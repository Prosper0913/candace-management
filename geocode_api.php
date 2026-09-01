<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/geocoding.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'search') {
    $query = trim($_GET['q'] ?? '');
    $result = search_places($query, 5);
    echo json_encode(['ok' => $result['error'] === null, 'results' => $result['results'], 'error' => $result['error']]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);