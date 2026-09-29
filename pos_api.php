<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/escpos.php';
require_once __DIR__ . '/includes/printer.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}
// store_scope_id(): an owner's own id, or - for a cashier - the owner they
// work for, so both share the same inventory and sales ledger.
$user_id = store_scope_id();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---- Look up a scanned barcode (read-only, no CSRF needed) --------------
if ($action === 'lookup') {
    $barcode = trim($_GET['barcode'] ?? '');
    if ($barcode === '') {
        echo json_encode(['ok' => false, 'error' => 'No barcode given.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, barcode, name, price, stock_quantity FROM products WHERE user_id = ? AND barcode = ?');
    $stmt->execute([$user_id, $barcode]);
    $product = $stmt->fetch();

    echo json_encode($product
        ? ['ok' => true, 'found' => true, 'product' => [
            'id' => (int) $product['id'],
            'barcode' => $product['barcode'],
            'name' => $product['name'],
            'price' => (float) $product['price'],
            'stock' => (int) $product['stock_quantity'],
        ]]
        : ['ok' => true, 'found' => false, 'barcode' => $barcode]
    );
    exit;
}

// Every action below this point changes data, so it needs a valid CSRF token
// and a JSON POST body.
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!csrf_verify($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh the page.']);
    exit;
}

// ---- Register a brand-new barcode on the fly ----------------------------
if ($action === 'register') {
    $barcode = trim($input['barcode'] ?? '');
    $name = trim($input['name'] ?? '');
    $price = $input['price'] ?? '';
    $stock = $input['stock_quantity'] ?? '';

    if ($barcode === '' || $name === '' || !is_numeric($price) || (float) $price <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please provide a valid barcode, name, and price.']);
        exit;
    }
    // A product being rung up right now has to exist in stock, so a starting
    // quantity of at least 1 is required (an item with 0 stock can't be sold).
    if (!is_numeric($stock) || (int) $stock < 1) {
        echo json_encode(['ok' => false, 'error' => 'Enter how many of this item are in stock (at least 1).']);
        exit;
    }
    $stock = (int) $stock;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO products (user_id, barcode, name, price, stock_quantity) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user_id, $barcode, $name, $price, $stock]);
        $product_id = (int) $pdo->lastInsertId();
        log_stock_addition($pdo, $user_id, $product_id, $stock, 'manual', 'Initial stock (registered at New Sale)');
        $pdo->commit();

        echo json_encode(['ok' => true, 'product' => [
            'id' => $product_id,
            'barcode' => $barcode,
            'name' => $name,
            'price' => (float) $price,
            'stock' => $stock,
        ]]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // 23000 = integrity constraint violation, i.e. the barcode already exists.
        $duplicate = $e->getCode() === '23000';
        echo json_encode(['ok' => false, 'error' => $duplicate ? 'That barcode is already registered.' : 'Could not register that product.']);
    }
    exit;
}

// ---- Checkout: turn the cart into a sale + income entry + receipt ------
if ($action === 'checkout') {
    $cart = $input['cart'] ?? [];
    if (!is_array($cart) || count($cart) === 0) {
        echo json_encode(['ok' => false, 'error' => 'Cart is empty.']);
        exit;
    }

    $cash_received = $input['cash_received'] ?? null;
    if (!is_numeric($cash_received) || (float) $cash_received < 0) {
        echo json_encode(['ok' => false, 'error' => 'Enter how much cash the buyer handed over.']);
        exit;
    }
    $cash_received = round((float) $cash_received, 2);

    // Merge the cart by product so a crafted request can't split one product
    // across several lines to slip past the stock check.
    $wanted = [];
    foreach ($cart as $line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $wanted[$pid] = ($wanted[$pid] ?? 0) + $qty;
    }
    if (!$wanted) {
        echo json_encode(['ok' => false, 'error' => 'Cart had no valid items.']);
        exit;
    }

    // Stops the sale, undoing anything started, and reports why.
    $fail = function (string $message) use ($pdo) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    };

    try {
        $pdo->beginTransaction();

        // Load (and lock) every product in this sale, so the stock we check is
        // the stock we then deduct - even if two sales happen at once. Price
        // and name come from the inventory itself, not from the browser.
        $placeholders = implode(',', array_fill(0, count($wanted), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, barcode, name, price, stock_quantity FROM products WHERE user_id = ? AND id IN ($placeholders) FOR UPDATE"
        );
        $stmt->execute(array_merge([$user_id], array_keys($wanted)));
        $products = [];
        foreach ($stmt->fetchAll() as $p) $products[(int) $p['id']] = $p;

        $total = 0.0;
        $item_count = 0;
        $clean_items = [];
        foreach ($wanted as $pid => $qty) {
            if (!isset($products[$pid])) {
                $fail('One of the items is no longer in your inventory. Refresh the page and try again.');
            }
            $p = $products[$pid];
            $stock = (int) $p['stock_quantity'];
            if ($stock <= 0) {
                $fail($p['name'] . ' is out of stock.');
            }
            if ($qty > $stock) {
                $fail('Not enough stock for ' . $p['name'] . ': only ' . $stock . ' left.');
            }
            $unit_price = (float) $p['price'];
            $line_total = round($unit_price * $qty, 2);
            $total += $line_total;
            $item_count += $qty;
            $clean_items[] = [$pid, $p['barcode'], $p['name'], $unit_price, $qty, $line_total];
        }
        $total = round($total, 2);

        if ($cash_received < $total) {
            $fail('Cash received is less than the total - collect the full amount first.');
        }
        $change_due = round($cash_received - $total, 2);

        // Log the sale total in the existing income ledger, so the dashboard,
        // "Sales" list, and Reports page all pick it up automatically.
        $stmt = $pdo->prepare(
            'INSERT INTO income (user_id, source, amount, income_date, notes) VALUES (?, ?, ?, CURDATE(), ?)'
        );
        $stmt->execute([$user_id, 'POS Sale', $total, $item_count . ' item(s) scanned at checkout']);
        $income_id = (int) $pdo->lastInsertId();

        // Sale header
        $stmt = $pdo->prepare(
            'INSERT INTO sales (user_id, income_id, total_amount, cash_received, change_due, item_count, sale_date)
             VALUES (?, ?, ?, ?, ?, ?, CURDATE())'
        );
        $stmt->execute([$user_id, $income_id, $total, $cash_received, $change_due, $item_count]);
        $sale_id = (int) $pdo->lastInsertId();

        // Line items + stock deduction
        $stmt = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, barcode, name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $decrement_stmt = $pdo->prepare(
            'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND user_id = ?'
        );
        foreach ($clean_items as [$pid, $barcode, $name, $unit_price, $qty, $line_total]) {
            $stmt->execute([$sale_id, $pid, $barcode, $name, $unit_price, $qty, $line_total]);
            $decrement_stmt->execute([$qty, $pid, $user_id]);
        }

        $pdo->commit();

        // The sale is safely saved either way - printing is "best effort" on
        // top of that, so a printer hiccup never loses or blocks a sale.
        $sale_row = [
            'id' => $sale_id, 'total_amount' => $total,
            'cash_received' => $cash_received, 'change_due' => $change_due,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $item_rows = array_map(fn($i) => [
            'name' => $i[2], 'unit_price' => $i[3], 'quantity' => $i[4], 'line_total' => $i[5],
        ], $clean_items);

        [$printed, $print_error] = send_to_printer(build_receipt_escpos($sale_row, $item_rows));

        echo json_encode([
            'ok' => true,
            'sale_id' => $sale_id,
            'printed' => $printed,
            'print_error' => $print_error,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Could not complete the sale. Please try again.']);
    }
    exit;
}

// ---- Reprint an existing sale on the thermal printer --------------------
if ($action === 'reprint') {
    $sale_id = (int) ($input['sale_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ? AND user_id = ?');
    $stmt->execute([$sale_id, $user_id]);
    $sale = $stmt->fetch();
    if (!$sale) {
        echo json_encode(['ok' => false, 'error' => 'Sale not found.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id');
    $stmt->execute([$sale_id]);
    $items = $stmt->fetchAll();

    [$printed, $print_error] = send_to_printer(build_receipt_escpos($sale, $items));
    echo json_encode(['ok' => $printed, 'error' => $print_error]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);