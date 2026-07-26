<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'Scan Sale';
$active_nav = 'pos';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Scan Sale</h1>
        <p>Scan each item's barcode like a mall checkout. New barcodes ask for a name and price once, then are remembered forever.</p>
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
            <div class="card-title" style="margin-bottom:10px;">New barcode &mdash; set its price</div>
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
            </div>
            <div class="form-actions">
                <button type="button" class="btn" id="np-save">Save &amp; add to cart</button>
                <button type="button" class="btn-ghost btn" id="np-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Current sale</div>
        <table id="cart-table">
            <thead>
                <tr><th>Item</th><th class="amount">Price</th><th>Qty</th><th class="amount">Subtotal</th><th></th></tr>
            </thead>
            <tbody id="cart-body">
                <tr id="cart-empty-row"><td colspan="5" class="empty-state">Cart is empty. Scan an item to begin.</td></tr>
            </tbody>
        </table>
        <div class="form-actions" style="justify-content:space-between; align-items:center; margin-top:16px;">
            <div style="font-family:var(--font-mono); font-size:22px; font-weight:600;">
                Total: <span id="cart-total">&#8369;0.00</span>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn-ghost btn" id="cart-clear">Clear</button>
                <button type="button" class="btn" id="cart-checkout" disabled>Complete sale &amp; print receipt</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const PESO = amt => '\u20b1' + Number(amt).toFixed(2);

let cart = []; // { product_id, barcode, name, price, qty }

const barcodeInput = document.getElementById('barcode-input');
const posMessage = document.getElementById('pos-message');
const newProductForm = document.getElementById('new-product-form');
const npBarcode = document.getElementById('np-barcode');
const npName = document.getElementById('np-name');
const npPrice = document.getElementById('np-price');
const cartBody = document.getElementById('cart-body');
const cartEmptyRow = document.getElementById('cart-empty-row');
const cartTotalEl = document.getElementById('cart-total');
const checkoutBtn = document.getElementById('cart-checkout');

function showMessage(text, isError) {
    posMessage.textContent = text;
    posMessage.style.color = isError ? 'var(--negative)' : 'var(--muted)';
}

function focusScanner() {
    barcodeInput.value = '';
    barcodeInput.focus();
}

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
            const tr = document.createElement('tr');
            tr.className = 'cart-row';
            tr.innerHTML = `
                <td>${escapeHtml(item.name)}<br><span class="helper-text" style="margin:0;">${escapeHtml(item.barcode)}</span></td>
                <td class="amount">${PESO(item.price)}</td>
                <td>
                    <button type="button" class="icon-link qty-btn" data-idx="${idx}" data-delta="-1" style="background:none;border:1px solid var(--line);cursor:pointer;padding:2px 8px;">-</button>
                    <span style="display:inline-block;width:28px;text-align:center;">${item.qty}</span>
                    <button type="button" class="icon-link qty-btn" data-idx="${idx}" data-delta="1" style="background:none;border:1px solid var(--line);cursor:pointer;padding:2px 8px;">+</button>
                </td>
                <td class="amount">${PESO(lineTotal)}</td>
                <td class="actions"><button type="button" class="icon-link remove-btn" data-idx="${idx}" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Remove</button></td>
            `;
            cartBody.appendChild(tr);
        });
    }

    cartTotalEl.textContent = PESO(total);
    checkoutBtn.disabled = cart.length === 0;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function addToCart(product) {
    const existing = cart.find(i => i.barcode === product.barcode);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({ product_id: product.id, barcode: product.barcode, name: product.name, price: product.price, qty: 1 });
    }
    renderCart();
    showMessage(`Added: ${product.name}`, false);
}

cartBody.addEventListener('click', (e) => {
    const qtyBtn = e.target.closest('.qty-btn');
    if (qtyBtn) {
        const idx = Number(qtyBtn.dataset.idx);
        const delta = Number(qtyBtn.dataset.delta);
        cart[idx].qty = Math.max(1, cart[idx].qty + delta);
        renderCart();
        return;
    }
    const removeBtn = e.target.closest('.remove-btn');
    if (removeBtn) {
        const idx = Number(removeBtn.dataset.idx);
        cart.splice(idx, 1);
        renderCart();
    }
});

document.getElementById('cart-clear').addEventListener('click', () => {
    if (cart.length && !confirm('Clear the current sale?')) return;
    cart = [];
    renderCart();
    focusScanner();
});

barcodeInput.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const barcode = barcodeInput.value.trim();
    if (!barcode) return;

    fetch(`pos_api.php?action=lookup&barcode=${encodeURIComponent(barcode)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { showMessage(data.error || 'Lookup failed.', true); return; }
            if (data.found) {
                addToCart(data.product);
                focusScanner();
            } else {
                npBarcode.value = data.barcode;
                npName.value = '';
                npPrice.value = '';
                newProductForm.style.display = '';
                showMessage('New barcode. Set its name and price below.', false);
                npName.focus();
            }
        })
        .catch(() => showMessage('Could not reach the server.', true));
});

document.getElementById('np-save').addEventListener('click', () => {
    const barcode = npBarcode.value;
    const name = npName.value.trim();
    const price = npPrice.value;

    if (!name || !price || Number(price) <= 0) {
        showMessage('Enter a valid name and price.', true);
        return;
    }

    fetch('pos_api.php?action=register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, barcode, name, price })
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

checkoutBtn.addEventListener('click', () => {
    if (!cart.length) return;
    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Processing\u2026';

    fetch('pos_api.php?action=checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: CSRF_TOKEN, cart })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                showMessage(data.error || 'Checkout failed.', true);
                checkoutBtn.disabled = false;
                checkoutBtn.textContent = 'Complete sale & print receipt';
                return;
            }
            cart = [];
            renderCart();
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