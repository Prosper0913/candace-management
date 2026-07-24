<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: categories.php');
        exit;
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Expenses using this category simply become "Uncategorized" (ON DELETE SET NULL)
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Category deleted. Related expenses are now uncategorized.');
        header('Location: categories.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $errors[] = 'Please enter a category name.';
    } elseif (strlen($name) > 60) {
        $errors[] = 'Category name is too long (max 60 characters).';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
            $stmt->execute([$user_id, $name]);
            set_flash('success', 'Category added.');
            header('Location: categories.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'You already have a category with that name.';
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT c.id, c.name, COUNT(e.id) AS usage_count
     FROM categories c LEFT JOIN expenses e ON e.category_id = c.id
     WHERE c.user_id = ?
     GROUP BY c.id, c.name
     ORDER BY c.name'
);
$stmt->execute([$user_id]);
$categories = $stmt->fetchAll();

$page_title = 'Categories';
$active_nav = 'categories';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Record</p>
        <h1>Categories</h1>
        <p>Organize your expenses so reports can show exactly where money goes.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Add a category</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field full">
                <label for="name">Category name</label>
                <input type="text" id="name" name="name" placeholder="e.g. Supplies, Delivery fees" required maxlength="60">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Add category</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">Your categories</div>
    <?php if (!$categories): ?>
        <div class="empty-state">No categories yet.</div>
    <?php else: ?>
        <table>
            <thead><tr><th>Name</th><th>Expenses using it</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?= h($c['name']) ?></td>
                    <td><?= (int) $c['usage_count'] ?></td>
                    <td class="actions">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete category &quot;<?= h(addslashes($c['name'])) ?>&quot;? Expenses using it will become Uncategorized.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
