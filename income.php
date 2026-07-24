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

    $source = trim($_POST['source'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $income_date = $_POST['income_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if ($source === '') $errors[] = 'Please enter where this sale came from.';
    if (!is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Please enter a valid amount greater than zero.';
    if (!strtotime($income_date)) $errors[] = 'Please enter a valid date.';

    if (!$errors) {
        if ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'UPDATE income SET source = ?, amount = ?, income_date = ?, notes = ? WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([$source, $amount, $income_date, $notes ?: null, $id, $user_id]);
            set_flash('success', 'Sale entry updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO income (user_id, source, amount, income_date, notes) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$user_id, $source, $amount, $income_date, $notes ?: null]);
            set_flash('success', 'Sale recorded.');
        }
        header('Location: income.php');
        exit;
    }
}

// ---- Load record for editing, if requested ------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM income WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['edit'], $user_id]);
    $editing = $stmt->fetch() ?: null;
}

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
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label for="source">Source</label>
                <input type="text" id="source" name="source" placeholder="e.g. Store sales, Allowance, Salary"
                       value="<?= h($editing['source'] ?? '') ?>" required>
            </div>
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
        <div class="form-actions">
            <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Add sale' ?></button>
            <?php if ($editing): ?><a href="income.php" class="btn-ghost btn">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">All sales</div>
    <?php if (!$income_list): ?>
        <div class="empty-state">No sales recorded yet. Use the form above to add your first entry.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Date</th><th>Source</th><th>Notes</th><th class="amount">Amount</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($income_list as $row): ?>
                <tr>
                    <td><?= h(display_date($row['income_date'])) ?></td>
                    <td><?= h($row['source']) ?></td>
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
<?php include __DIR__ . '/includes/footer.php'; ?>
