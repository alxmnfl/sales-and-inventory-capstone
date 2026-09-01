<?php
require_once '../../Landing Page/php/auth.php';

header('Content-Type: application/json');

// Accept session from main login (branch_staff) or POS-direct login
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
require_once __DIR__ . '/../../Landing Page/php/transfer_schema.php';
ensure_transfer_schema($conn);

$branch   = strtoupper(trim($_SESSION['pos_cashier_branch'] ?? ''));
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['pos_cashier'] ?? 'Staff';

if ($branch === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Your account has no branch assigned.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

/** Distinct known branches (directory + anywhere else), UPPERCASE. */
function known_branches(mysqli $conn): array {
    $out = [];
    $r = $conn->query("
        SELECT b FROM (
            SELECT branch COLLATE utf8mb4_unicode_ci AS b FROM branch_directory WHERE is_active = 1
            UNION SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci FROM users
                  WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
            UNION SELECT DISTINCT UPPER(TRIM(branch)) COLLATE utf8mb4_unicode_ci FROM pos_products
                  WHERE branch IS NOT NULL AND branch <> ''
        ) t WHERE b IS NOT NULL AND b <> '' ORDER BY b
    ");
    while ($r && ($row = $r->fetch_row())) $out[] = $row[0];
    return $out;
}

function audit(mysqli $conn, int $uid, string $uname, string $branch, string $act,
               string $etype, ?int $eid, string $ename, ?string $batch, string $detail): void {
    $a = $conn->prepare(
        "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, batch_id, details)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $a->bind_param('issssisss', $uid, $uname, $branch, $act, $etype, $eid, $ename, $batch, $detail);
    $a->execute();
    $a->close();
}

/* ───────────────────────── STOCK LOOKUP ─────────────────────────
   Which other branches hold this SKU, and how much is spare. */
if ($action === 'stock_lookup') {
    $sku = trim($input['sku'] ?? '');
    if ($sku === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No product given.']);
        exit;
    }

    $myRegion = null;
    $rs = $conn->prepare("SELECT region FROM branch_directory WHERE branch = ?");
    $rs->bind_param('s', $branch);
    $rs->execute();
    $rs->bind_result($myRegion);
    $rs->fetch();
    $rs->close();

    $buffer = TRANSFER_SURPLUS_BUFFER;
    $stmt = $conn->prepare("
        SELECT p.branch, p.name, p.category, p.stock,
               COALESCE(d.region, '') AS region
        FROM pos_products p
        LEFT JOIN branch_directory d ON d.branch = p.branch
        WHERE p.sku = ? AND p.branch <> ? AND p.stock > 0
        ORDER BY (p.stock - ?) DESC, p.branch
    ");
    $stmt->bind_param('ssi', $sku, $branch, $buffer);
    $stmt->execute();
    $res = $stmt->get_result();

    $nearby = [];
    $other  = [];
    $productName = '';
    while ($row = $res->fetch_assoc()) {
        $productName = $productName ?: $row['name'];
        $stock   = (int)$row['stock'];
        $surplus = max(0, $stock - $buffer);
        $entry = [
            'branch'   => $row['branch'],
            'region'   => $row['region'],
            'on_hand'  => $stock,
            'surplus'  => $surplus,
        ];
        if ($myRegion && $row['region'] !== '' && strcasecmp($row['region'], $myRegion) === 0) {
            $nearby[] = $entry;
        } else {
            $other[] = $entry;
        }
    }
    $stmt->close();
    $conn->close();

    echo json_encode([
        'success'      => true,
        'sku'          => $sku,
        'product_name' => $productName,
        'my_region'    => $myRegion ?: '',
        'buffer'       => $buffer,
        'nearby'       => $nearby,
        'other'        => $other,
    ]);
    exit;
}

/* ───────────────────────── CREATE REQUEST ───────────────────────── */
if ($action === 'request') {
    $sourceBranch = strtoupper(trim($input['source_branch'] ?? ''));
    $note         = trim($input['note'] ?? '');
    $rawItems     = is_array($input['items'] ?? null) ? $input['items'] : [];

    $branches = known_branches($conn);
    if ($sourceBranch === $branch) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Pick a different branch to request from.']);
        exit;
    }
    if (!in_array($sourceBranch, $branches, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That source branch is not recognised.']);
        exit;
    }

    // Normalise + de-dupe line items by SKU.
    $bySku = [];
    foreach ($rawItems as $it) {
        $sku = trim($it['sku'] ?? '');
        $qty = (int)($it['qty'] ?? 0);
        if ($sku === '' || $qty <= 0) continue;
        $bySku[$sku] = ($bySku[$sku] ?? 0) + $qty;
    }
    if (!$bySku) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Add at least one product with a quantity.']);
        exit;
    }
    if (count($bySku) > 500) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Too many line items (max 500).']);
        exit;
    }

    // Resolve name/category/product_id, preferring the source branch's catalogue,
    // then this branch's, then a bare SKU row.
    $lookup = $conn->prepare(
        "SELECT id, name, category, branch FROM pos_products
         WHERE sku = ? AND branch IN (?, ?)
         ORDER BY branch = ? DESC LIMIT 1"
    );

    $clean = [];
    foreach ($bySku as $sku => $qty) {
        $lookup->bind_param('ssss', $sku, $sourceBranch, $branch, $sourceBranch);
        $lookup->execute();
        $lookup->bind_result($pid, $pname, $pcat, $pbranch);
        $found = $lookup->fetch();
        $lookup->free_result();
        $clean[] = [
            'product_id' => ($found && $pbranch === $sourceBranch) ? (int)$pid : null,
            'sku'        => $sku,
            'name'       => $found ? $pname : $sku,
            'category'   => $found ? ($pcat ?? '') : '',
            'qty'        => $qty,
        ];
    }
    $lookup->close();

    $conn->begin_transaction();
    try {
        $ref = transfer_reference();
        $h = $conn->prepare(
            "INSERT INTO branch_transfers
                (reference, requesting_branch, source_branch, status, note, requested_by, requested_by_name)
             VALUES (?, ?, ?, 'requested', ?, ?, ?)"
        );
        $noteVal = $note !== '' ? $note : null;
        $h->bind_param('ssssis', $ref, $branch, $sourceBranch, $noteVal, $userId, $userName);
        $h->execute();
        $transferId = $h->insert_id;
        $h->close();

        $li = $conn->prepare(
            "INSERT INTO branch_transfer_items (transfer_id, product_id, sku, name, category, qty_requested)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $units = 0;
        foreach ($clean as $c) {
            $li->bind_param('iisssi', $transferId, $c['product_id'], $c['sku'], $c['name'], $c['category'], $c['qty']);
            $li->execute();
            $units += $c['qty'];
        }
        $li->close();

        $detail = "Ref: $ref | $branch -> $sourceBranch | Lines: " . count($clean) . " | Units: $units";
        audit($conn, $userId, $userName, $branch, 'REQUEST_TRANSFER', 'transfer', $transferId, $ref, $ref, $detail);

        $conn->commit();
        echo json_encode(['success' => true, 'status' => 'requested', 'reference' => $ref, 'source_branch' => $sourceBranch]);
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not send the request. ' . $e->getMessage()]);
    }
    exit;
}

/* ───────────────────────── DECLINE (source) ───────────────────────── */
if ($action === 'reject') {
    $transferId = (int)($input['transfer_id'] ?? 0);
    $remarks    = trim($input['remarks'] ?? '');
    if ($transferId <= 0 || $remarks === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Say why the request is being declined.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT reference, status FROM branch_transfers WHERE id = ? AND source_branch = ?");
    $stmt->bind_param('is', $transferId, $branch);
    $stmt->execute();
    $stmt->bind_result($reference, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found for your branch.']);
        exit;
    }
    if ($status !== 'requested') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request has already been actioned.']);
        exit;
    }

    $up = $conn->prepare(
        "UPDATE branch_transfers
         SET status = 'rejected', source_remarks = ?, actioned_by = ?, actioned_by_name = ?, actioned_at = NOW()
         WHERE id = ? AND status = 'requested'"
    );
    $up->bind_param('sisi', $remarks, $userId, $userName, $transferId);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if (!$ok) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request has already been actioned.']);
        exit;
    }

    $detail = "Ref: $reference | Declined by source: $remarks";
    audit($conn, $userId, $userName, $branch, 'REJECT_TRANSFER', 'transfer', $transferId, $reference, $reference, $detail);
    echo json_encode(['success' => true, 'status' => 'rejected', 'reference' => $reference]);
    exit;
}

/* ───────────────────────── CANCEL (requester) ───────────────────────── */
if ($action === 'cancel') {
    $transferId = (int)($input['transfer_id'] ?? 0);
    if ($transferId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bad request.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT reference, status FROM branch_transfers WHERE id = ? AND requesting_branch = ?");
    $stmt->bind_param('is', $transferId, $branch);
    $stmt->execute();
    $stmt->bind_result($reference, $status);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found for your branch.']);
        exit;
    }
    if ($status !== 'requested') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only a still-pending request can be cancelled.']);
        exit;
    }

    $up = $conn->prepare("UPDATE branch_transfers SET status = 'cancelled' WHERE id = ? AND status = 'requested'");
    $up->bind_param('i', $transferId);
    $up->execute();
    $ok = $up->affected_rows > 0;
    $up->close();

    if (!$ok) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only a still-pending request can be cancelled.']);
        exit;
    }

    audit($conn, $userId, $userName, $branch, 'CANCEL_TRANSFER', 'transfer', $transferId, $reference, $reference,
          "Ref: $reference | Cancelled by requester before shipping");
    echo json_encode(['success' => true, 'status' => 'cancelled', 'reference' => $reference]);
    exit;
}

/* ───────────────────────── APPROVE & SHIP (source) ─────────────────────────
   Deducts the shipped quantities from the source branch's stock inside a
   transaction, locking each pos_products row FOR UPDATE (same guard the POS
   sale + delivery-receipt flows use). */
if ($action === 'approve') {
    $transferId = (int)($input['transfer_id'] ?? 0);
    $shipMap    = [];
    foreach ((is_array($input['shipments'] ?? null) ? $input['shipments'] : []) as $s) {
        $iid = (int)($s['item_id'] ?? 0);
        if ($iid > 0) $shipMap[$iid] = max(0, (int)($s['qty'] ?? 0));
    }
    if ($transferId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bad request.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT reference, requesting_branch, status FROM branch_transfers
             WHERE id = ? AND source_branch = ? FOR UPDATE"
        );
        $stmt->bind_param('is', $transferId, $branch);
        $stmt->execute();
        $stmt->bind_result($reference, $requestingBranch, $status);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found)                 throw new Exception('Request not found for your branch.', 404);
        if ($status !== 'requested') throw new Exception('This request has already been actioned.', 409);

        $items = [];
        $res = $conn->query("SELECT id, sku, name, category, qty_requested
                             FROM branch_transfer_items WHERE transfer_id = $transferId ORDER BY sku");
        while ($row = $res->fetch_assoc()) $items[] = $row;
        if (!$items) throw new Exception('This request has no line items.', 400);

        $selProd = $conn->prepare("SELECT id, stock FROM pos_products WHERE sku = ? AND branch = ? FOR UPDATE");
        $updProd = $conn->prepare("UPDATE pos_products SET stock = stock - ? WHERE id = ?");
        $updItem = $conn->prepare("UPDATE branch_transfer_items SET qty_shipped = ?, product_id = ? WHERE id = ?");

        $shipped = [];
        $totalShipped = 0;
        foreach ($items as $it) {
            $iid = (int)$it['id'];
            $sku = $it['sku'];
            $want = array_key_exists($iid, $shipMap) ? $shipMap[$iid] : (int)$it['qty_requested'];

            $selProd->bind_param('ss', $sku, $branch);
            $selProd->execute();
            $selProd->bind_result($pid, $onHand);
            $exists = $selProd->fetch();
            $selProd->free_result();

            $onHand = $exists ? (int)$onHand : 0;
            $qty    = max(0, min($want, $onHand));   // never ship more than is on the shelf

            if ($qty > 0) {
                $updProd->bind_param('ii', $qty, $pid);
                $updProd->execute();
            }
            $pidVal = $exists ? (int)$pid : null;
            $updItem->bind_param('iii', $qty, $pidVal, $iid);
            $updItem->execute();

            if ($qty > 0) {
                $detail = "Ref: $reference | To: $requestingBranch | SKU: $sku | Stock: $onHand -> " . ($onHand - $qty) . " (-$qty)";
                audit($conn, $userId, $userName, $branch, 'SHIP_TRANSFER', 'product', $pidVal, $it['name'], $reference, $detail);
            }
            $shipped[] = ['sku' => $sku, 'name' => $it['name'], 'requested' => (int)$it['qty_requested'], 'shipped' => $qty, 'on_hand_was' => $onHand];
            $totalShipped += $qty;
        }
        $selProd->close(); $updProd->close(); $updItem->close();

        if ($totalShipped <= 0) {
            throw new Exception('None of these items are in stock here right now — decline the request instead.', 409);
        }

        $up = $conn->prepare(
            "UPDATE branch_transfers
             SET status = 'shipped', actioned_by = ?, actioned_by_name = ?, actioned_at = NOW()
             WHERE id = ? AND status = 'requested'"
        );
        $up->bind_param('isi', $userId, $userName, $transferId);
        $up->execute();
        if ($up->affected_rows < 1) { $up->close(); throw new Exception('This request has already been actioned.', 409); }
        $up->close();

        $sumDetail = "Ref: $reference | To: $requestingBranch | Lines: " . count($shipped) . " | Units shipped: $totalShipped";
        audit($conn, $userId, $userName, $branch, 'SHIP_TRANSFER', 'transfer', $transferId, $reference, $reference, $sumDetail);

        $conn->commit();
        echo json_encode([
            'success'   => true,
            'status'    => 'shipped',
            'reference' => $reference,
            'lines'     => count($shipped),
            'units'     => $totalShipped,
            'items'     => $shipped,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        $code = $e->getCode();
        http_response_code(($code >= 400 && $code < 600) ? $code : 400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ───────────────────────── CONFIRM RECEIPT (requester) ─────────────────────────
   Adds the shipped quantities to the requesting branch's stock, creating the
   product locally if it isn't stocked here yet (carrying a price from another
   branch) — same approach as the delivery-receipt flow. */
if ($action === 'receive') {
    $transferId = (int)($input['transfer_id'] ?? 0);
    if ($transferId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bad request.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT reference, source_branch, status FROM branch_transfers
             WHERE id = ? AND requesting_branch = ? FOR UPDATE"
        );
        $stmt->bind_param('is', $transferId, $branch);
        $stmt->execute();
        $stmt->bind_result($reference, $sourceBranch, $status);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found)               throw new Exception('Transfer not found for your branch.', 404);
        if ($status !== 'shipped') throw new Exception('This transfer is not awaiting receipt.', 409);

        $items = [];
        $res = $conn->query("SELECT id, sku, name, category, qty_shipped, applied
                             FROM branch_transfer_items WHERE transfer_id = $transferId ORDER BY sku");
        while ($row = $res->fetch_assoc()) $items[] = $row;

        $selProd = $conn->prepare("SELECT id, stock FROM pos_products WHERE sku = ? AND branch = ? FOR UPDATE");
        $updProd = $conn->prepare("UPDATE pos_products SET stock = stock + ? WHERE id = ?");
        $priceLk = $conn->prepare("SELECT COALESCE(MAX(price), 0) FROM pos_products WHERE sku = ?");
        $insProd = $conn->prepare(
            "INSERT INTO pos_products (sku, name, category, price, stock, branch, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $updItem = $conn->prepare("UPDATE branch_transfer_items SET applied = 1 WHERE id = ?");

        $applied = [];
        $totalIn = 0;
        foreach ($items as $it) {
            $qty = (int)($it['qty_shipped'] ?? 0);
            if ($qty <= 0 || (int)$it['applied'] === 1) continue;
            $sku = $it['sku'];
            $cat = $it['category'] ?? '';

            $selProd->bind_param('ss', $sku, $branch);
            $selProd->execute();
            $selProd->bind_result($pid, $oldStock);
            $exists = $selProd->fetch();
            $selProd->free_result();

            if ($exists) {
                $oldStock = (int)$oldStock;
                $newStock = $oldStock + $qty;
                $updProd->bind_param('ii', $qty, $pid);
                $updProd->execute();
                $entityName = $it['name'];
            } else {
                $priceLk->bind_param('s', $sku);
                $priceLk->execute();
                $priceLk->bind_result($price);
                $priceLk->fetch();
                $priceLk->free_result();
                $price = (float)$price;

                $insProd->bind_param('sssdisi', $sku, $it['name'], $cat, $price, $qty, $branch, $userId);
                $insProd->execute();
                $pid = $insProd->insert_id;
                $oldStock = 0;
                $newStock = $qty;
                $entityName = $it['name'];
            }

            $updItem->bind_param('i', $it['id']);
            $updItem->execute();

            $detail = "Ref: $reference | From: $sourceBranch | SKU: $sku | Stock: $oldStock -> $newStock (+$qty)";
            audit($conn, $userId, $userName, $branch, 'RECEIVE_TRANSFER', 'product', (int)$pid, $entityName, $reference, $detail);

            $applied[] = ['sku' => $sku, 'name' => $entityName, 'old_stock' => $oldStock, 'new_stock' => $newStock, 'added' => $qty];
            $totalIn += $qty;
        }
        $selProd->close(); $updProd->close(); $priceLk->close(); $insProd->close(); $updItem->close();

        $up = $conn->prepare(
            "UPDATE branch_transfers
             SET status = 'received', received_by = ?, received_by_name = ?, received_at = NOW()
             WHERE id = ? AND status = 'shipped'"
        );
        $up->bind_param('isi', $userId, $userName, $transferId);
        $up->execute();
        if ($up->affected_rows < 1) { $up->close(); throw new Exception('This transfer has already been received.', 409); }
        $up->close();

        $sumDetail = "Ref: $reference | From: $sourceBranch | Lines: " . count($applied) . " | Units added: $totalIn";
        audit($conn, $userId, $userName, $branch, 'RECEIVE_TRANSFER', 'transfer', $transferId, $reference, $reference, $sumDetail);

        $conn->commit();
        echo json_encode([
            'success'   => true,
            'status'    => 'received',
            'reference' => $reference,
            'lines'     => count($applied),
            'units'     => $totalIn,
            'items'     => $applied,
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        $code = $e->getCode();
        http_response_code(($code >= 400 && $code < 600) ? $code : 400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
