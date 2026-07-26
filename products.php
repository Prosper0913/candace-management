<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
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

    $barcode = trim($_POST['barcode'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $price = $_POST['price'] ?? '';

    if ($barcode === '') $errors[] = 'Please scan or type a barcode.';
    elseif (strlen($barcode) > 64) $errors[] = 'Barcode is too long.';
    if ($name === '') $errors[] = 'Please enter a product name.';
    if (!is_numeric($price) || (float) $price <= 0) $errors[] = 'Please enter a valid price greater than zero.';

    if (!$errors) {
        try {
            if ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare(
                    'UPDATE products SET barcode = ?, name = ?, price = ? WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([$barcode, $name, $price, $id, $user_id]);
                set_flash('success', 'Product updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO products (user_id, barcode, name, price) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$user_id, $barcode, $name, $price]);
                set_flash('success', 'Product registered to that barcode.');
            }
            header('Location: products.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'You already have a product registered under that barcode.';
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
    <div>
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
                <label for="price">Price (₱)</label>
                <input type="number" step="0.01" min="0.01" id="price" name="price"
                       value="<?= h((string) ($editing['price'] ?? '')) ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Register product' ?></button>
            <?php if ($editing): ?><a href="products.php" class="btn-ghost btn">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">All products</div>
    <?php if (!$products): ?>
        <div class="empty-state">No products registered yet. Scan an item's barcode above, or register one from the Scan Sale page the first time you ring it up.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Barcode</th><th>Name</th><th class="amount">Price</th><th>Units sold</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($products as $row): ?>
                <tr>
                    <td style="font-family:var(--font-mono);"><?= h($row['barcode']) ?></td>
                    <td><?= h($row['name']) ?></td>
                    <td class="amount"><?= peso((float) $row['price']) ?></td>
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
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>