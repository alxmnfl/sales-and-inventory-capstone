<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';
require_once '../../Landing Page/php/delivery_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/php/login.php'); exit;
}

ensure_delivery_schema($conn);

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Branch list (same union the rest of the console uses) ── */
$branches = [];
$r = $conn->query("
    SELECT DISTINCT b FROM (
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci AS b FROM users
            WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_products
            WHERE branch IS NOT NULL AND branch <> ''
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_sales
            WHERE branch IS NOT NULL AND branch <> ''
    ) t
    ORDER BY b
");
while ($row = $r->fetch_row()) $branches[] = $row[0];

function delivery_reference(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = 'DEL-';
    for ($i = 0; $i < 8; $i++) $out .= $chars[random_int(0, 35)];
    $out .= '-';
    for ($i = 0; $i < 3; $i++) $out .= $chars[random_int(0, 35)];
    return $out;
}

/* ── POST actions ── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $adminId  = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'create') {
        $branch = strtoupper(trim($_POST['branch'] ?? ''));
        $note   = trim($_POST['note'] ?? '');
        $items  = json_decode($_POST['items_json'] ?? '[]', true);

        $clean = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $sku = trim($it['sku'] ?? '');
                $qty = (int)($it['qty'] ?? 0);
                if ($sku === '' || $qty <= 0) continue;
                $clean[] = [
                    'product_id' => isset($it['product_id']) && $it['product_id'] !== '' ? (int)$it['product_id'] : null,
                    'sku'        => $sku,
                    'name'       => trim($it['name'] ?? $sku),
                    'category'   => trim($it['category'] ?? ''),
                    'qty'        => $qty,
                ];
            }
        }

        if (!in_array($branch, $branches, true)) {
            $flash = 'err:Pick a valid destination branch.';
        } elseif (!$clean) {
            $flash = 'err:Add at least one product with a quantity.';
        } elseif (count($clean) > 500) {
            $flash = 'err:Too many line items (max 500).';
        } else {
            $conn->begin_transaction();
            try {
                $ref = delivery_reference();
                $h = $conn->prepare(
                    "INSERT INTO inventory_deliveries (reference, branch, status, note, created_by, created_by_name)
                     VALUES (?, ?, 'sent', ?, ?, ?)"
                );
                $noteVal = $note !== '' ? $note : null;
                $h->bind_param('sssis', $ref, $branch, $noteVal, $adminId, $user_name);
                $h->execute();
                $deliveryId = $h->insert_id;
                $h->close();

                $li = $conn->prepare(
                    "INSERT INTO inventory_delivery_items (delivery_id, product_id, sku, name, category, qty_sent)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $units = 0;
                foreach ($clean as $c) {
                    $li->bind_param('iisssi', $deliveryId, $c['product_id'], $c['sku'], $c['name'], $c['category'], $c['qty']);
                    $li->execute();
                    $units += $c['qty'];
                }
                $li->close();

                $detail = "Ref: $ref | To: $branch | Lines: ".count($clean)." | Units: $units";
                $a = $conn->prepare(
                    "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
                     VALUES (?, ?, ?, 'SEND_DELIVERY', 'delivery', ?, ?, ?)"
                );
                $a->bind_param('ississ', $adminId, $user_name, $branch, $deliveryId, $ref, $detail);
                $a->execute();
                $a->close();

                $conn->commit();
                $flash = 'sent:'.$ref;
            } catch (Throwable $e) {
                $conn->rollback();
                $flash = 'err:Could not send the delivery. '.$e->getMessage();
            }
        }

        header('Location: deliveries.php?branch='.urlencode($branch).'&flash='.urlencode($flash));
        exit;
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['delivery_id'] ?? 0);
        $row = $conn->query("SELECT reference, branch, status FROM inventory_deliveries WHERE id=".$id)->fetch_assoc();
        if ($row && $row['status'] === 'sent') {
            $conn->query("UPDATE inventory_deliveries SET status='cancelled' WHERE id=".$id." AND status='sent'");
            $detail = "Ref: ".$row['reference']." | Cancelled before receipt";
            $a = $conn->prepare(
                "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
                 VALUES (?, ?, ?, 'CANCEL_DELIVERY', 'delivery', ?, ?, ?)"
            );
            $a->bind_param('ississ', $adminId, $user_name, $row['branch'], $id, $row['reference'], $detail);
            $a->execute();
            $a->close();
            $flash = 'cancelled';
        } else {
            $flash = 'err:That delivery can no longer be cancelled.';
        }
        header('Location: deliveries.php?flash='.urlencode($flash));
        exit;
    }
}

/* ── Selected branch for the "new delivery" form ── */
$selBranch = strtoupper(trim($_GET['branch'] ?? ''));
if ($selBranch === '' || !in_array($selBranch, $branches, true)) {
    $selBranch = $branches[0] ?? '';
}

/* Products stocked at the selected branch */
$branchProducts = [];
if ($selBranch !== '') {
    $ps = $conn->prepare("SELECT id, sku, name, category, stock FROM pos_products WHERE branch = ? ORDER BY name");
    $ps->bind_param('s', $selBranch);
    $ps->execute();
    $res = $ps->get_result();
    while ($row = $res->fetch_assoc()) {
        $branchProducts[] = [
            'id'       => (int)$row['id'],
            'sku'      => $row['sku'],
            'name'     => $row['name'],
            'category' => $row['category'],
            'stock'    => (int)$row['stock'],
        ];
    }
    $ps->close();
}

/* ── Delivery history + line items ── */
$deliveries = [];
$res = $conn->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) AS line_count,
           (SELECT COALESCE(SUM(qty_sent),0) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) AS unit_count
    FROM inventory_deliveries d
    ORDER BY d.created_at DESC
    LIMIT 200
");
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['items'] = [];
    $deliveries[$row['id']] = $row;
}
if ($deliveries) {
    $ids = implode(',', array_map('intval', array_keys($deliveries)));
    $res = $conn->query("SELECT delivery_id, sku, name, category, qty_sent, qty_received, applied
                         FROM inventory_delivery_items WHERE delivery_id IN ($ids) ORDER BY id");
    while ($row = $res->fetch_assoc()) {
        $deliveries[(int)$row['delivery_id']]['items'][] = [
            'sku'          => $row['sku'],
            'name'         => $row['name'],
            'category'     => $row['category'],
            'qty_sent'     => (int)$row['qty_sent'],
            'qty_received' => $row['qty_received'] === null ? null : (int)$row['qty_received'],
            'applied'      => (int)$row['applied'],
        ];
    }
}
$deliveries = array_values($deliveries);

/* KPIs */
$kpiSent = $kpiReceived = $kpiDisputed = 0;
foreach ($deliveries as $d) {
    if ($d['status'] === 'sent')     $kpiSent++;
    if ($d['status'] === 'received') $kpiReceived++;
    if ($d['status'] === 'disputed') $kpiDisputed++;
}

$conn->close();

function statusBadge(string $s): array {
    return [
        'sent'      => ['Awaiting Receipt', 'badge-sent'],
        'received'  => ['Received',         'badge-received'],
        'disputed'  => ['Disputed',         'badge-disputed'],
        'cancelled' => ['Cancelled',        'badge-cancelled'],
    ][$s] ?? [ucfirst($s), 'badge-sent'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Deliveries</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260829">
<link rel="stylesheet" href="../styles/inventory.css?v=20260830e">
<link rel="stylesheet" href="../styles/reports.css?v=20260829">
<link rel="stylesheet" href="../styles/deliveries.css?v=20260830f">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Deliveries</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <?php if (isset($_GET['flash'])):
            $f = $_GET['flash'];
            $isErr = strncmp($f, 'err:', 4) === 0;
            $isSent = strncmp($f, 'sent:', 5) === 0;
            $msg = $isErr  ? substr($f, 4)
                 : ($isSent ? 'Delivery '.substr($f, 5).' sent to the branch.'
                 : ($f === 'cancelled' ? 'Delivery cancelled.' : 'Done.'));
        ?>
        <div class="flash <?= $isErr ? 'err' : 'ok' ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <div class="kpi-grid dlv-kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Awaiting Receipt</span><div class="kpi-icon orange"><i class="fa-solid fa-truck-fast"></i></div></div>
                <div class="kpi-value"><?=$kpiSent?></div>
                <div class="kpi-meta">sent, not yet confirmed</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Received</span><div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div></div>
                <div class="kpi-value"><?=$kpiReceived?></div>
                <div class="kpi-meta">confirmed &amp; added to stock</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Disputed</span><div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
                <div class="kpi-value"><?=$kpiDisputed?></div>
                <div class="kpi-meta">flagged by branch staff</div>
            </div>
        </div>

        <!-- New delivery -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <div class="chart-title">New Delivery Document</div>
                    <div class="chart-subtitle">Pick a branch, set quantities, and send. The branch confirms receipt before stock changes.</div>
                </div>
                <?php if (!empty($branches)): ?>
                <button type="button" class="btn-orange" id="dlvSendBtn" onclick="openDlvConfirm()" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Send Delivery
                </button>
                <?php endif; ?>
            </div>

            <?php if (empty($branches)): ?>
            <div style="padding:32px;text-align:center;color:#9ca3af;">No branches found yet.</div>
            <?php else: ?>

            <div class="filter-bar">
                <label>Destination branch</label>
                <div class="branch-filter" title="Destination branch">
                    <i class="fa-solid fa-location-dot branch-filter-icon"></i>
                    <button class="branch-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <span class="branch-selected-label"><?=htmlspecialchars($selBranch)?></span>
                        <i class="fa-solid fa-chevron-down branch-chevron"></i>
                    </button>
                    <div class="branch-dropdown-panel" role="listbox" aria-label="Destination branch">
                        <?php foreach ($branches as $b): ?>
                        <div class="branch-option<?=$b === $selBranch ? ' branch-option--selected' : ''?>"
                             data-value="<?=htmlspecialchars($b)?>" role="option"
                             aria-selected="<?=$b === $selBranch ? 'true' : 'false'?>">
                            <i class="fa-solid fa-store"></i><span><?=htmlspecialchars($b)?></span>
                            <i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <select id="dlvBranch" class="branch-filter-hidden-select" style="display:none"
                            onchange="location.href='deliveries.php?branch='+encodeURIComponent(this.value)">
                        <?php foreach ($branches as $b): ?>
                        <option value="<?=htmlspecialchars($b)?>" <?=$b === $selBranch ? 'selected' : ''?>><?=htmlspecialchars($b)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label>Note</label>
                <input type="text" id="dlvNote" class="dlv-grow" placeholder="e.g. Restock shipment from Main Hub">
                <label>Search</label>
                <input type="text" id="dlvSearch" class="dlv-grow" placeholder="Product name or SKU…" oninput="dlvGoto(1)">
            </div>

            <p class="preview-note" id="dlvPreviewNote"></p>

            <div class="report-table-wrap">
                <table class="intel-table dlv-product-table">
                    <thead><tr>
                        <th>Product</th><th>SKU</th><th class="r">On Hand</th><th class="c">Send Qty</th>
                    </tr></thead>
                    <tbody id="dlvTableBody"></tbody>
                </table>
            </div>

            <div class="pagination" id="dlvPagination"></div>
            <?php endif; ?>
        </div>

        <!-- History -->
        <div class="chart-card">
            <div style="margin-bottom:16px;">
                <div class="chart-title">Delivery History</div>
                <div class="chart-subtitle">Most recent 200 documents</div>
            </div>
            <div class="report-table-wrap">
                <table class="intel-table">
                    <thead><tr>
                        <th>Reference</th><th>Branch</th><th class="r">Lines</th><th class="r">Units</th>
                        <th>Status</th><th>Sent</th><th>Received / Notes</th><th class="r">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">No deliveries sent yet.</td></tr>
                    <?php else: foreach ($deliveries as $d):
                        [$blabel, $bclass] = statusBadge($d['status']);
                    ?>
                        <tr>
                            <td class="mono"><?=htmlspecialchars($d['reference'])?></td>
                            <td><?=htmlspecialchars($d['branch'])?></td>
                            <td class="r"><?=(int)$d['line_count']?></td>
                            <td class="r"><?=(int)$d['unit_count']?></td>
                            <td><span class="dlv-badge <?=$bclass?>"><?=$blabel?></span></td>
                            <td class="dlv-dim"><?=htmlspecialchars($d['created_at'])?><br><span class="dlv-sub">by <?=htmlspecialchars($d['created_by_name'] ?? '—')?></span></td>
                            <td class="dlv-dim">
                                <?php if ($d['status'] === 'received'): ?>
                                    <?=htmlspecialchars($d['received_at'] ?? '')?><br><span class="dlv-sub">by <?=htmlspecialchars($d['received_by_name'] ?? '—')?></span>
                                <?php elseif ($d['status'] === 'disputed'): ?>
                                    <span class="dlv-sub"><?=htmlspecialchars($d['staff_remarks'] ?: 'Flagged — no details')?></span>
                                <?php else: ?>
                                    <span class="dlv-sub">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="r" style="white-space:nowrap;">
                                <button type="button" class="btn-ghost" onclick="viewDelivery(<?=$d['id']?>)">View</button>
                                <?php if ($d['status'] === 'sent'): ?>
                                <button type="button" class="btn-danger" onclick="cancelDelivery(<?=$d['id']?>, '<?=htmlspecialchars(addslashes($d['reference']))?>')">Cancel</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Send confirmation -->
<div class="modal-bg" id="dlvConfirmModal">
    <div class="modal">
        <h3><i class="fa-solid fa-paper-plane" style="color:#e8611a;margin-right:8px;"></i>Send this delivery?</h3>
        <p class="modal-note">The document goes to <strong id="dlvConfirmBranch"></strong>. Stock is added only after the branch confirms receipt.</p>
        <div id="dlvConfirmList" class="dlv-confirm-list"></div>
        <form method="POST" id="dlvSendForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="branch" id="dlvSendBranch">
            <input type="hidden" name="note" id="dlvSendNote">
            <input type="hidden" name="items_json" id="dlvSendItems">
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('dlvConfirmModal')">Cancel</button>
                <button type="submit" class="btn-orange">Confirm &amp; Send</button>
            </div>
        </form>
    </div>
</div>

<!-- View delivery -->
<div class="modal-bg" id="dlvViewModal">
    <div class="modal">
        <h3><i class="fa-solid fa-file-lines" style="color:#e8611a;margin-right:8px;"></i><span id="dlvViewRef"></span></h3>
        <p class="modal-note" id="dlvViewMeta"></p>
        <div id="dlvViewList" class="dlv-confirm-list"></div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost" onclick="closeModal('dlvViewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Cancel delivery -->
<form method="POST" id="dlvCancelForm" style="display:none;">
    <input type="hidden" name="action" value="cancel">
    <input type="hidden" name="delivery_id" id="dlvCancelId">
</form>

<script>
const DLV_PRODUCTS  = <?=json_encode($branchProducts)?>;
const DLV_HISTORY   = <?=json_encode($deliveries)?>;
const DLV_BRANCH    = <?=json_encode($selBranch)?>;
</script>
<script src="../src/branch-filter-widget.js?v=20260829"></script>
<script src="../src/deliveries.js?v=20260830c"></script>
</body>
</html>
