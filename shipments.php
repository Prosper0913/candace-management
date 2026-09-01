<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/geocoding.php';
require_login();

$user_id = current_user_id();
$errors = [];

// ---- Handle form submissions --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: shipments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ---- Create a new expected shipment ----------------------------------
    if ($action === 'create') {
        $supplier = trim($_POST['supplier'] ?? '');
        $supplier_address = trim($_POST['supplier_address'] ?? '');
        $lat_hint = $_POST['supplier_lat_hint'] ?? '';
        $lng_hint = $_POST['supplier_lng_hint'] ?? '';
        $expected_date = trim($_POST['expected_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $names = $_POST['item_name'] ?? [];
        $barcodes = $_POST['item_barcode'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $costs = $_POST['item_cost'] ?? [];

        if ($expected_date === '' || !strtotime($expected_date)) {
            $errors[] = 'Please choose a valid expected delivery date.';
        }

        $clean_items = [];
        foreach ($names as $i => $name) {
            $name = trim($name);
            $qty = (int) ($qtys[$i] ?? 0);
            $cost = $costs[$i] ?? '';
            $barcode = trim($barcodes[$i] ?? '');
            if ($name === '' && $qty <= 0 && $barcode === '') {
                continue; // an entirely blank row, e.g. a row added then left empty
            }
            if ($name === '') { $errors[] = 'Every item needs a name.'; continue; }
            if ($qty <= 0) { $errors[] = "Quantity for \"{$name}\" must be at least 1."; continue; }
            if (!is_numeric($cost) || (float) $cost <= 0) { $errors[] = "Unit cost for \"{$name}\" must be greater than zero."; continue; }

            $product_id = null;
            if ($barcode !== '') {
                $stmt = $pdo->prepare('SELECT id FROM products WHERE user_id = ? AND barcode = ?');
                $stmt->execute([$user_id, $barcode]);
                $product_id = $stmt->fetchColumn() ?: null;
            }

            $clean_items[] = [
                'product_id' => $product_id,
                'barcode' => $barcode !== '' ? $barcode : null,
                'name' => $name,
                'qty' => $qty,
                'cost' => (float) $cost,
                'line_total' => round($qty * (float) $cost, 2),
            ];
        }

        if (!$clean_items && !$errors) {
            $errors[] = 'Add at least one item to the shipment.';
        }

        if (!$errors) {
            // If the owner picked a suggestion from the address search box,
            // we already know Nominatim resolved it - use those coordinates
            // directly instead of re-searching (faster, and guaranteed to
            // "pin" since it's a place Nominatim already confirmed exists).
            // Only fall back to a fresh geocode attempt if they typed an
            // address without picking a suggestion.
            $lat = $lng = null;
            $geocode_status = 'pending';
            $route_json = null;

            if (is_numeric($lat_hint) && is_numeric($lng_hint)) {
                $lat = (float) $lat_hint;
                $lng = (float) $lng_hint;
                $geocode_status = 'ok';
            } elseif ($supplier_address !== '') {
                $point = geocode_address($supplier_address);
                if ($point) {
                    [$lat, $lng] = $point;
                    $geocode_status = 'ok';
                } else {
                    $geocode_status = 'failed';
                }
            }

            if ($lat !== null && $lng !== null) {
                $route = get_route_points($lat, $lng, STORE_LAT, STORE_LNG);
                if ($route) {
                    $route_json = json_encode($route);
                }
            }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO shipments
                        (user_id, supplier, supplier_address, supplier_lat, supplier_lng, geocode_status, route_geojson, expected_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $user_id, $supplier !== '' ? $supplier : null,
                    $supplier_address !== '' ? $supplier_address : null,
                    $lat, $lng, $geocode_status, $route_json,
                    $expected_date, $notes !== '' ? $notes : null,
                ]);
                $shipment_id = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    'INSERT INTO shipment_items (shipment_id, product_id, barcode, name, quantity, unit_cost, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($clean_items as $item) {
                    $stmt->execute([
                        $shipment_id, $item['product_id'], $item['barcode'], $item['name'],
                        $item['qty'], $item['cost'], $item['line_total'],
                    ]);
                }
                $pdo->commit();

                if ($supplier_address !== '' && $geocode_status === 'failed') {
                    set_flash('success', "Shipment scheduled. Couldn't pin \"{$supplier_address}\" on the map - try picking a suggestion from the address search next time, or edit it later from this page.");
                } else {
                    set_flash('success', 'Shipment scheduled.');
                }
                header('Location: shipments.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Could not save the shipment. Please try again.';
            }
        }
    }

    // ---- Mark a shipment as received --------------------------------------
    if ($action === 'receive') {
        $id = (int) ($_POST['id'] ?? 0);
        $log_expense = isset($_POST['log_expense']);

        $stmt = $pdo->prepare(
            'SELECT s.*, COALESCE(SUM(si.line_total), 0) AS total_cost, COUNT(si.id) AS item_count
             FROM shipments s LEFT JOIN shipment_items si ON si.shipment_id = s.id
             WHERE s.id = ? AND s.user_id = ? AND s.status = "pending"
             GROUP BY s.id'
        );
        $stmt->execute([$id, $user_id]);
        $shipment = $stmt->fetch();

        if ($shipment) {
            try {
                $pdo->beginTransaction();
                $expense_id = null;

                if ($log_expense && (float) $shipment['total_cost'] > 0) {
                    $title = 'Shipment' . ($shipment['supplier'] ? ' from ' . $shipment['supplier'] : '');
                    $stmt = $pdo->prepare(
                        'INSERT INTO expenses (user_id, category_id, title, amount, expense_date, notes)
                         VALUES (?, NULL, ?, ?, CURDATE(), ?)'
                    );
                    $stmt->execute([
                        $user_id, $title, $shipment['total_cost'],
                        $shipment['item_count'] . ' item(s) received',
                    ]);
                    $expense_id = (int) $pdo->lastInsertId();
                }

                // Restock: add each item's quantity onto its linked product,
                // so the Products/Notifications pages reflect the delivery.
                $stmt = $pdo->prepare('SELECT product_id, quantity FROM shipment_items WHERE shipment_id = ? AND product_id IS NOT NULL');
                $stmt->execute([$id]);
                $restock_stmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND user_id = ?');
                foreach ($stmt->fetchAll() as $line) {
                    $restock_stmt->execute([$line['quantity'], $line['product_id'], $user_id]);
                }

                $stmt = $pdo->prepare('UPDATE shipments SET status = "received", expense_id = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([$expense_id, $id, $user_id]);

                $pdo->commit();
                set_flash('success', $log_expense ? 'Shipment received, stock updated, and logged as an expense.' : 'Shipment received and stock updated.');
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('error', 'Could not update the shipment.');
            }
        }
        header('Location: shipments.php');
        exit;
    }

    // ---- Cancel a shipment --------------------------------------------------
    if ($action === 'cancel') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE shipments SET status = "cancelled" WHERE id = ? AND user_id = ? AND status = "pending"');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Shipment cancelled.');
        header('Location: shipments.php');
        exit;
    }

    // ---- Delete a shipment ---------------------------------------------------
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM shipments WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Shipment removed.');
        header('Location: shipments.php');
        exit;
    }
}

// ---- List all shipments ---------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT s.*, COALESCE(SUM(si.line_total), 0) AS total_cost, COUNT(si.id) AS item_count
     FROM shipments s
     LEFT JOIN shipment_items si ON si.shipment_id = s.id
     WHERE s.user_id = ?
     GROUP BY s.id
     ORDER BY (s.status = "pending") DESC, s.expected_date ASC'
);
$stmt->execute([$user_id]);
$shipments = $stmt->fetchAll();

$items_by_shipment = [];
if ($shipments) {
    $ids = array_column($shipments, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id IN ($placeholders) ORDER BY id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $item) {
        $items_by_shipment[$item['shipment_id']][] = $item;
    }
}

$page_title = 'Shipments';
$active_nav = 'shipments';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Upcoming Shipments</h1>
        <p>Track what's on order, how much it costs, and when it's due. Type a supplier address and click Search to pin it on the delivery map.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Schedule a shipment</div>
    <form method="post" id="shipment-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-grid">
            <div class="field">
                <label for="supplier">Supplier (optional)</label>
                <input type="text" id="supplier" name="supplier" placeholder="e.g. Coca-Cola FEMSA">
            </div>
            <div class="field">
                <label for="expected_date">Expected delivery date</label>
                <input type="date" id="expected_date" name="expected_date" required>
            </div>
            <div class="field full">
                <label for="supplier_address">Supplier address (optional - needed for the delivery map)</label>
                <div style="display:flex; gap:8px; align-items:start;">
                    <input type="text" id="supplier_address" name="supplier_address" placeholder="Type the full address, then click Search" autocomplete="off" style="flex:1;">
                    <button type="button" id="address-search-btn" class="btn-ghost btn btn-small" style="width:auto; white-space:nowrap;">Search</button>
                </div>
                <input type="hidden" id="supplier_lat_hint" name="supplier_lat_hint">
                <input type="hidden" id="supplier_lng_hint" name="supplier_lng_hint">
                <div id="address-suggestions" class="address-suggestions" style="display:none;"></div>
                <p class="helper-text" id="address-status"></p>
            </div>
            <div class="field full">
                <label for="notes">Notes (optional)</label>
                <input type="text" id="notes" name="notes" placeholder="e.g. Supplier confirmed via text">
            </div>
        </div>

        <div style="margin-top:18px; padding-top:16px; border-top:1px solid var(--line);">
            <div class="card-title" style="border-bottom:none; padding-bottom:0;">Items on this shipment</div>
            <div id="item-rows">
                <div class="item-row">
                    <div class="field"><label>Barcode (optional)</label><input type="text" name="item_barcode[]" class="row-barcode" placeholder="Scan or type"></div>
                    <div class="field"><label>Product name</label><input type="text" name="item_name[]" class="row-name" required></div>
                    <div class="field"><label>Qty</label><input type="number" min="1" name="item_qty[]" class="row-qty" required></div>
                    <div class="field"><label>Unit cost (&#8369;)</label><input type="number" step="0.01" min="0.01" name="item_cost[]" class="row-cost" required></div>
                    <div class="field"><label>Line total</label><input type="text" class="row-total" readonly tabindex="-1" value="&#8369;0.00"></div>
                    <button type="button" class="icon-link remove-row-btn" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0 0 10px;">Remove</button>
                </div>
            </div>
            <button type="button" class="btn-ghost btn btn-small" id="add-row-btn" style="width:auto;">+ Add another item</button>
        </div>

        <div class="form-actions" style="justify-content:space-between;">
            <div style="font-family:var(--font-mono); font-size:16px;">Shipment total: <strong id="grand-total">&#8369;0.00</strong></div>
            <button type="submit" class="btn">Schedule shipment</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">All shipments</div>
    <?php if (!$shipments): ?>
        <div class="empty-state">No shipments scheduled yet. Add one above when you place an order with a supplier.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Supplier</th><th>Expected</th><th>Items</th><th class="amount">Total cost</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($shipments as $s): $days_left = (int) round((strtotime($s['expected_date']) - strtotime(date('Y-m-d'))) / 86400); ?>
                <tr>
                    <td>
                        <?= h($s['supplier'] ?: '—') ?>
                        <?php if ($s['notes']): ?><br><span class="helper-text"><?= h($s['notes']) ?></span><?php endif; ?>
                        <?php if ($s['geocode_status'] === 'failed'): ?><br><span class="helper-text" style="color:var(--negative);">Address not found for the map</span><?php endif; ?>
                    </td>
                    <td>
                        <?= h(display_date($s['expected_date'])) ?>
                        <?php if ($s['status'] === 'pending'): ?><br><span class="helper-text"><?= h(shipment_due_label($days_left)) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?= (int) $s['item_count'] ?> item(s)
                        <?php if (!empty($items_by_shipment[$s['id']])): ?>
                            <br><span class="helper-text"><?= h(implode(', ', array_map(fn($i) => $i['name'] . ' ×' . $i['quantity'], $items_by_shipment[$s['id']]))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="amount"><?= peso((float) $s['total_cost']) ?></td>
                    <td><span class="status-pill <?= h($s['status']) ?>"><?= h(ucfirst($s['status'])) ?></span></td>
                    <td class="actions">
                        <?php if ($s['status'] === 'pending' && $s['supplier_lat'] !== null): ?>
                            <a class="icon-link" href="shipment_map.php?id=<?= (int) $s['id'] ?>">View map</a><br>
                        <?php endif; ?>
                        <?php if ($s['status'] === 'pending'): ?>
                            <form method="post" style="display:inline-block; text-align:right;" onsubmit="return confirm('Mark this shipment as received?');">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="receive">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <label class="helper-text" style="display:block; margin-bottom:4px;">
                                    <input type="checkbox" name="log_expense" checked style="vertical-align:middle;"> Log cost as expense
                                </label>
                                <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--positive);padding:0;">Mark received</button>
                            </form>
                            <br>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this shipment?');">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--ink-soft);padding:0;">Cancel</button>
                            </form>
                        <?php endif; ?>
                        <br>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this shipment record permanently?');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
const PESO = amt => '\u20b1' + Number(amt).toFixed(2);
const itemRows = document.getElementById('item-rows');
const grandTotalEl = document.getElementById('grand-total');

function rowTemplate() {
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <div class="field"><label>Barcode (optional)</label><input type="text" name="item_barcode[]" class="row-barcode" placeholder="Scan or type"></div>
        <div class="field"><label>Product name</label><input type="text" name="item_name[]" class="row-name" required></div>
        <div class="field"><label>Qty</label><input type="number" min="1" name="item_qty[]" class="row-qty" required></div>
        <div class="field"><label>Unit cost (\u20b1)</label><input type="number" step="0.01" min="0.01" name="item_cost[]" class="row-cost" required></div>
        <div class="field"><label>Line total</label><input type="text" class="row-total" readonly tabindex="-1" value="\u20b10.00"></div>
        <button type="button" class="icon-link remove-row-btn" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0 0 10px;">Remove</button>
    `;
    return row;
}

function recalcRow(row) {
    const qty = parseFloat(row.querySelector('.row-qty').value) || 0;
    const cost = parseFloat(row.querySelector('.row-cost').value) || 0;
    row.querySelector('.row-total').value = PESO(qty * cost);
    recalcGrandTotal();
}

function recalcGrandTotal() {
    let total = 0;
    itemRows.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.row-qty').value) || 0;
        const cost = parseFloat(row.querySelector('.row-cost').value) || 0;
        total += qty * cost;
    });
    grandTotalEl.textContent = PESO(total);
}

// ---- Supplier address search (Nominatim's usage policy explicitly bans
// autocomplete/search-as-you-type - see https://operations.osmfoundation.org/policies/nominatim/ -
// so this only searches when the owner clicks "Search", never automatically
// while typing. One request per deliberate click.) ----
const addressInput = document.getElementById('supplier_address');
const searchBtn = document.getElementById('address-search-btn');
const latHint = document.getElementById('supplier_lat_hint');
const lngHint = document.getElementById('supplier_lng_hint');
const suggestionsBox = document.getElementById('address-suggestions');
const addressStatus = document.getElementById('address-status');

function clearAddressHint() {
    latHint.value = '';
    lngHint.value = '';
    addressStatus.textContent = '';
    addressStatus.style.color = '';
}

function showSuggestions(results) {
    suggestionsBox.innerHTML = '';
    if (!results.length) {
        suggestionsBox.style.display = 'none';
        return;
    }
    results.forEach(place => {
        const item = document.createElement('div');
        item.className = 'address-suggestion-item';
        item.textContent = place.label;
        item.addEventListener('click', () => {
            addressInput.value = place.label;
            latHint.value = place.lat;
            lngHint.value = place.lng;
            suggestionsBox.style.display = 'none';
            addressStatus.textContent = '\u2713 Pinned - this address will show on the delivery map';
            addressStatus.style.color = 'var(--positive)';
        });
        suggestionsBox.appendChild(item);
    });
    suggestionsBox.style.display = 'block';
}

// Editing the text after picking a suggestion invalidates it, since the
// text no longer necessarily matches those coordinates.
addressInput.addEventListener('input', () => {
    clearAddressHint();
    suggestionsBox.style.display = 'none';
});

searchBtn.addEventListener('click', () => {
    const query = addressInput.value.trim();
    if (query.length < 3) {
        addressStatus.textContent = 'Type at least 3 characters first.';
        addressStatus.style.color = 'var(--muted)';
        return;
    }

    searchBtn.disabled = true;
    searchBtn.textContent = 'Searching\u2026';
    addressStatus.textContent = '';

    fetch(`geocode_api.php?action=search&q=${encodeURIComponent(query)}`)
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                showSuggestions(data.results);
                if (data.results.length === 0) {
                    addressStatus.textContent = 'No matching places found - you can still save this as typed, but it may not pin on the map.';
                    addressStatus.style.color = 'var(--muted)';
                }
            } else {
                addressStatus.textContent = data.error || 'Address search failed.';
                addressStatus.style.color = 'var(--negative)';
            }
        })
        .catch(() => {
            addressStatus.textContent = "Couldn't reach the address search service (needs internet) - you can still type an address manually.";
            addressStatus.style.color = 'var(--muted)';
        })
        .finally(() => {
            // A short cooldown keeps even rapid double-clicking well under
            // Nominatim's 1-request-per-second limit.
            setTimeout(() => {
                searchBtn.disabled = false;
                searchBtn.textContent = 'Search';
            }, 1200);
        });
});

// Hide suggestions when clicking elsewhere on the page.
document.addEventListener('click', (e) => {
    if (e.target !== addressInput && e.target !== searchBtn && !suggestionsBox.contains(e.target)) {
        suggestionsBox.style.display = 'none';
    }
});

document.getElementById('add-row-btn').addEventListener('click', () => {
    itemRows.appendChild(rowTemplate());
});

itemRows.addEventListener('click', (e) => {
    if (e.target.closest('.remove-row-btn')) {
        const rows = itemRows.querySelectorAll('.item-row');
        if (rows.length > 1) {
            e.target.closest('.item-row').remove();
            recalcGrandTotal();
        }
    }
});

itemRows.addEventListener('input', (e) => {
    if (e.target.classList.contains('row-qty') || e.target.classList.contains('row-cost')) {
        recalcRow(e.target.closest('.item-row'));
    }
});

itemRows.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.classList.contains('row-barcode')) {
        e.preventDefault();
        const barcode = e.target.value.trim();
        if (!barcode) return;
        fetch(`pos_api.php?action=lookup&barcode=${encodeURIComponent(barcode)}`)
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.found) {
                    const row = e.target.closest('.item-row');
                    row.querySelector('.row-name').value = data.product.name;
                    row.querySelector('.row-cost').focus();
                }
            })
            .catch(() => {});
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>