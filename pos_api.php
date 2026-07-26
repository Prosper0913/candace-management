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
$user_id = current_user_id();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ---- Look up a scanned barcode (read-only, no CSRF needed) --------------
if ($action === 'lookup') {
    $barcode = trim($_GET['barcode'] ?? '');
    if ($barcode === '') {
        echo json_encode(['ok' => false, 'error' => 'No barcode given.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, barcode, name, price FROM products WHERE user_id = ? AND barcode = ?');
    $stmt->execute([$user_id, $barcode]);
    $product = $stmt->fetch();

    echo json_encode($product
        ? ['ok' => true, 'found' => true, 'product' => [
            'id' => (int) $product['id'],
            'barcode' => $product['barcode'],
            'name' => $product['name'],
            'price' => (float) $product['price'],
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

    if ($barcode === '' || $name === '' || !is_numeric($price) || (float) $price <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Please provide a valid barcode, name, and price.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO products (user_id, barcode, name, price) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user_id, $barcode, $name, $price]);
        echo json_encode(['ok' => true, 'product' => [
            'id' => (int) $pdo->lastInsertId(),
            'barcode' => $barcode,
            'name' => $name,
            'price' => (float) $price,
        ]]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'That barcode is already registered.']);
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

    $total = 0.0;
    $item_count = 0;
    $clean_items = [];
    foreach ($cart as $line) {
        $barcode = trim($line['barcode'] ?? '');
        $name = trim($line['name'] ?? '');
        $unit_price = (float) ($line['price'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $product_id = isset($line['product_id']) ? (int) $line['product_id'] : null;

        if ($barcode === '' || $name === '' || $unit_price <= 0 || $qty <= 0) {
            continue; // skip malformed rows rather than failing the whole sale
        }
        $line_total = round($unit_price * $qty, 2);
        $total += $line_total;
        $item_count += $qty;
        $clean_items[] = [$product_id, $barcode, $name, $unit_price, $qty, $line_total];
    }

    if (!$clean_items) {
        echo json_encode(['ok' => false, 'error' => 'Cart had no valid items.']);
        exit;
    }
    $total = round($total, 2);

    try {
        $pdo->beginTransaction();

        // Log the sale total in the existing income ledger, so the dashboard,
        // "Sales" list, and Reports page all pick it up automatically.
        $stmt = $pdo->prepare(
            'INSERT INTO income (user_id, source, amount, income_date, notes) VALUES (?, ?, ?, CURDATE(), ?)'
        );
        $stmt->execute([$user_id, 'POS Sale', $total, $item_count . ' item(s) scanned at checkout']);
        $income_id = (int) $pdo->lastInsertId();

        // Sale header
        $stmt = $pdo->prepare(
            'INSERT INTO sales (user_id, income_id, total_amount, item_count, sale_date) VALUES (?, ?, ?, ?, CURDATE())'
        );
        $stmt->execute([$user_id, $income_id, $total, $item_count]);
        $sale_id = (int) $pdo->lastInsertId();

        // Line items
        $stmt = $pdo->prepare(
            'INSERT INTO sale_items (sale_id, product_id, barcode, name, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($clean_items as [$product_id, $barcode, $name, $unit_price, $qty, $line_total]) {
            $stmt->execute([$sale_id, $product_id, $barcode, $name, $unit_price, $qty, $line_total]);
        }

        $pdo->commit();

        // The sale is safely saved either way - printing is "best effort" on
        // top of that, so a printer hiccup never loses or blocks a sale.
        $sale_row = ['id' => $sale_id, 'total_amount' => $total, 'created_at' => date('Y-m-d H:i:s')];
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
        $pdo->rollBack();
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