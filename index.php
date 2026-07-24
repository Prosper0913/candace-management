<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$user_id = current_user_id();

// ---- All-time totals ----------------------------------------------------
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM income WHERE user_id = ?');
$stmt->execute([$user_id]);
$total_income = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE user_id = ?');
$stmt->execute([$user_id]);
$total_expenses = (float) $stmt->fetchColumn();

$balance = $total_income - $total_expenses;

// ---- This month's totals ------------------------------------------------
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM income WHERE user_id = ? AND income_date BETWEEN ? AND ?');
$stmt->execute([$user_id, $monthStart, $monthEnd]);
$month_income = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id = ? AND expense_date BETWEEN ? AND ?');
$stmt->execute([$user_id, $monthStart, $monthEnd]);
$month_expenses = (float) $stmt->fetchColumn();

// ---- Category breakdown (this month) -----------------------------------
$stmt = $pdo->prepare(
    'SELECT COALESCE(c.name, "Uncategorized") AS name, SUM(e.amount) AS total
     FROM expenses e
     LEFT JOIN categories c ON c.id = e.category_id
     WHERE e.user_id = ? AND e.expense_date BETWEEN ? AND ?
     GROUP BY name
     ORDER BY total DESC'
);
$stmt->execute([$user_id, $monthStart, $monthEnd]);
$category_breakdown = $stmt->fetchAll();
$max_category = 0;
foreach ($category_breakdown as $row) $max_category = max($max_category, (float) $row['total']);

// ---- Recent transactions (last 8, income + expenses merged) ------------
$stmt = $pdo->prepare(
    "(SELECT 'income' AS type, source AS label, amount, income_date AS the_date, NULL AS category
      FROM income WHERE user_id = ?)
     UNION ALL
     (SELECT 'expense' AS type, e.title AS label, e.amount, e.expense_date AS the_date, c.name AS category
      FROM expenses e LEFT JOIN categories c ON c.id = e.category_id WHERE e.user_id = ?)
     ORDER BY the_date DESC, the_date IS NULL
     LIMIT 8"
);
$stmt->execute([$user_id, $user_id]);
$recent = $stmt->fetchAll();

$page_title = 'Dashboard';
$active_nav = 'dashboard';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Overview</p>
        <h1>Welcome back, <?= h($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?></h1>
        <p>Here's a real-time snapshot of your finances as of <?= date('F j, Y') ?>.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="income.php" class="btn-ghost btn" style="text-decoration:none;">+ Add sale</a>
        <a href="expenses.php" class="btn" style="text-decoration:none;">+ Add expense</a>
    </div>
</div>

<div class="grid grid-3" style="margin-bottom:24px;">
    <div class="stat-tile">
        <div class="label">Total sale (all time)</div>
        <div class="value positive"><?= peso($total_income) ?></div>
        <div class="sub">This month: <?= peso($month_income) ?></div>
    </div>
    <div class="stat-tile">
        <div class="label">Total expenses (all time)</div>
        <div class="value negative"><?= peso($total_expenses) ?></div>
        <div class="sub">This month: <?= peso($month_expenses) ?></div>
    </div>
    <div class="stat-tile">
        <div class="label">Remaining balance</div>
        <div class="value <?= $balance >= 0 ? 'positive' : 'negative' ?>"><?= peso($balance) ?></div>
        <div class="sub">Sales minus expenses, updated automatically</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-title">Recent activity</div>
        <?php if (!$recent): ?>
            <div class="empty-state">No transactions yet. Add your first sale or expense to get started.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Date</th><th>Description</th><th>Type</th><th class="amount">Amount</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= h(display_date($row['the_date'])) ?></td>
                        <td>
                            <?= h($row['label']) ?>
                            <?php if ($row['category']): ?><span class="tag"><?= h($row['category']) ?></span><?php endif; ?>
                        </td>
                        <td><?= $row['type'] === 'income' ? 'Income' : 'Expense' ?></td>
                        <td class="amount <?= $row['type'] === 'income' ? 'positive' : 'negative' ?>" style="color:var(--<?= $row['type'] === 'income' ? 'positive' : 'negative' ?>);">
                            <?= $row['type'] === 'income' ? '+' : '-' ?><?= peso((float) $row['amount']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title">Where this month's money went</div>
        <?php if (!$category_breakdown): ?>
            <div class="empty-state">No expenses recorded this month yet.</div>
        <?php else: ?>
            <?php foreach ($category_breakdown as $row):
                $pct = $max_category > 0 ? ((float) $row['total'] / $max_category) * 100 : 0; ?>
                <div class="cat-row">
                    <div class="name"><?= h($row['name']) ?></div>
                    <div class="bar-track"><div class="bar-fill" style="width: <?= $pct ?>%;"></div></div>
                    <div class="amount"><?= peso((float) $row['total']) ?></div>
                </div>
            <?php endforeach; ?>
            <p class="helper-text">See the Reports page for trends over longer periods.</p>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
