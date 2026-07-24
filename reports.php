<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();

// ---- Date range filter (defaults to last 6 months) ----------------------
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01'))));
$to   = $_GET['to'] ?? date('Y-m-d');

if (!strtotime($from)) $from = date('Y-m-d', strtotime('-5 months'));
if (!strtotime($to)) $to = date('Y-m-d');

// ---- Totals for the selected range ---------------------------------------
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM income WHERE user_id = ? AND income_date BETWEEN ? AND ?');
$stmt->execute([$user_id, $from, $to]);
$range_income = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ?');
$stmt->execute([$user_id, $from, $to]);
$range_expenses = (float) $stmt->fetchColumn();

$range_net = $range_income - $range_expenses;

// ---- Monthly trend (income vs expenses) ----------------------------------
$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(income_date, '%Y-%m') AS ym, SUM(amount) AS total
     FROM income WHERE user_id = ? AND income_date BETWEEN ? AND ?
     GROUP BY ym ORDER BY ym"
);
$stmt->execute([$user_id, $from, $to]);
$income_by_month = [];
foreach ($stmt->fetchAll() as $r) $income_by_month[$r['ym']] = (float) $r['total'];

$stmt = $pdo->prepare(
    "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total
     FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ?
     GROUP BY ym ORDER BY ym"
);
$stmt->execute([$user_id, $from, $to]);
$expense_by_month = [];
foreach ($stmt->fetchAll() as $r) $expense_by_month[$r['ym']] = (float) $r['total'];

$months = array_unique(array_merge(array_keys($income_by_month), array_keys($expense_by_month)));
sort($months);
if (!$months) {
    // Build an empty month axis so the chart still renders sensibly
    $cursor = new DateTime(date('Y-m-01', strtotime($from)));
    $end = new DateTime(date('Y-m-01', strtotime($to)));
    while ($cursor <= $end) {
        $months[] = $cursor->format('Y-m');
        $cursor->modify('+1 month');
    }
}

$month_labels = array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), $months);
$month_income_series = array_map(fn($ym) => round($income_by_month[$ym] ?? 0, 2), $months);
$month_expense_series = array_map(fn($ym) => round($expense_by_month[$ym] ?? 0, 2), $months);

// ---- Category breakdown for the range ------------------------------------
$stmt = $pdo->prepare(
    'SELECT COALESCE(c.name, "Uncategorized") AS name, SUM(e.amount) AS total
     FROM expenses e LEFT JOIN categories c ON c.id = e.category_id
     WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ?
     GROUP BY name ORDER BY total DESC'
);
$stmt->execute([$user_id, $from, $to]);
$category_rows = $stmt->fetchAll();
$category_labels = array_column($category_rows, 'name');
$category_totals = array_map('floatval', array_column($category_rows, 'total'));

$page_title = 'Reports';
$active_nav = 'reports';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Insights</p>
        <h1>Reports</h1>
        <p>Spending patterns and sales trends over the period you choose.</p>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?= h($from) ?>">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?= h($to) ?>">
    </div>
    <button type="submit" class="btn btn-small">Apply</button>
    <a href="reports.php" class="btn-ghost btn btn-small">Reset</a>
</form>

<div class="grid grid-3" style="margin-bottom:24px;">
    <div class="stat-tile">
        <div class="label">Sales in range</div>
        <div class="value positive"><?= peso($range_income) ?></div>
    </div>
    <div class="stat-tile">
        <div class="label">Expenses in range</div>
        <div class="value negative"><?= peso($range_expenses) ?></div>
    </div>
    <div class="stat-tile">
        <div class="label">Net for range</div>
        <div class="value <?= $range_net >= 0 ? 'positive' : 'negative' ?>"><?= peso($range_net) ?></div>
    </div>
</div>

<div class="card" style="margin-bottom:24px;">
    <div class="card-title">Sales vs. expenses by month</div>
    <?php if (!array_sum($month_income_series) && !array_sum($month_expense_series)): ?>
        <div class="empty-state">No data in this range yet.</div>
    <?php else: ?>
        <canvas id="trendChart" height="90"></canvas>
    <?php endif; ?>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-title">Spending by category</div>
        <?php if (!$category_rows): ?>
            <div class="empty-state">No expenses in this range yet.</div>
        <?php else: ?>
            <canvas id="categoryChart" height="220"></canvas>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">Category summary</div>
        <?php if (!$category_rows): ?>
            <div class="empty-state">Nothing to summarize yet.</div>
        <?php else: ?>
            <table>
                <thead><tr><th>Category</th><th class="amount">Total</th><th class="amount">Share</th></tr></thead>
                <tbody>
                <?php foreach ($category_rows as $row):
                    $share = $range_expenses > 0 ? ((float) $row['total'] / $range_expenses) * 100 : 0; ?>
                    <tr>
                        <td><?= h($row['name']) ?></td>
                        <td class="amount"><?= peso((float) $row['total']) ?></td>
                        <td class="amount"><?= number_format($share, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const inkColor = '#17181a';
const gridColor = '#eeeeec';

<?php if (array_sum($month_income_series) || array_sum($month_expense_series)): ?>
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($month_labels) ?>,
        datasets: [
            {
                label: 'Sales',
                data: <?= json_encode($month_income_series) ?>,
                backgroundColor: '#3f6b52'
            },
            {
                label: 'Expenses',
                data: <?= json_encode($month_expense_series) ?>,
                backgroundColor: '#8a4a3d'
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: inkColor } } },
        scales: {
            x: { grid: { color: gridColor }, ticks: { color: inkColor } },
            y: { grid: { color: gridColor }, ticks: { color: inkColor }, beginAtZero: true }
        }
    }
});
<?php endif; ?>

<?php if ($category_rows): ?>
new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($category_labels) ?>,
        datasets: [{
            data: <?= json_encode($category_totals) ?>,
            backgroundColor: ['#17181a', '#55575c', '#8a8c90', '#b6b8bb', '#3f6b52', '#8a4a3d', '#c9c9c6', '#dedede']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: inkColor, boxWidth: 12 } } }
    }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
