<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$errors = [];

// ---- Handle form submissions --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: income.php');
        exit;
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM income WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Sale entry deleted.');
        header('Location: income.php');
        exit;
    }

    $amount = $_POST['amount'] ?? '';
    $income_date = $_POST['income_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if (!is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Please enter a valid amount greater than zero.';
    if (!strtotime($income_date)) $errors[] = 'Please enter a valid date.';

    if ($action === 'update') {
        // Editing only ever touches amount/date/notes - the product and
        // quantity stay fixed, since changing them after the fact would mean
        // correctly reversing whatever stock adjustment already happened.
        // To fix a wrong product/quantity, delete this entry and add a new one.
        if (!$errors) {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE income SET amount = ?, income_date = ?, notes = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$amount, $income_date, $notes ?: null, $id, $user_id]);
            set_flash('success', 'Sale entry updated.');
            header('Location: income.php');
            exit;
        }
    } else {
        // Creating a sale: the product MUST be one of the owner's own
        // registered products - no free-typed "source" text allowed. This is
        // what keeps every sale traceable to real inventory.
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND user_id = ?');
        $stmt->execute([$product_id, $user_id]);
        $product = $stmt->fetch();

        if (!$product) $errors[] = 'Please choose a product from your inventory.';
        if ($quantity <= 0) $errors[] = 'Quantity must be at least 1.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO income (user_id, product_id, quantity, source, amount, income_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $user_id, $product['id'], $quantity, $product['name'],
                    $amount, $income_date, $notes ?: null,
                ]);

                // Keep stock in sync, same as a POS checkout would.
                $stmt = $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ? AND user_id = ?');
                $stmt->execute([$quantity, $product['id'], $user_id]);

                $pdo->commit();
                set_flash('success', 'Sale recorded and stock updated.');
                header('Location: income.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Could not save the sale. Please try again.';
            }
        }
    }
}

// ---- Load record for editing, if requested ------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM income WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $user_id]);
    $editing = $stmt->fetch() ?: null;
}

// ---- Products available to sell (for the dropdown) ----------------------
$stmt = $pdo->prepare('SELECT * FROM products WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();

// ---- List all income, most recent first ---------------------------------
$stmt = $pdo->prepare('SELECT * FROM income WHERE user_id = ? ORDER BY income_date DESC, id DESC');
$stmt->execute([$user_id]);
$income_list = $stmt->fetchAll();

$page_title = 'Sales';
$active_nav = 'income';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Record</p>
        <h1>Sales</h1>
        <p>Log every peso coming in so your balance always reflects reality.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title"><?= $editing ? 'Edit sale entry' : 'Add sale' ?></div>

    <?php if (!$editing && !$products): ?>
        <div class="empty-state">
            You don't have any products registered yet, so there's nothing to sell.
            <a href="products.php">Register a product</a> first, then come back here.
        </div>
    <?php else: ?>
        <form method="post" id="sale-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

            <div class="form-grid">
                <?php if ($editing): ?>
                    <!-- Product and quantity are locked once a sale is saved - see the
                         note above the form actions for why. -->
                    <div class="field">
                        <label>Product</label>
                        <input type="text" value="<?= h($editing['source']) ?>" readonly style="background:var(--line-soft);">
                    </div>
                    <?php if ($editing['quantity'] !== null): ?>
                        <div class="field">
                            <label>Quantity</label>
                            <input type="text" value="<?= (int) $editing['quantity'] ?>" readonly style="background:var(--line-soft);">
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="field">
                        <label for="product_id">Product</label>
                        <select id="product_id" name="product_id" required>
                            <option value="">Choose a product&hellip;</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" data-price="<?= h((string) $p['price']) ?>" data-stock="<?= (int) $p['stock_quantity'] ?>">
                                    <?= h($p['name']) ?> &mdash; <?= peso((float) $p['price']) ?> (<?= (int) $p['stock_quantity'] ?> in stock)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="quantity">Quantity sold</label>
                        <input type="number" step="1" min="1" id="quantity" name="quantity" value="1" required>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="amount">Amount (₱)</label>
                    <input type="number" step="0.01" min="0.01" id="amount" name="amount"
                           value="<?= h((string) ($editing['amount'] ?? '')) ?>" required>
                </div>
                <div class="field">
                    <label for="income_date">Date</label>
                    <input type="date" id="income_date" name="income_date"
                           value="<?= h($editing['income_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="field full">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" placeholder="Any extra detail worth remembering"><?= h($editing['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <?php if (!$editing): ?>
                <p class="helper-text" id="stock-warning"></p>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add sale' ?></button>
                <?php if ($editing): ?><a href="income.php" class="btn-ghost btn">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">All sales</div>
    <?php if (!$income_list): ?>
        <div class="empty-state">No sales recorded yet. Use the form above to add your first entry.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Date</th><th>Product</th><th>Qty</th><th>Notes</th><th class="amount">Amount</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($income_list as $row): ?>
                <tr>
                    <td><?= h(display_date($row['income_date'])) ?></td>
                    <td><?= h($row['source']) ?></td>
                    <td><?= $row['quantity'] !== null ? (int) $row['quantity'] : '—' ?></td>
                    <td><?= h($row['notes'] ?? '') ?></td>
                    <td class="amount" style="color:var(--positive);">+<?= peso((float) $row['amount']) ?></td>
                    <td class="actions">
                        <a class="icon-link" href="income.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                        &nbsp;
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this sale entry?');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if (!$editing && $products): ?>
<script>
const productSelect = document.getElementById('product_id');
const quantityInput = document.getElementById('quantity');
const amountInput = document.getElementById('amount');
const stockWarning = document.getElementById('stock-warning');

function recalcAmount() {
    const opt = productSelect.selectedOptions[0];
    if (!opt || !opt.value) return;
    const price = parseFloat(opt.dataset.price) || 0;
    const stock = parseInt(opt.dataset.stock, 10) || 0;
    const qty = parseInt(quantityInput.value, 10) || 0;

    amountInput.value = (price * qty).toFixed(2);

    if (qty > stock) {
        stockWarning.textContent = `Heads up: only ${stock} in stock, but you're logging ${qty}. Stock will floor at 0, not go negative.`;
        stockWarning.style.color = 'var(--negative)';
    } else {
        stockWarning.textContent = '';
    }
}

productSelect.addEventListener('change', recalcAmount);
quantityInput.addEventListener('input', recalcAmount);
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>