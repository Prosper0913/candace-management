<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();
$sale_id = (int) ($_GET['sale'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND user_id = ?');
$stmt->execute([$sale_id, $user_id]);
$sale = $stmt->fetch();

if (!$sale) {
    set_flash('error', 'That receipt could not be found.');
    header('Location: pos.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt #<?= (int) $sale['id'] ?> &middot; <?= h(STORE_NAME) ?></title>
<style>
    :root { --ink:#17181a; --line:#cfcfcc; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 24px;
        background: #e9e9e7;
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        color: var(--ink);
    }
    .receipt {
        width: 300px;
        max-width: 100%;
        margin: 0 auto;
        background: #fff;
        padding: 18px 16px;
        font-size: 13px;
        line-height: 1.5;
    }
    .receipt h1 { font-size: 16px; margin: 0 0 2px; text-align: center; }
    .receipt .addr { text-align: center; font-size: 11px; color: #555; margin: 0 0 12px; }
    .receipt .meta { font-size: 11px; color: #555; margin-bottom: 10px; }
    .rule { border: none; border-top: 1px dashed var(--line); margin: 10px 0; }
    .receipt table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .receipt th, .receipt td { text-align: left; padding: 2px 0; }
    .receipt td.num, .receipt th.num { text-align: right; }
    .receipt .total-row td { font-weight: 700; font-size: 14px; padding-top: 8px; }
    .receipt .thanks { text-align: center; margin-top: 14px; font-size: 12px; }
    .actions { max-width: 300px; margin: 14px auto 0; display: flex; gap: 10px; justify-content: center; }
    .actions button, .actions a {
        font-family: inherit; font-size: 13px; padding: 8px 14px; cursor: pointer;
        border: 1px solid var(--ink); background: var(--ink); color: #fff; border-radius: 2px;
        text-decoration: none;
    }
    .actions a { background: #fff; color: var(--ink); }

    @media print {
        body { background: #fff; padding: 0; }
        .receipt { width: auto; padding: 0; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

<div class="receipt">
    <h1><?= h(STORE_NAME) ?></h1>
    <?php if (defined('STORE_ADDRESS') && STORE_ADDRESS): ?>
        <p class="addr"><?= h(STORE_ADDRESS) ?></p>
    <?php endif; ?>
    <p class="meta">
        Receipt #<?= str_pad((string) $sale['id'], 6, '0', STR_PAD_LEFT) ?><br>
        <?= h(date('M j, Y g:i A', strtotime($sale['created_at']))) ?>
    </p>
    <hr class="rule">
    <table>
        <thead>
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td colspan="3"><?= h($item['name']) ?></td>
            </tr>
            <tr>
                <td style="color:#777;">&#8369;<?= number_format((float) $item['unit_price'], 2) ?> each</td>
                <td class="num"><?= (int) $item['quantity'] ?></td>
                <td class="num">&#8369;<?= number_format((float) $item['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="2">TOTAL</td>
                <td class="num">&#8369;<?= number_format((float) $sale['total_amount'], 2) ?></td>
            </tr>
        </tbody>
    </table>
    <hr class="rule">
    <p class="thanks">Thank you for shopping with us!</p>
</div>

<div class="actions no-print">
    <button type="button" id="reprint-btn">Reprint on receipt printer</button>
    <button type="button" onclick="window.print()">Print via browser instead</button>
    <a href="pos.php">Back to Scan Sale</a>
</div>
<p class="no-print" id="reprint-status" style="max-width:300px; margin:10px auto 0; text-align:center; font-size:12px; color:#555;"></p>

<script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    const saleId = <?= (int) $sale['id'] ?>;
    const reprintBtn = document.getElementById('reprint-btn');
    const reprintStatus = document.getElementById('reprint-status');

    reprintBtn.addEventListener('click', () => {
        reprintBtn.disabled = true;
        reprintBtn.textContent = 'Printing\u2026';
        reprintStatus.textContent = '';

        fetch('pos_api.php?action=reprint', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, sale_id: saleId })
        })
            .then(r => r.json())
            .then(data => {
                reprintStatus.textContent = data.ok
                    ? 'Sent to the printer.'
                    : ('Printing failed: ' + (data.error || 'Unknown error') + ' - try "Print via browser instead".');
                reprintStatus.style.color = data.ok ? '#2a7a2a' : '#b3261e';
            })
            .catch(() => {
                reprintStatus.textContent = 'Could not reach the server.';
                reprintStatus.style.color = '#b3261e';
            })
            .finally(() => {
                reprintBtn.disabled = false;
                reprintBtn.textContent = 'Reprint on receipt printer';
            });
    });
</script>
</body>
</html>