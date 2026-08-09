<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['pos_cashier'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../Landing Page/php/db.php';

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$productId = (int)($input['product_id'] ?? 0);
$newStock  = $input['new_stock'] ?? null;

if ($productId <= 0 || !is_numeric($newStock) || (int)$newStock < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid product or stock value.']);
    exit;
}
$newStock = (int)$newStock;

$stmt = $conn->prepare('SELECT name, sku, branch, stock FROM pos_products WHERE id = ?');
$stmt->bind_param('i', $productId);
$stmt->execute();
$stmt->bind_result($name, $sku, $branch, $oldStock);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

$stmt = $conn->prepare('UPDATE pos_products SET stock = ? WHERE id = ?');
$stmt->bind_param('ii', $newStock, $productId);
$stmt->execute();
$stmt->close();

$userId      = (int)($_SESSION['user_id'] ?? 0);
$userName    = $_SESSION['pos_cashier'] ?? 'Staff';
$auditBranch = $branch !== '' ? $branch : ($_SESSION['pos_cashier_branch'] ?? '');
$delta       = $newStock - (int)$oldStock;
$sign        = $delta >= 0 ? '+' : '';
$detail      = "SKU: $sku | Stock: $oldStock \xE2\x86\x92 $newStock ($sign$delta)";

$auditStmt = $conn->prepare(
    "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
     VALUES (?, ?, ?, 'ADJUST_STOCK', 'product', ?, ?, ?)"
);
$auditStmt->bind_param('ississ', $userId, $userName, $auditBranch, $productId, $name, $detail);
$auditStmt->execute();
$auditStmt->close();

echo json_encode(['success' => true, 'stock' => $newStock]);
