<?php
require_once __DIR__ . '/includes/functions.php';
require_owner();

$owner_id = current_user_id(); // an owner's own id IS the store scope id
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: staff.php');
        exit;
    }

    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        // Only ever delete a cashier that actually belongs to this owner.
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND owner_id = ? AND role = "cashier"');
        $stmt->execute([$id, $owner_id]);
        set_flash('success', 'Cashier access removed.');
        header('Location: staff.php');
        exit;
    }

    // ---- Create a new cashier account ----
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3-50 characters (letters, numbers, underscore only).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            $errors[] = 'That username or email is already registered.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, username, email, password_hash, role, owner_id) VALUES (?, ?, ?, ?, "cashier", ?)'
        );
        $stmt->execute([$full_name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $owner_id]);
        set_flash('success', "Cashier account created. Share the username and password with {$full_name} so they can log in.");
        header('Location: staff.php');
        exit;
    }
}

$stmt = $pdo->prepare('SELECT id, full_name, username, email, created_at FROM users WHERE owner_id = ? AND role = "cashier" ORDER BY full_name');
$stmt->execute([$owner_id]);
$cashiers = $stmt->fetchAll();

$page_title = 'Staff';
$active_nav = 'staff';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Staff</h1>
        <p>Cashier accounts can ring up sales and record expenses, sharing your store's inventory and ledger. They can't see reports, manage products, or edit/delete expenses.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Add a cashier</div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-grid">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="6" required>
            </div>
            <div class="field">
                <label for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" minlength="6" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Create cashier account</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-title">Cashier accounts</div>
    <?php if (!$cashiers): ?>
        <div class="empty-state">No cashier accounts yet. Add one above to let someone else ring up sales without giving them your own login.</div>
    <?php else: ?>
        <table>
            <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cashiers as $c): ?>
                <tr>
                    <td><?= h($c['full_name']) ?></td>
                    <td><?= h($c['username']) ?></td>
                    <td><?= h($c['email']) ?></td>
                    <td><?= h(display_date($c['created_at'])) ?></td>
                    <td class="actions">
                        <form method="post" style="display:inline;" onsubmit="return confirm('Remove this cashier access? They will no longer be able to log in.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="icon-link" style="background:none;border:none;cursor:pointer;color:var(--negative);padding:0;">Remove access</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>