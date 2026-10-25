<?php
require_once __DIR__ . '/includes/functions.php';
require_owner();

$user_id = store_scope_id();
$errors = [];

// ---- Handle form submissions --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: products.php');
        exit;
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Past sale line items keep their own copy of the name/price (see sale_items),
        // so deleting a product never changes historical receipts.
        $stmt = $pdo->prepare('DELETE FROM products WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Product removed.');
        header('Location: products.php');
        exit;
    }

    if ($action === 'add_stock') {
        $id = (int) ($_POST['id'] ?? 0);
        $qty = (int) ($_POST['add_quantity'] ?? 0);
        $note = trim($_POST['add_note'] ?? '');

        if ($qty <= 0) {
            set_flash('error', 'Enter a quantity greater than zero to add.');
            header('Location: products.php');
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        $product = $stmt->fetch();

        if ($product) {
            $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ? AND user_id = ?')
                ->execute([$qty, $id, $user_id]);
            log_stock_addition($pdo, $user_id, $id, $qty, 'manual', $note !== '' ? $note : null);
            set_flash('success', "Added {$qty} to {$product['name']}'s stock.");
        }
        header('Location: products.php');
        exit;
    }

    $barcode = trim($_POST['barcode'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $price = $_POST['price'] ?? '';
    $stock_quantity = $_POST['stock_quantity'] ?? '0';
    $low_stock_threshold = $_POST['low_stock_threshold'] ?? (string) DEFAULT_LOW_STOCK_THRESHOLD;
    $category = trim($_POST['category'] ?? '');

    if ($barcode === '') $errors[] = 'Please scan or type a barcode.';
    elseif (strlen($barcode) > 64) $errors[] = 'Barcode is too long.';
    if ($name === '') $errors[] = 'Please enter a product name.';
    if (!is_numeric($price) || (float) $price <= 0) $errors[] = 'Please enter a valid price greater than zero.';
    if (!is_numeric($stock_quantity) || (int) $stock_quantity < 0) $errors[] = 'Stock on hand must be zero or more.';
    if (!is_numeric($low_stock_threshold) || (int) $low_stock_threshold < 0) $errors[] = 'Low-stock warning level must be zero or more.';
    if (strlen($category) > 100) $errors[] = 'Category is too long (100 characters max).';

    if (!$errors) {
        try {
            if ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);

                $old = $pdo->prepare('SELECT stock_quantity FROM products WHERE id = ? AND user_id = ?');
                $old->execute([$id, $user_id]);
                $old_stock = $old->fetchColumn();

                $stmt = $pdo->prepare(
                    'UPDATE products SET barcode = ?, name = ?, category = ?, price = ?, stock_quantity = ?, low_stock_threshold = ? WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([$barcode, $name, $category !== '' ? $category : null, $price, (int) $stock_quantity, (int) $low_stock_threshold, $id, $user_id]);

                // Stock raised by editing counts as stock added - log the difference.
                if ($old_stock !== false && (int) $stock_quantity > (int) $old_stock) {
                    log_stock_addition($pdo, $user_id, $id, (int) $stock_quantity - (int) $old_stock, 'manual', 'Stock edited on the Products page');
                }
                set_flash('success', 'Product updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO products (user_id, barcode, name, category, price, stock_quantity, low_stock_threshold) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$user_id, $barcode, $name, $category !== '' ? $category : null, $price, (int) $stock_quantity, (int) $low_stock_threshold]);

                if ((int) $stock_quantity > 0) {
                    log_stock_addition($pdo, $user_id, (int) $pdo->lastInsertId(), (int) $stock_quantity, 'manual', 'Initial stock');
                }
                set_flash('success', 'Product registered to that barcode.');
            }
            header('Location: products.php');
            exit;
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation, i.e. the barcode already exists.
            $errors[] = $e->getCode() === '23000'
                ? 'You already have a product registered under that barcode.'
                : 'Could not save the product. If you just updated the app, make sure the database migrations have been run.';
        }
    }
}

// ---- Load record for editing, if requested ------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $user_id]);
    $editing = $stmt->fetch() ?: null;
}

// ---- List all products, with how many times each has sold --------------
$stmt = $pdo->prepare(
    'SELECT p.*, COALESCE(SUM(si.quantity), 0) AS units_sold
     FROM products p
     LEFT JOIN sale_items si ON si.product_id = p.id
     WHERE p.user_id = ?
     GROUP BY p.id
     ORDER BY p.name'
);
$stmt->execute([$user_id]);
$products = $stmt->fetchAll();

// ---- Categories already in use (for the form suggestions and the filter) ----
$stmt = $pdo->prepare(
    "SELECT DISTINCT category FROM products
     WHERE user_id = ? AND category IS NOT NULL AND category <> ''
     ORDER BY category"
);
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Products';
$active_nav = 'products';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Products</h1>
        <p>Every barcode the scanner remembers, and the price it charges when scanned.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="stock_history.php" class="btn-ghost btn" style="text-decoration:none;">Stock History</a>
        <a href="pos.php" class="btn" style="text-decoration:none;">Go to Scan Sale</a>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title"><?= $editing ? 'Edit product' : 'Register a barcode' ?></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label for="barcode">Barcode</label>
                <input type="text" id="barcode" name="barcode" placeholder="Scan it, or type it in"
                       value="<?= h($editing['barcode'] ?? '') ?>" autofocus required>
            </div>
            <div class="field">
                <label for="name">Product name</label>
                <input type="text" id="name" name="name" placeholder="e.g. Coke 1.5L"
                       value="<?= h($editing['name'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="category">Category</label>
                <input type="text" id="category" name="category" list="category-list"
                       placeholder="e.g. Beverages (pick or type a new one)" maxlength="100"
                       value="<?= h($editing['category'] ?? '') ?>">
                <datalist id="category-list">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= h($cat) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="field">
                <label for="price">Price (₱)</label>
                <input type="number" step="0.01" min="0.01" id="price" name="price"
                       value="<?= h((string) ($editing['price'] ?? '')) ?>" required>
            </div>
            <div class="field">
                <label for="stock_quantity">Stock on hand</label>
                <input type="number" step="1" min="0" id="stock_quantity" name="stock_quantity"
                       value="<?= h((string) ($editing['stock_quantity'] ?? '0')) ?>" required>
            </div>
            <div class="field">
                <label for="low_stock_threshold">Warn me when stock falls to</label>
                <input type="number" step="1" min="0" id="low_stock_threshold" name="low_stock_threshold"
                       value="<?= h((string) ($editing['low_stock_threshold'] ?? DEFAULT_LOW_STOCK_THRESHOLD)) ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Register product' ?></button>
            <?php if ($editing): ?><a href="products.php" class="btn-ghost btn">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span>All products</span>
        <?php if ($products): ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <select id="category-filter"
                    style="padding:7px 10px; border:1px solid var(--line); border-radius:var(--radius); font-size:14px;">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h(strtolower($cat)) ?>"><?= h($cat) ?></option>
                <?php endforeach; ?>
                <option value="__none__">Uncategorized</option>
            </select>
            <input type="text" id="product-search" placeholder="Search by name or barcode&hellip;" autocomplete="off"
                   style="max-width:260px; padding:7px 10px; border:1px solid var(--line); border-radius:var(--radius); font-size:14px;">
        </div>
        <?php endif; ?>
    </div>
    <?php if (!$products): ?>
        <div class="empty-state">No products registered yet. Scan an item's barcode above, or register one from the Scan Sale page the first time you ring it up.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Barcode</th><th>Name</th><th>Category</th><th class="amount">Price</th><th>Stock</th><th>Units sold</th><th></th></tr>
            </thead>
            <tbody id="product-rows">
            <?php foreach ($products as $row):
                $stock = (int) $row['stock_quantity'];
                $none = $stock === 0; // Out of stock: show "None", never "Low".
                $low = !$none && $stock <= (int) $row['low_stock_threshold']; // "Low" only above zero, at/below the user's setting.
                $has_category = ($row['category'] ?? '') !== '';
            ?>
                <tr data-search="<?= h(strtolower($row['name'] . ' ' . $row['barcode'])) ?>"
                    data-category="<?= h($has_category ? strtolower($row['category']) : '__none__') ?>">
                    <td style="font-family:var(--font-mono);"><?= h($row['barcode']) ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td><?= $has_category ? h($row['category']) : '<span style="color:#999;">&mdash;</span>' ?></td>
                    <td class="amount"><?= peso((float) $row['price']) ?></td>
                    <td>
                        <?php if ($none): ?>
                            <span class="status-pill cancelled" style="text-decoration:none; color:var(--negative); border-color:var(--negative);">None</span>
                        <?php else: ?>
                            <?= $stock ?>
                            <?php if ($low): ?><span class="status-pill cancelled" style="text-decoration:none; color:var(--negative); border-color:var(--negative);">Low</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $row['units_sold'] ?></td>
                    <td class="actions">
                        <a class="icon-link" href="products.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                        &nbsp;
                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove this product? Past sales keep their own record either way.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Delete</button>
                        </form>
                        <br>
                        <form method="post" style="display:flex; gap:4px; justify-content:flex-end; margin-top:6px;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="add_stock">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="number" name="add_quantity" min="1" step="1" placeholder="Qty" required style="width:60px; padding:4px 6px; font-size:12px;">
                            <button type="submit" class="icon-link" style="background:none;border:1px solid var(--line);cursor:pointer;padding:3px 8px;font-size:12px;white-space:nowrap;">+ Add stock</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr id="product-search-empty" style="display:none;"><td colspan="7" class="empty-state">No products match that search.</td></tr>
            </tbody>
        </table>
        <script>
            const productSearch = document.getElementById('product-search');
            const categoryFilter = document.getElementById('category-filter');
            const productRows = document.querySelectorAll('#product-rows tr[data-search]');
            const searchEmptyRow = document.getElementById('product-search-empty');

            function applyFilters() {
                const query = productSearch.value.trim().toLowerCase();
                const category = categoryFilter.value;
                let visibleCount = 0;
                productRows.forEach(row => {
                    const matchesText = row.dataset.search.includes(query);
                    const matchesCategory = category === '' || row.dataset.category === category;
                    const show = matchesText && matchesCategory;
                    row.style.display = show ? '' : 'none';
                    if (show) visibleCount++;
                });
                searchEmptyRow.style.display = visibleCount === 0 ? '' : 'none';
            }

            productSearch.addEventListener('input', applyFilters);
            categoryFilter.addEventListener('change', applyFilters);
        </script>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>