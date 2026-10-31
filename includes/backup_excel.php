<?php
/**
 * Candace Management System - Excel version of the backup.
 *
 * A readable workbook of everything financial plus inventory and shipments:
 *   Summary, Products, Sales, Receipts, Receipt Items, Expenses,
 *   Shipments, Shipment Items, Stock History.
 * It is a report/copy for people (Excel, Google Sheets, printing) - the .sql
 * backup is still the one to use for restoring the system.
 *
 * Same scoping rules as the .sql backup: only this store's data, and in
 * date-range mode only the dated records are limited (products are always
 * the full current list).
 */

require_once __DIR__ . '/xlsx_writer.php';

/**
 * @param string[] $meta  keys: store, created, created_by, contents
 */
function backup_build_xlsx(PDO $pdo, int $store_id, ?string $from, ?string $to, string $path, array $meta): void
{
    $isRange = $from !== null && $to !== null;
    $rng = fn(string $col) => $isRange ? " AND {$col} BETWEEN ? AND ?" : '';
    $rngParams = $isRange ? [$from, $to] : [];

    $wb = new XlsxWorkbook();
    // Sheet order = tab order. Summary is created first but filled in last,
    // because its formulas need to know how many rows the other sheets have.
    $summary   = $wb->addSheet('Summary', [36, 20, 20, 20], false);
    $products  = $wb->addSheet('Products', [18, 34, 18, 14, 14, 14, 16, 14]);
    $sales     = $wb->addSheet('Sales', [14, 36, 8, 36, 16]);
    $receipts  = $wb->addSheet('Receipts', [11, 14, 18, 8, 14, 9, 14, 14, 14, 14]);
    $rItems    = $wb->addSheet('Receipt Items', [11, 14, 18, 34, 14, 8, 14]);
    $expenses  = $wb->addSheet('Expenses', [14, 38, 20, 16, 36]);
    $shipments = $wb->addSheet('Shipments', [11, 28, 14, 12, 8, 16, 32, 36, 18]);
    $sItems    = $wb->addSheet('Shipment Items', [11, 26, 14, 12, 18, 32, 8, 14, 14]);
    $stockHist = $wb->addSheet('Stock History', [18, 32, 18, 12, 12, 36, 22]);

    /**
     * Runs one query and writes a row per result via $map. A table that
     * doesn't exist (an older database) just leaves a note on that sheet.
     */
    $fill = function (XlsxSheet $sheet, string $sql, array $params, callable $map) use ($pdo): void {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $sheet->row($map($r, $sheet->nextRow()));
            }
            $stmt->closeCursor();
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
            $sheet->row([Xlsx::text('(this table does not exist in the database yet)')]);
        }
    };

    $T = fn($v) => Xlsx::text($v);

    // ---- Products (always the full current list) ------------------------
    $products->header(['Barcode', 'Product', 'Category', 'Price', 'Stock on hand', 'Low-stock level', 'Stock value', $isRange ? 'Units sold (in range)' : 'Units sold']);
    $inventory_value = 0.0;
    $units_in_stock = 0;
    $fill($products,
        'SELECT p.barcode, p.name, p.category, p.price, p.stock_quantity, p.low_stock_threshold,
                COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                          WHERE si.product_id = p.id AND s.user_id = ?' . $rng('s.sale_date') . '), 0) AS units_sold
         FROM products p WHERE p.user_id = ? ORDER BY p.name',
        array_merge([$store_id], $rngParams, [$store_id]),
        function ($r, $row) use ($T, &$inventory_value, &$units_in_stock) {
            $value = (float) $r['price'] * (int) $r['stock_quantity'];
            $inventory_value += $value;
            $units_in_stock += (int) $r['stock_quantity'];
            return [
                $T($r['barcode']), $T($r['name']), $T($r['category']),
                Xlsx::num($r['price'], Xlsx::S_PESO),
                Xlsx::num($r['stock_quantity'], Xlsx::S_INT),
                Xlsx::num($r['low_stock_threshold'], Xlsx::S_INT),
                Xlsx::formula("D{$row}*E{$row}", $value, Xlsx::S_PESO),
                Xlsx::num($r['units_sold'], Xlsx::S_INT),
            ];
        });

    // ---- Sales (the sales ledger: what the Sales page and dashboard use) --
    $sales->header(['Date', 'Description', 'Qty', 'Notes', 'Amount']);
    $total_sales = 0.0;
    $sales_by_month = [];
    $min_date = $max_date = null;
    $track = function (string $ymd) use (&$min_date, &$max_date) {
        if ($min_date === null || $ymd < $min_date) $min_date = $ymd;
        if ($max_date === null || $ymd > $max_date) $max_date = $ymd;
    };
    $fill($sales,
        'SELECT income_date, source, quantity, notes, amount FROM income
         WHERE user_id = ?' . $rng('income_date') . ' ORDER BY income_date, id',
        array_merge([$store_id], $rngParams),
        function ($r) use ($T, &$total_sales, &$sales_by_month, $track) {
            $total_sales += (float) $r['amount'];
            $ym = substr($r['income_date'], 0, 7);
            $sales_by_month[$ym] = ($sales_by_month[$ym] ?? 0.0) + (float) $r['amount'];
            $track($r['income_date']);
            return [Xlsx::date($r['income_date']), $T($r['source']), Xlsx::num($r['quantity'], Xlsx::S_INT), $T($r['notes']), Xlsx::num($r['amount'], Xlsx::S_PESO)];
        });

    // ---- Receipts (one row per sale, with VAT and cash/change) ----------
    $receipts->header(['Receipt #', 'Date', 'Time', 'Items', 'Subtotal', 'VAT %', 'VAT amount', 'Total', 'Cash received', 'Change due']);
    $receipt_count = 0;
    $vat_collected = 0.0;
    $fill($receipts,
        'SELECT id, sale_date, created_at, item_count, subtotal, vat_percent, vat_amount, total_amount, cash_received, change_due
         FROM sales WHERE user_id = ?' . $rng('sale_date') . ' ORDER BY id',
        array_merge([$store_id], $rngParams),
        function ($r) use (&$receipt_count, &$vat_collected) {
            $receipt_count++;
            $vat_collected += (float) ($r['vat_amount'] ?? 0);
            return [
                Xlsx::num($r['id'], Xlsx::S_ID), Xlsx::date($r['sale_date']), Xlsx::datetime($r['created_at']),
                Xlsx::num($r['item_count'], Xlsx::S_INT), Xlsx::num($r['subtotal'], Xlsx::S_PESO),
                Xlsx::num($r['vat_percent'], Xlsx::S_PCT), Xlsx::num($r['vat_amount'], Xlsx::S_PESO),
                Xlsx::num($r['total_amount'], Xlsx::S_PESO), Xlsx::num($r['cash_received'], Xlsx::S_PESO),
                Xlsx::num($r['change_due'], Xlsx::S_PESO),
            ];
        });

    // ---- Receipt items (every line sold) --------------------------------
    $rItems->header(['Receipt #', 'Date', 'Barcode', 'Product', 'Unit price', 'Qty', 'Line total']);
    $fill($rItems,
        'SELECT si.sale_id, s.sale_date, si.barcode, si.name, si.unit_price, si.quantity, si.line_total
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.user_id = ?' . $rng('s.sale_date') . ' ORDER BY si.sale_id, si.id',
        array_merge([$store_id], $rngParams),
        fn($r) => [
            Xlsx::num($r['sale_id'], Xlsx::S_ID), Xlsx::date($r['sale_date']), $T($r['barcode']), $T($r['name']),
            Xlsx::num($r['unit_price'], Xlsx::S_PESO), Xlsx::num($r['quantity'], Xlsx::S_INT), Xlsx::num($r['line_total'], Xlsx::S_PESO),
        ]);

    // ---- Expenses -------------------------------------------------------
    $expenses->header(['Date', 'Description', 'Category', 'Amount', 'Notes']);
    $total_expenses = 0.0;
    $expenses_by_month = [];
    $fill($expenses,
        'SELECT e.expense_date, e.title, c.name AS category_name, e.amount, e.notes
         FROM expenses e LEFT JOIN categories c ON c.id = e.category_id
         WHERE e.user_id = ?' . $rng('e.expense_date') . ' ORDER BY e.expense_date, e.id',
        array_merge([$store_id], $rngParams),
        function ($r) use ($T, &$total_expenses, &$expenses_by_month, $track) {
            $total_expenses += (float) $r['amount'];
            $ym = substr($r['expense_date'], 0, 7);
            $expenses_by_month[$ym] = ($expenses_by_month[$ym] ?? 0.0) + (float) $r['amount'];
            $track($r['expense_date']);
            return [Xlsx::date($r['expense_date']), $T($r['title']), $T($r['category_name'] ?? 'Uncategorized'), Xlsx::num($r['amount'], Xlsx::S_PESO), $T($r['notes'])];
        });

    // ---- Shipments (by expected delivery date) --------------------------
    $shipments->header(['Shipment #', 'Supplier', 'Expected date', 'Status', 'Items', 'Total cost', 'Notes', 'Supplier address', 'Scheduled on']);
    $ship_pending = 0.0;
    $ship_received = 0.0;
    $fill($shipments,
        'SELECT s.id, s.supplier, s.expected_date, s.status, s.supplier_address, s.notes, s.created_at,
                COUNT(si.id) AS item_count, COALESCE(SUM(si.line_total), 0) AS total_cost
         FROM shipments s LEFT JOIN shipment_items si ON si.shipment_id = s.id
         WHERE s.user_id = ?' . $rng('s.expected_date') . ' GROUP BY s.id ORDER BY s.expected_date, s.id',
        array_merge([$store_id], $rngParams),
        function ($r) use ($T, &$ship_pending, &$ship_received) {
            if ($r['status'] === 'pending') $ship_pending += (float) $r['total_cost'];
            if ($r['status'] === 'received') $ship_received += (float) $r['total_cost'];
            return [
                Xlsx::num($r['id'], Xlsx::S_ID), $T($r['supplier']), Xlsx::date($r['expected_date']), $T(ucfirst($r['status'])),
                Xlsx::num($r['item_count'], Xlsx::S_INT), Xlsx::num($r['total_cost'], Xlsx::S_PESO),
                $T($r['notes']), $T($r['supplier_address']), Xlsx::datetime($r['created_at']),
            ];
        });

    // ---- Shipment items -------------------------------------------------
    $sItems->header(['Shipment #', 'Supplier', 'Expected date', 'Status', 'Barcode', 'Product', 'Qty', 'Unit cost', 'Line total']);
    $fill($sItems,
        'SELECT si.shipment_id, s.supplier, s.expected_date, s.status, si.barcode, si.name, si.quantity, si.unit_cost, si.line_total
         FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
         WHERE s.user_id = ?' . $rng('s.expected_date') . ' ORDER BY s.expected_date, si.shipment_id, si.id',
        array_merge([$store_id], $rngParams),
        fn($r) => [
            Xlsx::num($r['shipment_id'], Xlsx::S_ID), $T($r['supplier']), Xlsx::date($r['expected_date']), $T(ucfirst($r['status'])),
            $T($r['barcode']), $T($r['name']), Xlsx::num($r['quantity'], Xlsx::S_INT),
            Xlsx::num($r['unit_cost'], Xlsx::S_PESO), Xlsx::num($r['line_total'], Xlsx::S_PESO),
        ]);

    // ---- Stock history --------------------------------------------------
    $stockHist->header(['When', 'Product', 'Barcode', 'Qty added', 'Source', 'Reference', 'Done by']);
    $fill($stockHist,
        'SELECT sh.created_at, p.name, p.barcode, sh.quantity_added, sh.source, sh.reference, u.full_name
         FROM stock_history sh
         JOIN products p ON p.id = sh.product_id
         LEFT JOIN users u ON u.id = sh.performed_by
         WHERE sh.user_id = ?' . $rng('DATE(sh.created_at)') . ' ORDER BY sh.created_at, sh.id',
        array_merge([$store_id], $rngParams),
        fn($r) => [
            Xlsx::datetime($r['created_at']), $T($r['name']), $T($r['barcode']), Xlsx::num($r['quantity_added'], Xlsx::S_INT),
            $T(ucfirst($r['source'])), $T($r['reference']), $T($r['full_name']),
        ]);

    // ---- Summary (live formulas over the sheets above) ------------------
    $last = fn(XlsxSheet $s) => max($s->lastRow(), 2); // keep ranges valid even when a sheet has no data
    $ref = fn(string $sheet, string $col, XlsxSheet $s) => "'{$sheet}'!\${$col}\$2:\${$col}\${$last($s)}";

    $summary->row([Xlsx::text(($meta['store'] ?? 'Store') . ' - financial & inventory report', Xlsx::S_TITLE)]);
    $summary->row([Xlsx::text('Created', Xlsx::S_BOLD), Xlsx::text($meta['created'] ?? '')]);
    $summary->row([Xlsx::text('Created by', Xlsx::S_BOLD), Xlsx::text($meta['created_by'] ?? '')]);
    $summary->row([Xlsx::text('Contents', Xlsx::S_BOLD), Xlsx::text($meta['contents'] ?? '')]);
    $summary->row([]);
    $summary->header(['Key figures', 'Amount']);

    $firstFig = $summary->nextRow();
    $summary->row([Xlsx::text('Total sales (incl. VAT)'), Xlsx::formula('SUM(' . $ref('Sales', 'E', $sales) . ')', $total_sales, Xlsx::S_PESO_BOLD)]);
    $summary->row([Xlsx::text('Total expenses'), Xlsx::formula('SUM(' . $ref('Expenses', 'D', $expenses) . ')', $total_expenses, Xlsx::S_PESO_BOLD)]);
    $salesRow = $firstFig; $expRow = $firstFig + 1;
    $summary->row([Xlsx::text('Net (sales minus expenses)', Xlsx::S_BOLD), Xlsx::formula("B{$salesRow}-B{$expRow}", $total_sales - $total_expenses, Xlsx::S_PESO_BOLD)]);
    $summary->row([Xlsx::text('VAT collected (included in sales)'), Xlsx::formula('SUM(' . $ref('Receipts', 'G', $receipts) . ')', $vat_collected, Xlsx::S_PESO_BOLD)]);
    $summary->row([Xlsx::text('Number of receipts'), Xlsx::formula('COUNT(' . $ref('Receipts', 'A', $receipts) . ')', $receipt_count, Xlsx::S_INT)]);
    $summary->row([Xlsx::text('Inventory value (stock x selling price)'), Xlsx::formula('SUM(' . $ref('Products', 'G', $products) . ')', $inventory_value, Xlsx::S_PESO_BOLD)]);
    $summary->row([Xlsx::text('Units in stock'), Xlsx::formula('SUM(' . $ref('Products', 'E', $products) . ')', $units_in_stock, Xlsx::S_INT)]);
    $summary->row([Xlsx::text('Shipments still pending (cost)'), Xlsx::formula('SUMIFS(' . $ref('Shipments', 'F', $shipments) . ',' . $ref('Shipments', 'D', $shipments) . ',"Pending")', $ship_pending, Xlsx::S_PESO_BOLD)]);
    $summary->row([Xlsx::text('Shipments received (cost)'), Xlsx::formula('SUMIFS(' . $ref('Shipments', 'F', $shipments) . ',' . $ref('Shipments', 'D', $shipments) . ',"Received")', $ship_received, Xlsx::S_PESO_BOLD)]);

    // Month by month
    if ($min_date !== null) {
        $summary->row([]);
        $summary->header(['Month', 'Sales', 'Expenses', 'Net']);
        $cursor = new DateTimeImmutable(substr($min_date, 0, 7) . '-01 00:00:00', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable(substr($max_date, 0, 7) . '-01 00:00:00', new DateTimeZone('UTC'));
        $guard = 0;
        while ($cursor <= $end && $guard++ < 240) {
            $ym = $cursor->format('Y-m');
            $row = $summary->nextRow();
            $cond = fn(string $dateRef) => "{$dateRef},\">=\"&\$A{$row},{$dateRef},\"<\"&DATE(YEAR(\$A{$row}),MONTH(\$A{$row})+1,1)";
            $s = $sales_by_month[$ym] ?? 0.0;
            $e = $expenses_by_month[$ym] ?? 0.0;
            $summary->row([
                Xlsx::date($cursor->format('Y-m-d'), Xlsx::S_MONTH),
                Xlsx::formula('SUMIFS(' . $ref('Sales', 'E', $sales) . ',' . $cond($ref('Sales', 'A', $sales)) . ')', $s, Xlsx::S_PESO),
                Xlsx::formula('SUMIFS(' . $ref('Expenses', 'D', $expenses) . ',' . $cond($ref('Expenses', 'A', $expenses)) . ')', $e, Xlsx::S_PESO),
                Xlsx::formula("B{$row}-C{$row}", $s - $e, Xlsx::S_PESO),
            ]);
            $cursor = $cursor->modify('+1 month');
        }
    }

    $summary->row([]);
    $summary->row([Xlsx::text('Notes', Xlsx::S_BOLD)]);
    $summary->row([Xlsx::text('Sales amounts are as recorded at checkout, so they already include any VAT that was charged.')]);
    $summary->row([Xlsx::text('Inventory value uses the current selling price, not what the stock cost to buy.')]);
    $summary->row([Xlsx::text('The totals above are formulas - edit or filter the other sheets and they update.')]);
    $summary->row([Xlsx::text('This workbook is for reading and reporting. To restore the system, use the .sql backup.')]);

    $wb->save($path);
}