<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['pos_cashier'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../Landing Page/db.php';

$branch = $_SESSION['pos_cashier_branch'] ?? '';

$stmt = $conn->prepare(
    "SELECT id, sku, name, category, price, stock
     FROM pos_products
     WHERE branch = ?
     ORDER BY category, name"
);
$stmt->bind_param('s', $branch);
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
    exit;
}

$products = [];
while ($row = $result->fetch_assoc()) {
    $products[] = [
        'id'       => (int)$row['id'],
        'sku'      => $row['sku'],
        'name'     => $row['name'],
        'category' => $row['category'],
        'price'    => (float)$row['price'],
        'stock'    => (int)$row['stock'],
    ];
}

echo json_encode(['success' => true, 'products' => $products]);
