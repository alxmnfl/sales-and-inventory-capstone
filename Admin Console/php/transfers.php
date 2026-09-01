<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';
require_once '../../Landing Page/php/transfer_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/php/login.php'); exit;
}

ensure_transfer_schema($conn);

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── POST: save branch regions (the only write this page does) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_regions') {
    $regions = $_POST['region'] ?? [];        // branch => region
    if (is_array($regions)) {
        $up = $conn->prepare("UPDATE branch_directory SET region = ? WHERE branch = ?");
        foreach ($regions as $b => $reg) {
            $b   = strtoupper(trim((string)$b));
            $reg = trim((string)$reg);
            $regVal = $reg === '' ? null : $reg;
            $up->bind_param('ss', $regVal, $b);
            $up->execute();
        }
        $up->close();

        $a = $conn->prepare(
            "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
             VALUES (?, ?, 'ALL BRANCHES', 'EDIT_BRANCH_REGION', 'branch', NULL, 'Branch directory', ?)"
        );
        $adminId = (int)$_SESSION['user_id'];
        $detail  = 'Updated branch regions for the transfer directory';
        $a->bind_param('iss', $adminId, $user_name, $detail);
        $a->execute();
        $a->close();
    }
    header('Location: transfers.php?flash=regions');
    exit;
}

/* ── Filters ── */
$fBranch = strtoupper(trim($_GET['branch'] ?? ''));
$fStatus = trim($_GET['status'] ?? '');

$where = [];
if ($fBranch !== '') {
    $b = addslashes($fBranch);
    $where[] = "(t.requesting_branch = '$b' OR t.source_branch = '$b')";
}
if (in_array($fStatus, ['requested','shipped','received','rejected','cancelled'], true)) {
    $where[] = "t.status = '".addslashes($fStatus)."'";
}
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ── Transfers + line items ── */
$transfers = [];
$res = $conn->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM branch_transfer_items i WHERE i.transfer_id = t.id) AS line_count,
           (SELECT COALESCE(SUM(qty_requested),0) FROM branch_transfer_items i WHERE i.transfer_id = t.id) AS unit_req,
           (SELECT COALESCE(SUM(qty_shipped),0)   FROM branch_transfer_items i WHERE i.transfer_id = t.id) AS unit_ship
    FROM branch_transfers t
    $whereSql
    ORDER BY t.requested_at DESC
    LIMIT 300
");
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['items'] = [];
    $transfers[$row['id']] = $row;
}
if ($transfers) {
    $ids = implode(',', array_map('intval', array_keys($transfers)));
    $ir = $conn->query("SELECT transfer_id, sku, name, category, qty_requested, qty_shipped, applied
                        FROM branch_transfer_items WHERE transfer_id IN ($ids) ORDER BY name");
    while ($row = $ir->fetch_assoc()) {
        $transfers[(int)$row['transfer_id']]['items'][] = [
            'sku'           => $row['sku'],
            'name'          => $row['name'],
            'category'      => $row['category'],
            'qty_requested' => (int)$row['qty_requested'],
            'qty_shipped'   => $row['qty_shipped'] === null ? null : (int)$row['qty_shipped'],
            'applied'       => (int)$row['applied'],
        ];
    }
}
$transfers = array_values($transfers);

/* ── KPIs (unfiltered, whole system) ── */
$kpi = ['requested' => 0, 'shipped' => 0, 'received' => 0, 'rejected' => 0];
$kr = $conn->query("SELECT status, COUNT(*) c FROM branch_transfers GROUP BY status");
while ($row = $kr->fetch_row()) { if (isset($kpi[$row[0]])) $kpi[$row[0]] = (int)$row[1]; }

/* ── Branch directory (for the region editor + filter dropdown) ── */
$directory = [];
$dr = $conn->query("SELECT branch, COALESCE(region,'') region FROM branch_directory ORDER BY branch");
while ($row = $dr->fetch_assoc()) $directory[] = $row;

$conn->close();

function tStatusBadge(string $s): array {
    return [
        'requested' => ['Pending',    'badge-sent'],
        'shipped'   => ['In Transit', 'badge-transit'],
        'received'  => ['Received',   'badge-received'],
        'rejected'  => ['Declined',   'badge-disputed'],
        'cancelled' => ['Cancelled',  'badge-cancelled'],
    ][$s] ?? [ucfirst($s), 'badge-sent'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Inter-Branch Transfers</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260901b">
<link rel="stylesheet" href="../styles/inventory.css?v=20260901">
<link rel="stylesheet" href="../styles/reports.css?v=20260829">
<link rel="stylesheet" href="../styles/deliveries.css?v=20260830f">
<link rel="stylesheet" href="../styles/transfers.css?v=20260901c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Inter-Branch Transfers</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <?php if (($_GET['flash'] ?? '') === 'regions'): ?>
        <div class="flash ok">Branch regions saved. Staff will now see “nearby” branches grouped by region in the POS stock lookup.</div>
        <?php endif; ?>

        <div class="chart-subtitle" style="margin-bottom:14px;">
            Read-only oversight of stock moving between branches. Requests are raised and approved by branch staff in the POS —
            this page is for monitoring and for maintaining the region directory that powers the “nearby branches” view.
        </div>

        <div class="kpi-grid dlv-kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Pending</span><div class="kpi-icon orange"><i class="fa-solid fa-hourglass-half"></i></div></div>
                <div class="kpi-value"><?=$kpi['requested']?></div>
                <div class="kpi-meta">awaiting a source branch</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">In Transit</span><div class="kpi-icon blue"><i class="fa-solid fa-truck-ramp-box"></i></div></div>
                <div class="kpi-value"><?=$kpi['shipped']?></div>
                <div class="kpi-meta">shipped, not yet received</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Received</span><div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div></div>
                <div class="kpi-value"><?=$kpi['received']?></div>
                <div class="kpi-meta">completed transfers</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Declined</span><div class="kpi-icon red"><i class="fa-solid fa-ban"></i></div></div>
                <div class="kpi-value"><?=$kpi['rejected']?></div>
                <div class="kpi-meta">source branch could not fill</div>
            </div>
        </div>

        <!-- History -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="margin-bottom:16px;">
                <div class="chart-title">Transfer Log</div>
                <div class="chart-subtitle">Most recent 300 transfers</div>
            </div>

            <?php
            $statusOpts = ['' => 'Any status', 'requested' => 'Pending', 'shipped' => 'In Transit',
                           'received' => 'Received', 'rejected' => 'Declined', 'cancelled' => 'Cancelled'];
            $curBranchLabel = $fBranch !== '' ? $fBranch : 'All branches';
            $curStatusLabel = $statusOpts[$fStatus] ?? 'Any status';
            ?>
            <form method="GET" class="filter-bar">
                <label>Branch</label>
                <div class="branch-filter" title="Filter by branch">
                    <i class="fa-solid fa-location-dot branch-filter-icon"></i>
                    <button class="branch-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <span class="branch-selected-label"><?=htmlspecialchars($curBranchLabel)?></span>
                        <i class="fa-solid fa-chevron-down branch-chevron"></i>
                    </button>
                    <div class="branch-dropdown-panel" role="listbox" aria-label="Filter by branch">
                        <div class="branch-option<?=$fBranch === '' ? ' branch-option--selected' : ''?>" data-value="" role="option" aria-selected="<?=$fBranch === '' ? 'true' : 'false'?>">
                            <i class="fa-solid fa-globe"></i><span>All branches</span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php foreach ($directory as $d): ?>
                        <div class="branch-option<?=$fBranch === $d['branch'] ? ' branch-option--selected' : ''?>" data-value="<?=htmlspecialchars($d['branch'])?>" role="option" aria-selected="<?=$fBranch === $d['branch'] ? 'true' : 'false'?>">
                            <i class="fa-solid fa-store"></i><span><?=htmlspecialchars($d['branch'])?></span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <select name="branch" class="branch-filter-hidden-select" style="display:none" onchange="this.form.submit()">
                        <option value=""<?=$fBranch === '' ? ' selected' : ''?>>All branches</option>
                        <?php foreach ($directory as $d): ?>
                        <option value="<?=htmlspecialchars($d['branch'])?>"<?=$fBranch === $d['branch'] ? ' selected' : ''?>><?=htmlspecialchars($d['branch'])?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label>Status</label>
                <div class="branch-filter" title="Filter by status">
                    <i class="fa-solid fa-circle-half-stroke branch-filter-icon"></i>
                    <button class="branch-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <span class="branch-selected-label"><?=htmlspecialchars($curStatusLabel)?></span>
                        <i class="fa-solid fa-chevron-down branch-chevron"></i>
                    </button>
                    <div class="branch-dropdown-panel" role="listbox" aria-label="Filter by status">
                        <?php foreach ($statusOpts as $v => $lbl): ?>
                        <div class="branch-option<?=$fStatus === $v ? ' branch-option--selected' : ''?>" data-value="<?=$v?>" role="option" aria-selected="<?=$fStatus === $v ? 'true' : 'false'?>">
                            <i class="fa-solid fa-<?= $v === '' ? 'list' : 'circle-dot' ?>"></i><span><?=$lbl?></span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <select name="status" class="branch-filter-hidden-select" style="display:none" onchange="this.form.submit()">
                        <?php foreach ($statusOpts as $v => $lbl): ?>
                        <option value="<?=$v?>"<?=$fStatus === $v ? ' selected' : ''?>><?=$lbl?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($fBranch || $fStatus): ?><a href="transfers.php" class="btn-ghost">Clear</a><?php endif; ?>
            </form>

            <div class="report-table-wrap">
                <table class="intel-table">
                    <thead><tr>
                        <th>Reference</th><th>Requesting</th><th>Source</th><th class="r">Lines</th>
                        <th class="r">Req / Ship</th><th>Status</th><th>Requested</th><th>Resolved</th><th class="r">Details</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($transfers)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:32px;color:#9ca3af;">No transfers match.</td></tr>
                    <?php else: foreach ($transfers as $t):
                        [$lbl, $cls] = tStatusBadge($t['status']);
                        $resolved = $t['received_at'] ?: $t['actioned_at'];
                    ?>
                        <tr>
                            <td class="mono"><?=htmlspecialchars($t['reference'])?></td>
                            <td><strong><?=htmlspecialchars($t['requesting_branch'])?></strong><div class="dlv-sub">by <?=htmlspecialchars($t['requested_by_name'] ?? '—')?></div></td>
                            <td><?=htmlspecialchars($t['source_branch'])?><?php if ($t['actioned_by_name']): ?><div class="dlv-sub">by <?=htmlspecialchars($t['actioned_by_name'])?></div><?php endif; ?></td>
                            <td class="r"><?=(int)$t['line_count']?></td>
                            <td class="r"><?=(int)$t['unit_req']?> / <?=$t['status'] === 'requested' || $t['status'] === 'cancelled' || $t['status'] === 'rejected' ? '—' : (int)$t['unit_ship']?></td>
                            <td><span class="dlv-badge <?=$cls?>"><?=$lbl?></span></td>
                            <td class="dlv-dim"><?=htmlspecialchars($t['requested_at'])?></td>
                            <td class="dlv-dim">
                                <?=$resolved ? htmlspecialchars($resolved) : '<span class="dlv-sub">—</span>'?>
                                <?php if ($t['status'] === 'rejected' && $t['source_remarks']): ?><div class="dlv-sub">“<?=htmlspecialchars($t['source_remarks'])?>”</div><?php endif; ?>
                            </td>
                            <td class="r"><button type="button" class="btn-ghost" onclick='viewTransfer(<?=json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT)?>)'>View</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Region directory -->
        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <div class="chart-title">Branch Region Directory</div>
                    <div class="chart-subtitle">Give each branch a region name (e.g. “METRO MANILA”, “CENTRAL LUZON”). Branches that share a region show up as “Nearby” in the POS stock lookup.</div>
                </div>
                <button type="submit" form="regionForm" class="btn-orange"><i class="fa-solid fa-floppy-disk"></i> Save Regions</button>
            </div>

            <form method="POST" id="regionForm">
                <input type="hidden" name="action" value="set_regions">
                <div class="report-table-wrap">
                    <table class="intel-table">
                        <thead><tr><th>Branch</th><th>Region</th></tr></thead>
                        <tbody>
                        <?php foreach ($directory as $d): ?>
                            <tr>
                                <td><strong><?=htmlspecialchars($d['branch'])?></strong></td>
                                <td><input type="text" class="trf-region-input" name="region[<?=htmlspecialchars($d['branch'])?>]" value="<?=htmlspecialchars($d['region'])?>" placeholder="— none —" list="regionSuggest"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <datalist id="regionSuggest">
                    <?php
                    $seen = [];
                    foreach ($directory as $d) { if ($d['region'] !== '' && !isset($seen[$d['region']])) { $seen[$d['region']] = 1; echo '<option value="'.htmlspecialchars($d['region']).'">'; } }
                    ?>
                </datalist>
            </form>
        </div>
    </div>
</div>

<!-- View modal -->
<div class="modal-bg" id="trfViewModal">
    <div class="modal">
        <h3><i class="fa-solid fa-right-left" style="color:#e8611a;margin-right:8px;"></i><span id="trfViewRef"></span></h3>
        <p class="modal-note" id="trfViewMeta"></p>
        <div id="trfViewList" class="dlv-confirm-list"></div>
        <div class="modal-footer">
            <button type="button" class="btn-ghost" onclick="document.getElementById('trfViewModal').classList.remove('open')">Close</button>
        </div>
    </div>
</div>

<script src="../src/branch-filter-widget.js?v=20260829"></script>
<script src="../src/transfers.js?v=20260901"></script>
</body>
</html>
