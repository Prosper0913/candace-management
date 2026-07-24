<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$old = ['full_name' => '', 'username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $old['full_name'] = trim($_POST['full_name'] ?? '');
        $old['username']  = trim($_POST['username'] ?? '');
        $old['email']     = trim($_POST['email'] ?? '');
        $password          = $_POST['password'] ?? '';
        $password_confirm  = $_POST['password_confirm'] ?? '';

        if ($old['full_name'] === '') $errors[] = 'Full name is required.';
        if ($old['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $old['username'])) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, underscore only).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $password_confirm) $errors[] = 'Passwords do not match.';

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$old['username'], $old['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'That username or email is already registered.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, username, email, password_hash) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([
                    $old['full_name'],
                    $old['username'],
                    $old['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
                $user_id = (int) $pdo->lastInsertId();

                $catStmt = $pdo->prepare('INSERT INTO categories (user_id, name) VALUES (?, ?)');
                foreach (DEFAULT_CATEGORIES as $catName) {
                    $catStmt->execute([$user_id, $catName]);
                }

                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION['user_id']   = $user_id;
                $_SESSION['full_name'] = $old['full_name'];
                $_SESSION['username']  = $old['username'];

                set_flash('success', 'Welcome to Candace! Your account is ready.');
                header('Location: index.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Something went wrong while creating your account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create account · <?= h(STORE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= h(ASSET_VERSION) ?>">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <div class="brand-mark">Candace Management System</div>
        <h1>Create your account</h1>

        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="field" style="margin-bottom:14px;">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" value="<?= h($old['full_name']) ?>" required>
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= h($old['username']) ?>" required>
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= h($old['email']) ?>" required>
            </div>
            <div class="field" style="margin-bottom:14px;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="6" required>
            </div>
            <div class="field" style="margin-bottom:6px;">
                <label for="password_confirm">Confirm password</label>
                <input type="password" id="password_confirm" name="password_confirm" minlength="6" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" style="width:100%;">Create account</button>
            </div>
        </form>

        <p class="auth-foot">Already have an account? <a class="icon-link" href="login.php">Log in</a></p>
    </div>
</div>
</body>
</html>
