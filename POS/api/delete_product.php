<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['pos_cashier'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../Landing Page/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$id    = (int)($input['id'] ?? 0);

if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

$branch   = $_SESSION['pos_cashier_branch'] ?? '';
$userId   = (int)($_SESSION['user_id']   ?? 0);
$userName = $_SESSION['user_name'] ?? '';

// Fetch name/sku before deleting for the audit log
$fetch = $conn->prepare("SELECT name, sku FROM pos_products WHERE id = ? AND branch = ?");
$fetch->bind_param('is', $id, $branch);
$fetch->execute();
$product = $fetch->get_result()->fetch_assoc();
$fetch->close();

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found or does not belong to your branch.']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM pos_products WHERE id = ? AND branch = ?");
$stmt->bind_param('is', $id, $branch);
$stmt->execute();
$deleted = $stmt->affected_rows > 0;
$stmt->close();

if ($deleted) {
    $details = "SKU: {$product['sku']}";
    $audit   = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
         VALUES (?, ?, ?, 'DELETE_PRODUCT', 'product', ?, ?, ?)"
    );
    if ($audit) {
        $audit->bind_param('issiis', $userId, $userName, $branch, $id, $product['name'], $details);
        $audit->execute();
        $audit->close();
    }
}

echo json_encode(['success' => $deleted]);
