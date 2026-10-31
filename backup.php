<?php
/**
 * Candace Management System - Backup (owner only)
 *
 * Builds a restorable .sql file of this store's data and sends it to the
 * browser as a download. Two modes:
 *   - "full":  everything, every date.
 *   - "range": only dated records (sales, expenses, shipments, stock history)
 *              that fall between two dates. Records that aren't tied to a
 *              date (products + current stock, categories, accounts, VAT
 *              setting) are ALWAYS included in full, because they describe
 *              the store as it is now.
 *
 * Written in plain PHP/PDO on purpose: it doesn't depend on mysqldump being
 * installed or on its path, so it keeps working after the app is moved off
 * XAMPP.
 *
 * A second button makes the same backup as an Excel workbook (.xlsx) with the
 * financial and inventory data in readable sheets - see includes/backup_excel.php.
 * That one is for reading/reporting; the .sql file is the one that restores.
 *
 * Restoring: import the file with phpMyAdmin (Import tab) or
 *   mysql -u root candace_system < backup.sql
 * It uses CREATE TABLE IF NOT EXISTS + INSERT IGNORE, so it never deletes or
 * overwrites anything: on an empty database it rebuilds everything, and on a
 * live database it only puts back rows that are missing.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/backup_excel.php';
require_owner();

$store_id = store_scope_id();

/**
 * The tables in this backup, in restore-safe order (parents before children).
 *   'where'  - how rows are limited to THIS store (never other stores' data)
 *   'range'  - how rows are limited by date in "range" mode (null = always everything)
 *   'order'  - stable row order
 * ':store' is replaced with the store id, ':from' / ':to' with the range dates.
 */
function backup_table_plan(): array
{
    return [
        'users' => [
            'label' => 'Accounts (owner + cashiers)',
            'where' => '(id = :store OR owner_id = :store)',
            'range' => null,
            'order' => 'id',
        ],
        'store_settings' => [
            'label' => 'Store settings (VAT)',
            'where' => 'user_id = :store',
            'range' => null,
            'order' => 'user_id',
        ],
        'categories' => [
            'label' => 'Expense categories',
            'where' => 'user_id = :store',
            'range' => null,
            'order' => 'id',
        ],
        'products' => [
            'label' => 'Products & current stock',
            'where' => 'user_id = :store',
            'range' => null,
            'order' => 'id',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'where' => 'user_id = :store',
            'range' => 'expense_date BETWEEN :from AND :to',
            'order' => 'id',
        ],
        'income' => [
            'label' => 'Sales ledger (income)',
            'where' => 'user_id = :store',
            'range' => 'income_date BETWEEN :from AND :to',
            'order' => 'id',
        ],
        'sales' => [
            'label' => 'Sales / receipts',
            'where' => 'user_id = :store',
            'range' => 'sale_date BETWEEN :from AND :to',
            'order' => 'id',
        ],
        'sale_items' => [
            'label' => 'Sold items (receipt lines)',
            'where' => 'sale_id IN (SELECT id FROM sales WHERE user_id = :store'
                     . '{RANGE_SALES})',
            'range' => null, // follows its parent sale, see {RANGE_SALES}
            'order' => 'id',
        ],
        'shipments' => [
            'label' => 'Shipments (by expected date)',
            'where' => 'user_id = :store',
            'range' => 'expected_date BETWEEN :from AND :to',
            'order' => 'id',
        ],
        'shipment_items' => [
            'label' => 'Shipment items',
            'where' => 'shipment_id IN (SELECT id FROM shipments WHERE user_id = :store'
                     . '{RANGE_SHIPMENTS})',
            'range' => null, // follows its parent shipment
            'order' => 'id',
        ],
        'stock_history' => [
            'label' => 'Stock history',
            'where' => 'user_id = :store',
            'range' => 'DATE(created_at) BETWEEN :from AND :to',
            'order' => 'id',
        ],
    ];
}

/** Build the final WHERE clause + params for one table. */
function backup_where(array $spec, int $store_id, ?string $from, ?string $to): array
{
    $sql = $spec['where'];
    $isRange = $from !== null && $to !== null;

    $sql = str_replace('{RANGE_SALES}', $isRange ? ' AND sale_date BETWEEN :from AND :to' : '', $sql);
    $sql = str_replace('{RANGE_SHIPMENTS}', $isRange ? ' AND expected_date BETWEEN :from AND :to' : '', $sql);

    if ($isRange && $spec['range'] !== null) {
        $sql .= ' AND ' . $spec['range'];
    }

    $params = [];
    if (strpos($sql, ':store') !== false) $params[':store'] = $store_id;
    if (strpos($sql, ':from') !== false)  $params[':from'] = $from;
    if (strpos($sql, ':to') !== false)    $params[':to'] = $to;

    // PDO with native prepares can't reuse one named placeholder several
    // times, so number each occurrence separately.
    $n = 0;
    $final = [];
    $sql = preg_replace_callback('/:(store|from|to)\b/', function ($m) use (&$n, &$final, $params) {
        $key = ':' . $m[1] . '_' . (++$n);
        $final[$key] = $params[':' . $m[1]];
        return $key;
    }, $sql);

    return [$sql, $final];
}

/** SQL literal for one value. */
function backup_literal(PDO $pdo, $value): string
{
    if ($value === null) return 'NULL';
    if (is_int($value)) return (string) $value;
    return $pdo->quote((string) $value);
}

/**
 * Writes the whole dump into $out (a stream). Returns row counts per table.
 * Throws on a real database failure.
 */
function backup_write_dump(PDO $pdo, $out, int $store_id, ?string $from, ?string $to): array
{
    $counts = [];
    $batch_size = 200;

    foreach (backup_table_plan() as $table => $spec) {
        // ---- structure ----
        try {
            $showStmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $row = $showStmt->fetch(PDO::FETCH_NUM);
            $showStmt->closeCursor(); // required in streaming (unbuffered) mode
        } catch (PDOException $e) {
            // 42S02 = table doesn't exist (e.g. an older database that never
            // ran a migration). Note it in the file and carry on.
            if ($e->getCode() === '42S02') {
                fwrite($out, "-- Table `{$table}` does not exist in this database - skipped.\n\n");
                $counts[$table] = null;
                continue;
            }
            throw $e;
        }
        $create = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $row[1], 1);

        fwrite($out, "--\n-- Table `{$table}`\n--\n\n");
        fwrite($out, $create . ";\n\n");

        // ---- data ----
        [$where, $params] = backup_where($spec, $store_id, $from, $to);
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$spec['order']}");
        $stmt->execute($params);

        $columns = null;
        $batch = [];
        $count = 0;

        $flush = function () use (&$batch, &$columns, $out, $table) {
            if (!$batch) return;
            fwrite($out, "INSERT IGNORE INTO `{$table}` (" . $columns . ") VALUES\n"
                . implode(",\n", $batch) . ";\n");
            $batch = [];
        };

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = '`' . implode('`, `', array_keys($r)) . '`';
            }
            $vals = [];
            foreach ($r as $v) $vals[] = backup_literal($pdo, $v);
            $batch[] = '(' . implode(', ', $vals) . ')';
            $count++;
            if (count($batch) >= $batch_size) $flush();
        }
        $stmt->closeCursor();
        $flush();

        if ($count === 0) fwrite($out, "-- (no rows)\n");
        fwrite($out, "\n");
        $counts[$table] = $count;
    }

    return $counts;
}

// ======================================================================
// Download request
// ======================================================================
$errors = [];
$mode = 'full';
$from = date('Y-m-01');
$to   = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: backup.php');
        exit;
    }

    $mode = ($_POST['mode'] ?? 'full') === 'range' ? 'range' : 'full';
    $from = trim($_POST['from'] ?? '');
    $to   = trim($_POST['to'] ?? '');

    $isDate = fn($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

    if ($mode === 'range') {
        if (!$isDate($from) || !$isDate($to)) {
            $errors[] = 'Please choose both a start and an end date.';
        } elseif ($from > $to) {
            $errors[] = 'The start date can\'t be after the end date.';
        }
    }

    $format = ($_POST['format'] ?? 'sql') === 'xlsx' ? 'xlsx' : 'sql';

    if (!$errors && $format === 'xlsx') {
        $range_from = $mode === 'range' ? $from : null;
        $range_to   = $mode === 'range' ? $to : null;

        if (!XlsxWorkbook::available()) {
            $errors[] = 'Excel export needs PHP\'s "zip" extension, which is switched off on this computer. '
                      . 'In XAMPP, open php.ini and make sure the line "extension=zip" has no semicolon in front, then restart Apache.';
        } else {
            @set_time_limit(300);
            $tmp = tempnam(sys_get_temp_dir(), 'candace_xlsx_');
            try {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                backup_build_xlsx($pdo, $store_id, $range_from, $range_to, $tmp, [
                    'store'      => STORE_NAME,
                    'created'    => date('Y-m-d H:i:s') . ' (' . date_default_timezone_get() . ')',
                    'created_by' => $_SESSION['username'] ?? '?',
                    'contents'   => $mode === 'range'
                        ? "Date range {$from} to {$to} (sales, expenses, shipments and stock history limited to these dates; products are the full current list)"
                        : 'Everything (all dates)',
                ]);
            } catch (Throwable $e) {
                $errors[] = 'The Excel file could not be created, so nothing was downloaded. Please try again.';
                $tmp = null;
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

            if ($tmp !== null && is_file($tmp)) {
                $stamp = date('Y-m-d_His');
                $filename = $mode === 'range'
                    ? "candace_excel_{$from}_to_{$to}_made-{$stamp}.xlsx"
                    : "candace_excel_full_{$stamp}.xlsx";

                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($tmp));
                header('Cache-Control: no-store');
                readfile($tmp);
                @unlink($tmp);
                exit;
            }
            if ($tmp !== null) @unlink($tmp);
        }
    }

    if (!$errors && $format === 'sql') {
        $range_from = $mode === 'range' ? $from : null;
        $range_to   = $mode === 'range' ? $to : null;

        @set_time_limit(300);
        $body = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+');

        try {
            // Stream rows instead of loading whole tables into memory.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $counts = backup_write_dump($pdo, $body, $store_id, $range_from, $range_to);
        } catch (Throwable $e) {
            fclose($body);
            $errors[] = 'The backup could not be created, so nothing was downloaded. Please try again.';
            $counts = null;
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        if ($counts !== null) {
            $now = date('Y-m-d H:i:s');
            $scope = $mode === 'range'
                ? "Date range {$from} to {$to} (dated records only; products, categories, accounts and settings are included in full)"
                : 'Everything (all dates)';

            $head  = "-- ==========================================================\n";
            $head .= '-- ' . APP_NAME . " - backup\n";
            $head .= '-- Store:      ' . STORE_NAME . "\n";
            $head .= '-- Created:    ' . $now . ' (' . date_default_timezone_get() . ")\n";
            $head .= '-- Created by: ' . ($_SESSION['username'] ?? '?') . "\n";
            $head .= '-- Database:   ' . DB_NAME . "\n";
            $head .= '-- Contents:   ' . $scope . "\n";
            $head .= "--\n-- Rows backed up:\n";
            foreach ($counts as $t => $n) {
                $head .= '--   ' . str_pad($t, 16) . ($n === null ? 'skipped (table missing)' : $n) . "\n";
            }
            $head .= "--\n-- Restore: phpMyAdmin > select the database > Import, or\n";
            $head .= "--   mysql -u root " . DB_NAME . " < this-file.sql\n";
            $head .= "-- Safe to import: existing rows are never deleted or overwritten.\n";
            $head .= "-- This file contains account password hashes - keep it private.\n";
            $head .= "-- ==========================================================\n\n";
            $head .= "SET NAMES utf8mb4;\n";
            $head .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
            $head .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

            $foot = "SET FOREIGN_KEY_CHECKS = 1;\n\n-- End of backup (if you can read this line, the file is complete).\n";

            $stamp = date('Y-m-d_His');
            $filename = $mode === 'range'
                ? "candace_backup_{$from}_to_{$to}_made-{$stamp}.sql"
                : "candace_backup_full_{$stamp}.sql";

            $size = strlen($head) + fstat($body)['size'] + strlen($foot);

            // Drop anything already buffered so the file isn't polluted.
            while (ob_get_level() > 0) ob_end_clean();

            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store');

            echo $head;
            rewind($body);
            fpassthru($body);
            echo $foot;
            fclose($body);
            exit;
        }
    }
}

// ======================================================================
// Page
// ======================================================================
// Current record counts for this store, so the owner can see what a full
// backup covers.
$overview = [];
foreach (backup_table_plan() as $table => $spec) {
    try {
        [$where, $params] = backup_where($spec, $store_id, null, null);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        $overview[$table] = ['label' => $spec['label'], 'count' => (int) $stmt->fetchColumn(), 'dated' => $spec['range'] !== null || in_array($table, ['sale_items', 'shipment_items'], true)];
    } catch (PDOException $e) {
        $overview[$table] = ['label' => $spec['label'], 'count' => null, 'dated' => false];
    }
}

$page_title = 'Backup';
$active_nav = 'backup';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Store</p>
        <h1>Backup</h1>
        <p>Download a copy of your store's data - sales, expenses, shipments, product inventory, stock history, accounts and settings.</p>
    </div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="flash error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="grid grid-2" style="align-items:start;">
    <div class="card">
        <div class="card-title">Create a backup</div>
        <form method="post" id="backup-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <div class="field" style="margin-bottom:14px;">
                <label style="display:flex; gap:8px; align-items:center; cursor:pointer; font-size:14px;">
                    <input type="radio" name="mode" value="full" <?= $mode === 'full' ? 'checked' : '' ?>>
                    <span><strong>Everything</strong> &mdash; all dates</span>
                </label>
                <label style="display:flex; gap:8px; align-items:center; cursor:pointer; font-size:14px; margin-top:8px;">
                    <input type="radio" name="mode" value="range" <?= $mode === 'range' ? 'checked' : '' ?>>
                    <span><strong>Specific date range</strong></span>
                </label>
            </div>

            <div id="range-fields" style="display:none; padding:14px; border:1px solid var(--line); border-radius:var(--radius); margin-bottom:14px;">
                <div class="form-grid">
                    <div class="field">
                        <label for="from">From</label>
                        <input type="date" id="from" name="from" value="<?= h($from) ?>">
                    </div>
                    <div class="field">
                        <label for="to">To</label>
                        <input type="date" id="to" name="to" value="<?= h($to) ?>">
                    </div>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:10px;">
                    <button type="button" class="btn-ghost btn btn-small preset" data-preset="today" style="width:auto;">Today</button>
                    <button type="button" class="btn-ghost btn btn-small preset" data-preset="7d" style="width:auto;">Last 7 days</button>
                    <button type="button" class="btn-ghost btn btn-small preset" data-preset="month" style="width:auto;">This month</button>
                    <button type="button" class="btn-ghost btn btn-small preset" data-preset="lastmonth" style="width:auto;">Last month</button>
                    <button type="button" class="btn-ghost btn btn-small preset" data-preset="year" style="width:auto;">This year</button>
                </div>
                <p class="helper-text" style="margin-top:10px;">
                    Sales, expenses, shipments and stock history are limited to these dates.
                    Products (with current stock), categories, accounts and settings are always included in full.
                </p>
            </div>

            <div class="form-actions" style="gap:10px; flex-wrap:wrap;">
                <button type="submit" name="format" value="sql" class="btn">Download backup (.sql)</button>
                <button type="submit" name="format" value="xlsx" class="btn-ghost btn">Download Excel (.xlsx)</button>
            </div>
        </form>

        <p class="helper-text" style="margin-top:16px;">
            <strong>.sql</strong> is the full backup that can restore the system: open phpMyAdmin, pick the database,
            and use <strong>Import</strong>. Restoring never deletes or overwrites existing records &mdash;
            it only adds back anything that's missing. It includes staff password hashes, so keep it somewhere safe
            (a USB drive, not just this computer).
        </p>
        <p class="helper-text" style="margin-top:10px;">
            <strong>Excel</strong> is a readable copy of the money and stock side &mdash; a summary with month-by-month
            sales and expenses, products and stock, sales, receipts and their items, expenses, shipments and stock history.
            It's for reviewing, printing or sharing with an accountant; it can't restore the system and has no passwords.
        </p>
    </div>

    <div class="card">
        <div class="card-title">What's in a backup</div>
        <table>
            <thead><tr><th>Data</th><th class="amount">Records now</th><th>Date range?</th></tr></thead>
            <tbody>
            <?php foreach ($overview as $row): ?>
                <tr>
                    <td><?= h($row['label']) ?></td>
                    <td class="amount"><?= $row['count'] === null ? '&mdash;' : (int) $row['count'] ?></td>
                    <td><?= $row['dated'] ? 'Yes' : 'Always full' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('backup-form');
    const rangeFields = document.getElementById('range-fields');
    const from = document.getElementById('from');
    const to = document.getElementById('to');

    function syncMode() {
        const isRange = form.querySelector('input[name="mode"]:checked').value === 'range';
        rangeFields.style.display = isRange ? '' : 'none';
        from.required = to.required = isRange;
    }
    form.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', syncMode));
    syncMode();

    const iso = d => {
        const p = n => String(n).padStart(2, '0');
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    };
    document.querySelectorAll('.preset').forEach(btn => btn.addEventListener('click', () => {
        const now = new Date();
        let a = new Date(now), b = new Date(now);
        switch (btn.dataset.preset) {
            case '7d':        a.setDate(a.getDate() - 6); break;
            case 'month':     a = new Date(now.getFullYear(), now.getMonth(), 1); break;
            case 'lastmonth': a = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                              b = new Date(now.getFullYear(), now.getMonth(), 0); break;
            case 'year':      a = new Date(now.getFullYear(), 0, 1); break;
        }
        from.value = iso(a);
        to.value = iso(b);
    }));
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>