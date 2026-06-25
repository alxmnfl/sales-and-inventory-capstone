<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/db.php';

$branch    = trim($_GET['branch'] ?? '');
$threshold = min(100, max(1, (int)($_GET['threshold'] ?? 10)));

if ($branch !== '') {
    $stmt = $conn->prepare(
        "SELECT id, name, sku, category, branch, stock
         FROM pos_products
         WHERE stock < ? AND stock >= 0 AND branch = ?
         ORDER BY stock ASC"
    );
    $stmt->bind_param('is', $threshold, $branch);
} else {
    $stmt = $conn->prepare(
        "SELECT id, name, sku, category, branch, stock
         FROM pos_products
         WHERE stock < ? AND stock >= 0
         ORDER BY stock ASC"
    );
    $stmt->bind_param('i', $threshold);
}

$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id'       => (int)$row['id'],
        'name'     => $row['name'],
        'sku'      => $row['sku'],
        'category' => $row['category'],
        'branch'   => $row['branch'],
        'stock'    => (int)$row['stock'],
    ];
}

echo json_encode(['success' => true, 'items' => $items]);
