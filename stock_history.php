<?php
require_once __DIR__ . '/includes/functions.php';
require_owner();

$user_id = store_scope_id();

$stmt = $pdo->prepare(
    'SELECT sh.*, p.name AS product_name, p.barcode, u.full_name AS performed_by_name
     FROM stock_history sh
     JOIN products p ON p.id = sh.product_id
     LEFT JOIN users u ON u.id = sh.performed_by
     WHERE sh.user_id = ?
     ORDER BY sh.created_at DESC
     LIMIT 200'
);
$stmt->execute([$user_id]);
$history = $stmt->fetchAll();

$page_title = 'Stock History';
$active_nav = 'products';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Stock History</h1>
        <p>Every time stock came in - a manual restock or a shipment marked received - with who did it and when.</p>
    </div>
    <a href="products.php" class="btn-ghost btn" style="text-decoration:none;">Back to Products</a>
</div>

<div class="card">
    <div class="card-title">Recent additions</div>
    <?php if (!$history): ?>
        <div class="empty-state">No stock additions logged yet. Use "Add stock" on the Products page, or mark a shipment as received.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>When</th><th>Product</th><th>Qty added</th><th>Source</th><th>By</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= h(date('M j, Y g:i A', strtotime($row['created_at']))) ?></td>
                    <td>
                        <?= h($row['product_name']) ?>
                        <br><span class="helper-text"><?= h($row['barcode']) ?></span>
                    </td>
                    <td style="color:var(--positive); font-weight:600;">+<?= (int) $row['quantity_added'] ?></td>
                    <td>
                        <span class="tag"><?= $row['source'] === 'shipment' ? 'Shipment' : 'Manual' ?></span>
                        <?php if ($row['reference']): ?><br><span class="helper-text"><?= h($row['reference']) ?></span><?php endif; ?>
                    </td>
                    <td><?= h($row['performed_by_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>