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
if (!$input) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit; }

$id       = (int)($input['id'] ?? 0);
$sku      = trim($input['sku']      ?? '');
$name     = trim($input['name']     ?? '');
$category = trim($input['category'] ?? '');
$price    = (float)($input['price'] ?? 0);
$stock    = (int)($input['stock']   ?? 0);

if (!$sku || !$name || !$category || $price <= 0) {
    echo json_encode(['success' => false, 'message' => 'All fields are required and price must be greater than 0.']);
    exit;
}

$branch   = $_SESSION['pos_cashier_branch'] ?? '';
$userId   = (int)($_SESSION['user_id']   ?? 0);
$userName = $_SESSION['user_name'] ?? '';

if ($id) {
    // Verify ownership and capture before-state for audit log
    $check = $conn->prepare("SELECT name, price, stock, branch FROM pos_products WHERE id = ?");
    $check->bind_param('i', $id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }
    if ($existing['branch'] !== $branch) {
        echo json_encode(['success' => false, 'message' => 'You can only edit products belonging to your branch.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE pos_products SET sku=?, name=?, category=?, price=?, stock=? WHERE id=? AND branch=?");
    $stmt->bind_param('sssdiis', $sku, $name, $category, $price, $stock, $id, $branch);

    $action  = 'EDIT_PRODUCT';
    $details = "SKU: $sku | Price: {$existing['price']} → $price | Stock: {$existing['stock']} → $stock";
} else {
    $stmt = $conn->prepare("INSERT INTO pos_products (sku, name, category, price, stock, branch, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssdisi', $sku, $name, $category, $price, $stock, $branch, $userId);

    $action  = 'ADD_PRODUCT';
    $details = "SKU: $sku | Price: $price | Stock: $stock";
}

if ($stmt->execute()) {
    $savedId = $id ?: $conn->insert_id;

    // Log to audit trail (silently skip if table doesn't exist yet)
    $audit = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
         VALUES (?, ?, ?, ?, 'product', ?, ?, ?)"
    );
    if ($audit) {
        $audit->bind_param('isssiis', $userId, $userName, $branch, $action, $savedId, $name, $details);
        $audit->execute();
        $audit->close();
    }

    echo json_encode(['success' => true, 'id' => $savedId]);
} else {
    $msg = strpos($stmt->error, 'Duplicate') !== false
        ? 'A product with that SKU already exists in your branch.'
        : 'Database error: ' . $stmt->error;
    echo json_encode(['success' => false, 'message' => $msg]);
}
$stmt->close();
