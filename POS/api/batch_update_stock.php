<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['pos_cashier'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../Landing Page/php/db.php';

function generateBatchId() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len   = strlen($chars);
    $id    = 'BATCH-';
    for ($i = 0; $i < 8; $i++) $id .= $chars[random_int(0, $len - 1)];
    $id .= '-';
    for ($i = 0; $i < 3; $i++) $id .= $chars[random_int(0, $len - 1)];
    return $id;
}

$input       = json_decode(file_get_contents('php://input'), true);
$adjustments = $input['adjustments'] ?? null;

if (!is_array($adjustments) || empty($adjustments)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No adjustments provided.']);
    exit;
}

if (count($adjustments) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Batch too large (max 500 rows).']);
    exit;
}

// Validate shape + de-dupe product IDs before touching the DB
$seenIds = [];
$clean   = [];
foreach ($adjustments as $adj) {
    $productId = (int)($adj['product_id'] ?? 0);
    $type      = $adj['type']              ?? '';
    $quantity  = $adj['quantity']           ?? null;
    $reason    = trim($adj['reason']       ?? '');

    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid product in batch.']);
        exit;
    }
    if (isset($seenIds[$productId])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Product $productId appears more than once in the batch."]);
        exit;
    }
    $seenIds[$productId] = true;

    if (!in_array($type, ['add', 'remove'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Invalid adjustment type for product $productId."]);
        exit;
    }
    if (!is_numeric($quantity) || (int)$quantity <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Invalid quantity for product $productId."]);
        exit;
    }
    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "A reason is required for product $productId."]);
        exit;
    }

    $clean[] = [
        'product_id' => $productId,
        'type'       => $type,
        'quantity'   => (int)$quantity,
        'reason'     => $reason,
    ];
}

// Sort by product_id ascending so concurrent batches always acquire row locks
// in the same order — avoids deadlocks when two batches touch overlapping products.
usort($clean, fn($a, $b) => $a['product_id'] <=> $b['product_id']);

$cashierBranch = strtoupper(trim($_SESSION['pos_cashier_branch'] ?? ''));
$userId        = (int)($_SESSION['user_id'] ?? 0);
$userName      = $_SESSION['pos_cashier'] ?? 'Staff';
$batchId       = generateBatchId();

$conn->begin_transaction();

try {
    $results = [];

    foreach ($clean as $adj) {
        $productId = $adj['product_id'];
        $qty       = $adj['quantity'];
        $reason    = $adj['reason'];

        $stmt = $conn->prepare(
            'SELECT sku, name, stock, branch FROM pos_products WHERE id = ? AND branch = ? FOR UPDATE'
        );
        $stmt->bind_param('is', $productId, $cashierBranch);
        $stmt->execute();
        $stmt->bind_result($sku, $name, $oldStock, $branch);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found) {
            throw new Exception("Product #$productId is not stocked at $cashierBranch.");
        }

        $oldStock = (int)$oldStock;
        $newStock = $adj['type'] === 'add' ? $oldStock + $qty : $oldStock - $qty;

        if ($newStock < 0) {
            throw new Exception("\"$name\" would go negative (currently $oldStock, requested -$qty).");
        }

        $updateStmt = $conn->prepare('UPDATE pos_products SET stock = ? WHERE id = ? AND branch = ?');
        $updateStmt->bind_param('iis', $newStock, $productId, $branch);
        $updateStmt->execute();
        $updateStmt->close();

        $delta  = $newStock - $oldStock;
        $sign   = $delta >= 0 ? '+' : '';
        $detail = "SKU: $sku | Stock: $oldStock \xE2\x86\x92 $newStock ($sign$delta) | Reason: $reason";

        $auditStmt = $conn->prepare(
            "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, batch_id, details)
             VALUES (?, ?, ?, 'ADJUST_STOCK', 'product', ?, ?, ?, ?)"
        );
        $auditStmt->bind_param('ississs', $userId, $userName, $branch, $productId, $name, $batchId, $detail);
        $auditStmt->execute();
        $auditStmt->close();

        $results[] = [
            'product_id' => $productId,
            'name'       => $name,
            'old_stock'  => $oldStock,
            'new_stock'  => $newStock,
        ];
    }

    $conn->commit();

    echo json_encode([
        'success'  => true,
        'batch_id' => $batchId,
        'count'    => count($results),
        'items'    => $results,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
