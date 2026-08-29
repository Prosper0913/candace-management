<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$low_stock = get_low_stock_products($pdo, $user_id);
$shipment_alerts = get_shipment_alerts($pdo, $user_id);

$page_title = 'Notifications';
$active_nav = 'notifications';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Notifications</h1>
        <p>Everything that needs your attention, in one place.</p>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Shipments due soon</div>
    <?php if (!$shipment_alerts['urgent'] && !$shipment_alerts['upcoming']): ?>
        <div class="empty-state">Nothing due in the next 3 days.</div>
    <?php else: ?>
        <?php foreach ($shipment_alerts['urgent'] as $s): ?>
            <div class="shipment-alert shipment-alert-urgent">
                <strong><?= h($s['supplier'] ?: 'Shipment') ?></strong>
                &mdash; <?= (int) $s['item_count'] ?> item(s), <?= h(peso((float) $s['total_cost'])) ?>
                &mdash; <?= h(shipment_due_label((int) $s['days_left'])) ?>
                (<?= h(display_date($s['expected_date'])) ?>)
                <a href="shipments.php" class="shipment-alert-link">View</a>
            </div>
        <?php endforeach; ?>
        <?php foreach ($shipment_alerts['upcoming'] as $s): ?>
            <div class="shipment-alert shipment-alert-upcoming">
                <strong><?= h($s['supplier'] ?: 'Shipment') ?></strong>
                &mdash; <?= (int) $s['item_count'] ?> item(s), <?= h(peso((float) $s['total_cost'])) ?>
                &mdash; <?= h(shipment_due_label((int) $s['days_left'])) ?>
                (<?= h(display_date($s['expected_date'])) ?>)
                <a href="shipments.php" class="shipment-alert-link">View</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">Running low on stock</div>
    <?php if (!$low_stock): ?>
        <div class="empty-state">Nothing is running low right now.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Product</th><th>Barcode</th><th>Stock left</th><th>Warning level</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($low_stock as $p): ?>
                <tr>
                    <td><?= h($p['name']) ?></td>
                    <td style="font-family:var(--font-mono);"><?= h($p['barcode']) ?></td>
                    <td style="color:var(--negative); font-weight:600;"><?= (int) $p['stock_quantity'] ?></td>
                    <td><?= (int) $p['low_stock_threshold'] ?></td>
                    <td class="actions"><a class="icon-link" href="products.php?edit=<?= (int) $p['id'] ?>">Restock / edit</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>