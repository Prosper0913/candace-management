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
            <a class="nav-link <?= $active_nav === 'shipments' ? 'active' : '' ?>" href="shipments.php">Shipments</a>
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

        <?php if (!empty($_SESSION['user_id'])):
            $shipment_alerts = get_shipment_alerts($pdo, (int) $_SESSION['user_id']);
        ?>
            <?php foreach ($shipment_alerts['urgent'] as $s): ?>
                <div class="shipment-alert shipment-alert-urgent">
                    <strong><?= h($s['supplier'] ?: 'Shipment') ?></strong>
                    &mdash; <?= (int) $s['item_count'] ?> item(s), <?= h(peso((float) $s['total_cost'])) ?>
                    &mdash; <?= h(shipment_due_label((int) $s['days_left'])) ?>
                    (<?= h(display_date($s['expected_date'])) ?>)
                    <a href="shipments.php" class="shipment-alert-link">View shipments</a>
                </div>
            <?php endforeach; ?>
            <?php foreach ($shipment_alerts['upcoming'] as $s): ?>
                <div class="shipment-alert shipment-alert-upcoming">
                    <strong><?= h($s['supplier'] ?: 'Shipment') ?></strong>
                    &mdash; <?= (int) $s['item_count'] ?> item(s), <?= h(peso((float) $s['total_cost'])) ?>
                    &mdash; <?= h(shipment_due_label((int) $s['days_left'])) ?>
                    (<?= h(display_date($s['expected_date'])) ?>)
                    <a href="shipments.php" class="shipment-alert-link">View shipments</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>