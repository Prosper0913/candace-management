<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: homepage.php');
    exit;
}

$errors = [];
$old_username = '';

// Very light throttling against brute-force login attempts
if (empty($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (empty($_SESSION['login_window_start'])) $_SESSION['login_window_start'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() - $_SESSION['login_window_start'] > 900) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_window_start'] = time();
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($_SESSION['login_attempts'] >= 8) {
        $errors[] = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $old_username = trim($_POST['username'] ?? '');
        $password     = $_POST['password'] ?? '';

        $stmt = $pdo->prepare('SELECT id, full_name, username, password_hash FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$old_username, $old_username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int) $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['login_attempts'] = 0;

            header('Location: homepage.php');
            exit;
        }

        $_SESSION['login_attempts']++;
        $errors[] = 'Incorrect username/email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in · <?= h(STORE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= h(ASSET_VERSION) ?>">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <div class="brand-mark">Candace Management System</div>
        <h1>Log in</h1>

        <?php foreach ($errors as $error): ?>
            <div class="flash error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <div class="field" style="margin-bottom:14px;">
                <label for="username">Username or email</label>
                <input type="text" id="username" name="username" value="<?= h($old_username) ?>" required autofocus>
            </div>
            <div class="field" style="margin-bottom:6px;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" style="width:100%;">Log in</button>
            </div>
        </form>

        <p class="auth-foot">New here? <a class="icon-link" href="register.php">Create an account</a></p>
    </div>
</div>
</body>
</html>
