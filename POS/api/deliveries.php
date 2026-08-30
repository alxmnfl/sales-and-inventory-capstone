<?php
require_once '../../Landing Page/php/auth.php';

header('Content-Type: application/json');

if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'branch_staff') {
    $_SESSION['pos_cashier']        = strtoupper($_SESSION['user_name']);
    $_SESSION['pos_cashier_branch'] = strtoupper($_SESSION['user_branch'] ?? '');
}

if (!isset($_SESSION['pos_cashier'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../Landing Page/php/db.php';
require_once __DIR__ . '/../../Landing Page/php/delivery_schema.php';
ensure_delivery_schema($conn);

$branch   = strtoupper(trim($_SESSION['pos_cashier_branch'] ?? ''));
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['pos_cashier'] ?? 'Staff';

$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$action      = $input['action']      ?? '';
$deliveryId  = (int)($input['delivery_id'] ?? 0);

if ($branch === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Your account has no branch assigned.']);
    exit;
}
if ($deliveryId <= 0 || !in_array($action, ['complete', 'dispute'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bad request.']);
    exit;
}

/* ─────────────────────────── DISPUTE ─────────────────────────── */
if ($action === 'dispute') {
    $remarks = trim($input['remarks'] ?? '');
    if ($remarks === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Describe what is wrong with the delivery.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT reference, status FROM inventory_deliveries WHERE id = ? AND branch = ?");
    $stmt->bind_param('is', $deliveryId, $branch);
    $stmt->execute();
    $stmt->bind_result($reference, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Delivery not found for this branch.']);
        exit;
    }
    if ($status !== 'sent') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This delivery has already been actioned.']);
        exit;
    }

    $up = $conn->prepare(
        "UPDATE inventory_deliveries
         SET status = 'disputed', staff_remarks = ?, received_by = ?, received_by_name = ?, received_at = NOW()
         WHERE id = ? AND status = 'sent'"
    );
    $up->bind_param('sisi', $remarks, $userId, $userName, $deliveryId);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if (!$ok) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This delivery has already been actioned.']);
        exit;
    }

    $detail = "Ref: $reference | Reported by branch: $remarks";
    $a = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
         VALUES (?, ?, ?, 'DISPUTE_DELIVERY', 'delivery', ?, ?, ?)"
    );
    $a->bind_param('ississ', $userId, $userName, $branch, $deliveryId, $reference, $detail);
    $a->execute();
    $a->close();

    echo json_encode(['success' => true, 'status' => 'disputed', 'reference' => $reference]);
    exit;
}

/* ─────────────────────────── COMPLETE ─────────────────────────── */
$conn->begin_transaction();
try {
    // Lock the delivery header and re-check state
    $stmt = $conn->prepare(
        "SELECT reference, status FROM inventory_deliveries WHERE id = ? AND branch = ? FOR UPDATE"
    );
    $stmt->bind_param('is', $deliveryId, $branch);
    $stmt->execute();
    $stmt->bind_result($reference, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        throw new Exception('Delivery not found for this branch.', 404);
    }
    if ($status !== 'sent') {
        throw new Exception('This delivery has already been received or actioned.', 409);
    }

    // Load line items, ordered by SKU so concurrent operations lock rows in a
    // consistent order (avoids deadlocks).
    $items = [];
    $res = $conn->query("SELECT id, product_id, sku, name, category, qty_sent
                         FROM inventory_delivery_items WHERE delivery_id = $deliveryId ORDER BY sku");
    while ($row = $res->fetch_assoc()) $items[] = $row;

    if (!$items) {
        throw new Exception('This delivery has no line items.', 400);
    }

    $applied = [];

    $selProd = $conn->prepare("SELECT id, name, stock FROM pos_products WHERE sku = ? AND branch = ? FOR UPDATE");
    $updProd = $conn->prepare("UPDATE pos_products SET stock = stock + ? WHERE id = ?");
    $priceLk = $conn->prepare("SELECT COALESCE(MAX(price), 0) FROM pos_products WHERE sku = ?");
    $insProd = $conn->prepare(
        "INSERT INTO pos_products (sku, name, category, price, stock, branch, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $updItem = $conn->prepare("UPDATE inventory_delivery_items SET qty_received = ?, applied = 1 WHERE id = ?");
    $auditIt = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, batch_id, details)
         VALUES (?, ?, ?, 'RECEIVE_DELIVERY', 'product', ?, ?, ?, ?)"
    );

    foreach ($items as $it) {
        $sku  = $it['sku'];
        $qty  = (int)$it['qty_sent'];
        $name = $it['name'];
        $cat  = $it['category'] ?? '';

        $selProd->bind_param('ss', $sku, $branch);
        $selProd->execute();
        $selProd->bind_result($pid, $pname, $oldStock);
        $exists = $selProd->fetch();
        $selProd->free_result();

        if ($exists) {
            $oldStock = (int)$oldStock;
            $newStock = $oldStock + $qty;
            $updProd->bind_param('ii', $qty, $pid);
            $updProd->execute();
            $entityName = $pname;
        } else {
            // Product not stocked here yet — create it, carrying a price from
            // another branch if one exists.
            $priceLk->bind_param('s', $sku);
            $priceLk->execute();
            $priceLk->bind_result($price);
            $priceLk->fetch();
            $priceLk->free_result();
            $price = (float)$price;

            $zero = 0;
            $insProd->bind_param('sssdisi', $sku, $name, $cat, $price, $qty, $branch, $userId);
            $insProd->execute();
            $pid = $insProd->insert_id;
            $oldStock = 0;
            $newStock = $qty;
            $entityName = $name;
        }

        $updItem->bind_param('ii', $qty, $it['id']);
        $updItem->execute();

        $detail = "Ref: $reference | SKU: $sku | Stock: $oldStock \xE2\x86\x92 $newStock (+$qty) | Delivery receipt";
        $auditIt->bind_param('ississs', $userId, $userName, $branch, $pid, $entityName, $reference, $detail);
        $auditIt->execute();

        $applied[] = ['sku' => $sku, 'name' => $entityName, 'old_stock' => $oldStock, 'new_stock' => $newStock, 'added' => $qty];
    }

    $selProd->close(); $updProd->close(); $priceLk->close();
    $insProd->close(); $updItem->close(); $auditIt->close();

    $up = $conn->prepare(
        "UPDATE inventory_deliveries
         SET status = 'received', received_by = ?, received_by_name = ?, received_at = NOW()
         WHERE id = ? AND status = 'sent'"
    );
    $up->bind_param('isi', $userId, $userName, $deliveryId);
    $up->execute();
    if ($up->affected_rows < 1) { $up->close(); throw new Exception('This delivery has already been received.', 409); }
    $up->close();

    $units = array_sum(array_column($applied, 'added'));
    $sumDetail = "Ref: $reference | Lines: ".count($applied)." | Units added: $units";
    $a = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, batch_id, details)
         VALUES (?, ?, ?, 'RECEIVE_DELIVERY', 'delivery', ?, ?, ?, ?)"
    );
    $a->bind_param('ississs', $userId, $userName, $branch, $deliveryId, $reference, $reference, $sumDetail);
    $a->execute();
    $a->close();

    $conn->commit();

    echo json_encode([
        'success'   => true,
        'status'    => 'received',
        'reference' => $reference,
        'lines'     => count($applied),
        'units'     => $units,
        'items'     => $applied,
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
