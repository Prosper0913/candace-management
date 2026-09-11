<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$errors = [];

// ---- Handle edits/deletes -------------------------------------------------
// Creating a new sale no longer happens here - see pos.php, which handles
// both scanning and manually picking a product through the same cart/checkout
// flow, so every sale (however it was rung up) is recorded the same way.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: income.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM income WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user_id]);
        set_flash('success', 'Sale entry deleted.');
        header('Location: income.php');
        exit;
    }

    if ($action === 'update') {
        // Editing only ever touches amount/date/notes - the product and
        // quantity stay fixed, since changing them after the fact would mean
        // correctly reversing whatever stock adjustment already happened.
        // To fix a wrong product/quantity, delete this entry and ring up a
        // new sale from the New Sale page instead.
        $amount = $_POST['amount'] ?? '';
        $income_date = $_POST['income_date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        if (!is_numeric($amount) || (float) $amount <= 0) $errors[] = 'Please enter a valid amount greater than zero.';
        if (!strtotime($income_date)) $errors[] = 'Please enter a valid date.';

        if (!$errors) {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE income SET amount = ?, income_date = ?, notes = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$amount, $income_date, $notes ?: null, $id, $user_id]);
            set_flash('success', 'Sale entry updated.');
            header('Location: income.php');
            exit;
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
        <p>Your full sales history. To ring up a new sale, head to New Sale.</p>
    </div>
    <a href="pos.php" class="btn" style="text-decoration:none;">+ New sale</a>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($editing): ?>
<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Edit sale entry</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

        <div class="form-grid">
            <!-- Product and quantity are locked once a sale is saved - see the
                 note in the PHP above for why. -->
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
            <div class="field">
                <label for="amount">Amount (₱)</label>
                <input type="number" step="0.01" min="0.01" id="amount" name="amount"
                       value="<?= h((string) $editing['amount']) ?>" required>
            </div>
            <div class="field">
                <label for="income_date">Date</label>
                <input type="date" id="income_date" name="income_date"
                       value="<?= h($editing['income_date']) ?>" required>
            </div>
            <div class="field full">
                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" placeholder="Any extra detail worth remembering"><?= h($editing['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Save changes</button>
            <a href="income.php" class="btn-ghost btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">All sales</div>
    <?php if (!$income_list): ?>
        <div class="empty-state">No sales recorded yet. Head to <a href="pos.php">New Sale</a> to ring up your first one.</div>
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

<?php include __DIR__ . '/includes/footer.php'; ?>