<?php
/**
 * Expects $page_title and optional $page_subtitle to be set before include.
 * Expects $active_nav to be one of: dashboard, income, expenses, categories, reports
 */
$active_nav = $active_nav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title ?? APP_NAME) ?> · <?= h(STORE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= h(ASSET_VERSION) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">Candace Store</span>
            <span class="brand-name"><?= h(STORE_NAME) ?></span>
        </div>

        <nav class="nav-group">
            <span class="nav-label">Overview</span>
            <a class="nav-link <?= $active_nav === 'dashboard' ? 'active' : '' ?>" href="index.php">Dashboard</a>
        </nav>

        <nav class="nav-group">
            <span class="nav-label">Record</span>
            <a class="nav-link <?= $active_nav === 'income' ? 'active' : '' ?>" href="income.php">Sales</a>
            <a class="nav-link <?= $active_nav === 'expenses' ? 'active' : '' ?>" href="expenses.php">Expenses</a>
            <a class="nav-link <?= $active_nav === 'categories' ? 'active' : '' ?>" href="categories.php">Categories</a>
        </nav>

        <nav class="nav-group">
            <span class="nav-label">Store</span>
            <a class="nav-link <?= $active_nav === 'pos' ? 'active' : '' ?>" href="pos.php">Scan Sale</a>
            <a class="nav-link <?= $active_nav === 'products' ? 'active' : '' ?>" href="products.php">Products</a>
        </nav>

        <nav class="nav-group">
            <span class="nav-label">Insights</span>
            <a class="nav-link <?= $active_nav === 'reports' ? 'active' : '' ?>" href="reports.php">Reports</a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-chip">
                <span class="name"><?= h($_SESSION['full_name'] ?? '') ?></span>
                <span class="username">@<?= h($_SESSION['username'] ?? '') ?></span>
                <a class="logout-link" href="logout.php">Log out</a>
            </div>
        </div>
    </aside>

    <main class="main">
        <?php foreach (get_flashes() as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>