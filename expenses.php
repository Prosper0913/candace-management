<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$errors = [];

$catStmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id = ? ORDER BY name');
$catStmt->execute([$user_id]);
$categories = $catStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: expenses.php');
        exit;
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Expense entry deleted.');
        header('Location: expenses.php');
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $expense_date = $_POST['expense_date'] ?? '';
    $category_id = $_POST['category_id'] !== '' ? (int) $_POST['category_id'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($title === '') $errors[] = 'Please enter what this expense was for.';
    if (!is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Please enter a valid amount greater than zero.';
    if (!strtotime($expense_date)) $errors[] = 'Please enter a valid date.';

    // Make sure category belongs to this user (or is null)
    if ($category_id !== null) {
        $valid = false;
        foreach ($categories as $c) if ((int) $c['id'] === $category_id) $valid = true;
        if (!$valid) $category_id = null;
    }

    if (!$errors) {
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE expenses SET title = ?, amount = ?, expense_date = ?, category_id = ?, notes = ? WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$title, $amount, $expense_date, $category_id, $notes ?: null, $id, $user_id]);
            set_flash('success', 'Expense entry updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO expenses (user_id, category_id, title, amount, expense_date, notes) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$user_id, $category_id, $title, $amount, $expense_date, $notes ?: null]);
            set_flash('success', 'Expense recorded.');
        }
        header('Location: expenses.php');
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $user_id]);
    $editing = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare(
    'SELECT e.*, c.name AS category_name
     FROM expenses e LEFT JOIN categories c ON c.id = e.category_id
     WHERE e.user_id = ? ORDER BY e.expense_date DESC, e.id DESC'
);
$stmt->execute([$user_id]);
$expense_list = $stmt->fetchAll();

$page_title = 'Expenses';
$active_nav = 'expenses';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Record</p>
        <h1>Expenses</h1>
        <p>Log every peso going out and tag it with a category to see where money is spent.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if (!$categories): ?>
    <div class="flash error">You don't have any categories yet. <a href="categories.php">Create one first</a> so expenses can be organized.</div>
<?php endif; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title"><?= $editing ? 'Edit expense entry' : 'Add expense' ?></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label for="title">What was it for</label>
                <input type="text" id="title" name="title" placeholder="e.g. Groceries, Jeepney fare, Internet bill"
                       value="<?= h($editing['title'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="amount">Amount (₱)</label>
                <input type="number" step="0.01" min="0.01" id="amount" name="amount"
                       value="<?= h((string) ($editing['amount'] ?? '')) ?>" required>
            </div>
            <div class="field">
                <label for="expense_date">Date</label>
                <input type="date" id="expense_date" name="expense_date"
                       value="<?= h($editing['expense_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id">
                    <option value="">Uncategorized</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= isset($editing['category_id']) && (int) $editing['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field full">
                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" placeholder="Any extra detail worth remembering"><?= h($editing['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add expense' ?></button>
            <?php if ($editing): ?><a href="expenses.php" class="btn-ghost btn">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">All expenses</div>
    <?php if (!$expense_list): ?>
        <div class="empty-state">No expenses recorded yet. Use the form above to add your first entry.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Date</th><th>Description</th><th>Category</th><th class="amount">Amount</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($expense_list as $row): ?>
                <tr>
                    <td><?= h(display_date($row['expense_date'])) ?></td>
                    <td><?= h($row['title']) ?><?php if ($row['notes']): ?><br><span class="helper-text"><?= h($row['notes']) ?></span><?php endif; ?></td>
                    <td><span class="tag"><?= h($row['category_name'] ?? 'Uncategorized') ?></span></td>
                    <td class="amount" style="color:var(--negative);">-<?= peso((float) $row['amount']) ?></td>
                    <td class="actions">
                        <a class="icon-link" href="expenses.php?edit=<?= (int) $row['id'] ?>">Edit</a>
                        &nbsp;
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this expense entry?');">
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
