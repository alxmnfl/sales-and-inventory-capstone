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

if (!$input || empty($input['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

function generateTxId() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len   = strlen($chars);
    $id    = 'SAL-';
    for ($i = 0; $i < 8; $i++) $id .= $chars[random_int(0, $len - 1)];
    $id .= '-';
    for ($i = 0; $i < 3; $i++) $id .= $chars[random_int(0, $len - 1)];
    return $id;
}

$conn->begin_transaction();

try {
    $txId          = generateTxId();
    $cashier       = trim($input['cashier']);
    $paymentMethod = trim($input['payment_method']);
    $subtotal      = round((float)$input['subtotal'], 2);
    $vat           = round((float)$input['vat'],      2);
    $total         = round((float)$input['total'],    2);
    $cashTendered  = isset($input['cash_tendered']) && $input['cash_tendered'] !== null
                     ? (float)$input['cash_tendered']
                     : null;
    $createdAt     = date('Y-m-d H:i:s');
    $branch        = $_SESSION['pos_cashier_branch'] ?? '';

    // Insert sale record
    $stmtSale = $conn->prepare(
        "INSERT INTO pos_sales
         (transaction_id, cashier, payment_method, subtotal, vat, total, cash_tendered, branch, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtSale->bind_param(
        'sssddddss',
        $txId, $cashier, $paymentMethod, $subtotal, $vat, $total, $cashTendered, $branch, $createdAt
    );
    $stmtSale->execute();
    $saleId = $conn->insert_id;
    $stmtSale->close();

    $responseItems = [];

    foreach ($input['items'] as $item) {
        $productId   = (int)$item['product_id'];
        $qty         = (int)$item['quantity'];
        $unitPrice   = (float)$item['unit_price'];
        $totalPrice  = round($unitPrice * $qty, 2);
        $productName = trim($item['product_name']);
        $sku         = trim($item['sku']);

        $stockRes = $conn->query(
            "SELECT stock FROM pos_products WHERE id = $productId FOR UPDATE"
        );
        if (!$stockRes) throw new Exception("Stock check failed for product $productId.");

        $stockRow = $stockRes->fetch_assoc();
        if (!$stockRow)                   throw new Exception("Product $productId not found.");
        if ((int)$stockRow['stock'] < $qty) throw new Exception("Insufficient stock for \"$productName\".");

        $stmtItem = $conn->prepare(
            "INSERT INTO pos_sale_items
             (sale_id, product_id, product_name, sku, quantity, unit_price, total_price)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtItem->bind_param('iissidd', $saleId, $productId, $productName, $sku, $qty, $unitPrice, $totalPrice);
        $stmtItem->execute();
        $stmtItem->close();

        $conn->query("UPDATE pos_products SET stock = stock - $qty WHERE id = $productId");

        $responseItems[] = [
            'product_name' => $productName,
            'quantity'     => $qty,
            'unit_price'   => $unitPrice,
            'total_price'  => $totalPrice,
        ];
    }

    $conn->commit();

    // Audit log for completed sale
    $userId      = (int)($_SESSION['user_id']   ?? 0);
    $userName    = $_SESSION['user_name'] ?? $cashier;
    $itemCount   = count($input['items']);
    $auditDetail = "Total: ₱" . number_format($total, 2) . " | Method: $paymentMethod | Items: $itemCount";
    $auditStmt   = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
         VALUES (?, ?, ?, 'COMPLETE_SALE', 'sale', ?, ?, ?)"
    );
    if ($auditStmt) {
        $auditStmt->bind_param('issiis', $userId, $userName, $branch, $saleId, $txId, $auditDetail);
        $auditStmt->execute();
        $auditStmt->close();
    }

    echo json_encode([
        'success'        => true,
        'transaction_id' => $txId,
        'cashier'        => $cashier,
        'subtotal'       => $subtotal,
        'vat'            => $vat,
        'total'          => $total,
        'items'          => $responseItems,
        'created_at'     => $createdAt,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
