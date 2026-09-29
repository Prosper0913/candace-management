<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = store_scope_id();

// Products available to pick manually (alternative to scanning).
$stmt = $pdo->prepare('SELECT id, barcode, name, price, stock_quantity FROM products WHERE user_id = ? ORDER BY name');
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();

$page_title = 'New Sale';
$active_nav = 'pos';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>New Sale</h1>
        <p>Scan each item's barcode and it's added automatically, or pick a product from the list. You can't sell more than what's in stock.</p>
    </div>
</div>

<div class="grid grid-2" style="align-items:start;">
    <div class="card">
        <div class="card-title">Scanner</div>
        <div class="form-grid">
            <div class="field full">
                <label for="barcode-input">Scan or type a barcode, then press Enter</label>
                <input type="text" id="barcode-input" autocomplete="off" placeholder="Ready to scan&hellip;" autofocus>
            </div>
        </div>
        <div id="pos-message" class="helper-text" style="min-height:18px;"></div>

        <div id="new-product-form" style="display:none; margin-top:16px; padding-top:16px; border-top:1px solid var(--line);">
            <div class="card-title" style="margin-bottom:10px;">New barcode &mdash; set its price and stock</div>
            <div class="form-grid">
                <div class="field">
                    <label>Barcode</label>
                    <input type="text" id="np-barcode" readonly style="font-family:var(--font-mono); background:var(--line-soft);">
                </div>
                <div class="field">
                    <label for="np-name">Product name</label>
                    <input type="text" id="np-name" placeholder="e.g. Coke 1.5L">
                </div>
                <div class="field">
                    <label for="np-price">Price (&#8369;)</label>
                    <input type="number" step="0.01" min="0.01" id="np-price">
                </div>
                <div class="field">
                    <label for="np-stock">How many in stock</label>
                    <input type="number" step="1" min="1" id="np-stock" placeholder="e.g. 12">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn" id="np-save">Save &amp; add to sale</button>
                <button type="button" class="btn-ghost btn" id="np-cancel">Cancel</button>
            </div>
        </div>

        <?php if ($products): ?>
        <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--line);">
            <div class="card-title" style="border-bottom:none; padding-bottom:0;">Or pick a product without scanning</div>
            <div class="form-grid">
                <div class="field">
                    <label for="manual-product">Product</label>
                    <select id="manual-product">
                        <option value="">Choose a product&hellip;</option>
                        <?php foreach ($products as $p): $out = (int) $p['stock_quantity'] <= 0; ?>
                            <option value="<?= (int) $p['id'] ?>"
                                    data-barcode="<?= h($p['barcode']) ?>"
                                    data-name="<?= h($p['name']) ?>"
                                    data-price="<?= h((string) $p['price']) ?>"
                                    data-stock="<?= (int) $p['stock_quantity'] ?>"
                                    <?= $out ? 'disabled' : '' ?>>
                                <?= h($p['name']) ?> &mdash; <?= peso((float) $p['price']) ?>
                                (<?= $out ? 'out of stock' : (int) $p['stock_quantity'] . ' in stock' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="align-self:end;">
                    <button type="button" class="btn-ghost btn" id="manual-add-btn" style="width:100%;">Add to sale</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">Current sale</div>
        <table id="cart-table">
            <thead>
                <tr><th>Item</th><th class="amount">Price</th><th>Qty</th><th class="amount">Subtotal</th><th></th></tr>
            </thead>
            <tbody id="cart-body">
                <tr id="cart-empty-row"><td colspan="5" class="empty-state">Cart is empty. Scan or pick an item to begin.</td></tr>
            </tbody>
        </table>

        <div class="form-actions" style="justify-content:space-between; align-items:center; margin-top:16px; flex-wrap:wrap; gap:12px;">
            <div style="font-family:var(--font-mono); font-size:22px; font-weight:600;">
                Total: <span id="cart-total">&#8369;0.00</span>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-ghost btn" id="cart-clear">Clear</button>
                <button type="button" class="btn" id="cart-checkout" disabled>Complete sale &amp; print receipt</button>
            </div>
        </div>

        <div class="form-grid" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--line);">
            <div class="field">
                <label for="cash-received">Money received from buyer (&#8369;)</label>
                <input type="number" step="0.01" min="0" id="cash-received" placeholder="0.00">
            </div>
            <div class="field">
                <label>Change</label>
                <input type="text" id="cart-change" readonly style="font-family:var(--font-mono); font-weight:600; background:var(--line-soft);" value="&#8369;0.00">
            </div>
        </div>
        <p class="helper-text" id="cash-warning"></p>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const PESO = amt => '\u20b1' + Number(amt).toFixed(2);

let cart = []; // { product_id, barcode, name, price, qty, stock }
let currentTotal = 0;

const barcodeInput = document.getElementById('barcode-input');
const posMessage = document.getElementById('pos-message');
const newProductForm = document.getElementById('new-product-form');
const npBarcode = document.getElementById('np-barcode');
const npName = document.getElementById('np-name');
const npPrice = document.getElementById('np-price');
const npStock = document.getElementById('np-stock');
const cartBody = document.getElementById('cart-body');
const cartEmptyRow = document.getElementById('cart-empty-row');
const cartTotalEl = document.getElementById('cart-total');
const checkoutBtn = document.getElementById('cart-checkout');
const cashReceivedInput = document.getElementById('cash-received');
const cartChangeEl = document.getElementById('cart-change');
const cashWarning = document.getElementById('cash-warning');

function showMessage(text, isError) {
    posMessage.textContent = text;
    posMessage.style.color = isError ? 'var(--negative)' : 'var(--muted)';
}

function focusScanner() {
    barcodeInput.value = '';
    barcodeInput.focus();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function recalcChange() {
    const received = parseFloat(cashReceivedInput.value) || 0;
    const change = received - currentTotal;

    if (cashReceivedInput.value === '') {
        cartChangeEl.value = PESO(0);
        cashWarning.textContent = '';
    } else if (change < 0) {
        cartChangeEl.value = PESO(0);
        cashWarning.textContent = `Not enough yet - still short ${PESO(Math.abs(change))}.`;
        cashWarning.style.color = 'var(--negative)';
    } else {
        cartChangeEl.value = PESO(change);
        cashWarning.textContent = '';
    }
}
cashReceivedInput.addEventListener('input', recalcChange);

function renderCart() {
    cartBody.querySelectorAll('tr.cart-row').forEach(r => r.remove());
    let total = 0;

    if (cart.length === 0) {
        cartEmptyRow.style.display = '';
    } else {
        cartEmptyRow.style.display = 'none';
        cart.forEach((item, idx) => {
            const lineTotal = item.price * item.qty;
            total += lineTotal;
            const atMax = item.qty >= item.stock;
            const tr = document.createElement('tr');
            tr.className = 'cart-row';
            tr.innerHTML = `
                <td>${escapeHtml(item.name)}<br><span class="helper-text" style="margin:0;">${escapeHtml(item.barcode || 'no barcode')} &middot; ${item.stock} in stock</span></td>
                <td class="amount">${PESO(item.price)}</td>
                <td>
                    <button type="button" class="icon-link qty-btn" data-idx="${idx}" data-delta="-1" style="background:none;border:1px solid var(--line);cursor:pointer;padding:2px 8px;">-</button>
                    <input type="number" min="1" max="${item.stock}" step="1" class="qty-input" data-idx="${idx}" value="${item.qty}" style="width:56px; text-align:center; padding:2px 4px;">
                    <button type="button" class="icon-link qty-btn" data-idx="${idx}" data-delta="1" ${atMax ? 'disabled' : ''} style="background:none;border:1px solid var(--line);cursor:${atMax ? 'not-allowed' : 'pointer'};padding:2px 8px;${atMax ? 'opacity:0.4;' : ''}">+</button>
                </td>
                <td class="amount">${PESO(lineTotal)}</td>
                <td class="actions"><button type="button" class="icon-link remove-btn" data-idx="${idx}" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Remove</button></td>
            `;
            cartBody.appendChild(tr);
        });
    }

    cartTotalEl.textContent = PESO(total);
    currentTotal = total;
    recalcChange();
    checkoutBtn.disabled = cart.length === 0;
}

// Adds one unit of a product to the sale. Refuses (and says why) if the
// product is out of stock, or if the sale already holds all the stock there
// is. Returns true only if something was actually added.
function addToCart(product) {
    const stock = Number(product.stock) || 0;

    if (stock <= 0) {
        showMessage(`${product.name} is out of stock and can't be added.`, true);
        return false;
    }

    const existing = cart.find(i => i.product_id === product.id);
    if (existing) {
        existing.stock = stock; // use the freshest stock figure we have
        if (existing.qty >= stock) {
            showMessage(`Only ${stock} of ${product.name} in stock - can't add more.`, true);
            renderCart();
            return false;
        }
        existing.qty += 1;
    } else {
        cart.push({ product_id: product.id, barcode: product.barcode, name: product.name, price: product.price, qty: 1, stock });
    }
    renderCart();
    showMessage(`Added: ${product.name}`, false);
    return true;
}

cartBody.addEventListener('click', (e) => {
    const qtyBtn = e.target.closest('.qty-btn');
    if (qtyBtn) {
        const idx = Number(qtyBtn.dataset.idx);
        const delta = Number(qtyBtn.dataset.delta);
        const item = cart[idx];
        if (delta > 0 && item.qty >= item.stock) {
            showMessage(`Only ${item.stock} of ${item.name} in stock.`, true);
            return;
        }
        item.qty = Math.max(1, item.qty + delta);
        renderCart();
        return;
    }
    const removeBtn = e.target.closest('.remove-btn');
    if (removeBtn) {
        cart.splice(Number(removeBtn.dataset.idx), 1);
        renderCart();
    }
});

// Typing a quantity directly. Uses "change" (fires on Tab/Enter/click-away)
// rather than "input", because re-drawing the cart on every keystroke would
// drop focus mid-number.
cartBody.addEventListener('change', (e) => {
    const qtyInput = e.target.closest('.qty-input');
    if (!qtyInput) return;
    const item = cart[Number(qtyInput.dataset.idx)];
    let value = parseInt(qtyInput.value, 10);
    if (isNaN(value) || value < 1) value = 1;
    if (value > item.stock) {
        value = item.stock;
        showMessage(`Only ${item.stock} of ${item.name} in stock - quantity set to ${item.stock}.`, true);
    }
    item.qty = value;
    renderCart();
});

document.getElementById('cart-clear').addEventListener('click', () => {
    if (cart.length && !confirm('Clear the current sale?')) return;
    cart = [];
    cashReceivedInput.value = '';
    renderCart();
    focusScanner();
});

// ---- Scanning: a found product is added to the sale automatically --------
barcodeInput.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const barcode = barcodeInput.value.trim();
    if (!barcode) return;

    fetch(`pos_api.php?action=lookup&barcode=${encodeURIComponent(barcode)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showMessage(data.error || 'Lookup failed.', true); focusScanner(); return; }
            if (data.found) {
                addToCart(data.product);
                focusScanner();
            } else {
                npBarcode.value = data.barcode;
                npName.value = '';
                npPrice.value = '';
                npStock.value = '';
                newProductForm.style.display = '';
                showMessage('New barcode. Set its name, price and stock below.', false);
                npName.focus();
            }
        })
        .catch(() => showMessage('Could not reach the server.', true));
});

document.getElementById('np-save').addEventListener('click', () => {
    const barcode = npBarcode.value;
    const name = npName.value.trim();
    const price = npPrice.value;
    const stock = parseInt(npStock.value, 10);

    if (!name || !price || Number(price) <= 0) {
        showMessage('Enter a valid name and price.', true);
        return;
    }
    if (isNaN(stock) || stock < 1) {
        showMessage('Enter how many are in stock (at least 1).', true);
        return;
    }

    fetch('pos_api.php?action=register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, barcode, name, price, stock_quantity: stock })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showMessage(data.error || 'Could not save product.', true); return; }
            newProductForm.style.display = 'none';
            addToCart(data.product);
            focusScanner();
        })
        .catch(() => showMessage('Could not reach the server.', true));
});

document.getElementById('np-cancel').addEventListener('click', () => {
    newProductForm.style.display = 'none';
    focusScanner();
});

// ---- Manual "pick a product" path - the alternative to scanning -----------
const manualProduct = document.getElementById('manual-product');
const manualAddBtn = document.getElementById('manual-add-btn');
if (manualAddBtn) {
    manualAddBtn.addEventListener('click', () => {
        const opt = manualProduct.selectedOptions[0];
        if (!opt || !opt.value) {
            showMessage('Choose a product first.', true);
            return;
        }
        addToCart({
            id: Number(opt.value),
            barcode: opt.dataset.barcode,
            name: opt.dataset.name,
            price: parseFloat(opt.dataset.price),
            stock: parseInt(opt.dataset.stock, 10),
        });
        manualProduct.value = '';
    });
}

// ---- Checkout -------------------------------------------------------------
checkoutBtn.addEventListener('click', () => {
    if (!cart.length) return;

    const cashReceived = parseFloat(cashReceivedInput.value);
    if (!cashReceivedInput.value || isNaN(cashReceived) || cashReceived < currentTotal) {
        showMessage('Enter how much cash the buyer handed over - it must cover the total.', true);
        cashReceivedInput.focus();
        return;
    }

    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Processing\u2026';

    fetch('pos_api.php?action=checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, cart, cash_received: cashReceived })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                // e.g. stock changed since this page loaded - the server has the final say.
                showMessage(data.error || 'Checkout failed.', true);
                checkoutBtn.disabled = false;
                checkoutBtn.textContent = 'Complete sale & print receipt';
                return;
            }
            cart = [];
            cashReceivedInput.value = '';
            renderCart();

            if (!data.printed) {
                // Sale is saved regardless - only the physical printout failed.
                alert('Sale saved, but the receipt did not print:\n\n' + (data.print_error || 'Unknown printer error') + '\n\nYou can reprint or view it on the next screen.');
            }
            window.location.href = `receipt.php?sale=${data.sale_id}`;
        })
        .catch(() => {
            showMessage('Could not reach the server.', true);
            checkoutBtn.disabled = false;
            checkoutBtn.textContent = 'Complete sale & print receipt';
        });
});

renderCart();
focusScanner();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>