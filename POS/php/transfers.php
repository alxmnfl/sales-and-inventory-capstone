<?php
require_once '../../Landing Page/php/auth.php';

// Accept session from main login (branch_staff) or POS-direct login
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'branch_staff') {
    $_SESSION['pos_cashier']        = strtoupper($_SESSION['user_name']);
    $_SESSION['pos_cashier_branch'] = strtoupper($_SESSION['user_branch'] ?? '');
}

if (!isset($_SESSION['pos_cashier'])) {
    header('Location: ../../Landing Page/login.php');
    exit;
}

require_once '../../Landing Page/php/transfer_schema.php';
ensure_transfer_schema($conn);

$cashier = $_SESSION['pos_cashier'];
$branch  = strtoupper($_SESSION['pos_cashier_branch'] ?? 'MAIN HUB');

/* ── This branch's own catalogue (for the request picker) ── */
$myProducts = [];
$stmt = $conn->prepare("SELECT id, sku, name, category, stock FROM pos_products WHERE branch = ? ORDER BY name");
$stmt->bind_param('s', $branch);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $myProducts[] = [
        'id' => (int)$row['id'], 'sku' => $row['sku'], 'name' => $row['name'],
        'category' => $row['category'], 'stock' => (int)$row['stock'],
    ];
}
$stmt->close();

/* ── Other branches we can request from ── */
$myRegion = '';
$rs = $conn->prepare("SELECT region FROM branch_directory WHERE branch = ?");
$rs->bind_param('s', $branch);
$rs->execute();
$rs->bind_result($myRegion);
$rs->fetch();
$rs->close();
$myRegion = $myRegion ?: '';

$branches = [];
$bs = $conn->prepare("SELECT branch, COALESCE(region,'') region FROM branch_directory WHERE is_active = 1 AND branch <> ? ORDER BY branch");
$bs->bind_param('s', $branch);
$bs->execute();
$bres = $bs->get_result();
while ($row = $bres->fetch_assoc()) {
    $row['nearby'] = ($myRegion !== '' && strcasecmp($row['region'], $myRegion) === 0);
    $branches[] = $row;
}
$bs->close();

/* ── Load transfers touching this branch, with line items ──
   $stockBranch, when given, adds each line's current on-hand at that branch. */
function load_transfers(mysqli $conn, string $column, string $branch, ?string $stockBranch = null): array {
    $rows = [];
    $stmt = $conn->prepare("
        SELECT t.*,
               (SELECT COUNT(*) FROM branch_transfer_items i WHERE i.transfer_id = t.id) AS line_count,
               (SELECT COALESCE(SUM(qty_requested),0) FROM branch_transfer_items i WHERE i.transfer_id = t.id) AS unit_count
        FROM branch_transfers t
        WHERE t.$column = ?
        ORDER BY (t.status IN ('requested','shipped')) DESC, t.requested_at DESC
        LIMIT 100
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['items'] = [];
        $rows[$row['id']] = $row;
    }
    $stmt->close();

    if ($rows) {
        $ids = implode(',', array_map('intval', array_keys($rows)));
        if ($stockBranch !== null) {
            $ir = $conn->prepare("
                SELECT i.id, i.transfer_id, i.sku, i.name, i.category, i.qty_requested, i.qty_shipped, i.applied,
                       (SELECT p.stock FROM pos_products p WHERE p.sku = i.sku AND p.branch = ?) AS on_hand
                FROM branch_transfer_items i WHERE i.transfer_id IN ($ids) ORDER BY i.name
            ");
            $ir->bind_param('s', $stockBranch);
            $ir->execute();
            $ir = $ir->get_result();
        } else {
            $ir = $conn->query("SELECT id, transfer_id, sku, name, category, qty_requested, qty_shipped, applied, NULL AS on_hand
                                FROM branch_transfer_items WHERE transfer_id IN ($ids) ORDER BY name");
        }
        while ($row = $ir->fetch_assoc()) {
            $rows[(int)$row['transfer_id']]['items'][] = [
                'item_id'       => (int)$row['id'],
                'sku'           => $row['sku'],
                'name'          => $row['name'],
                'category'      => $row['category'],
                'qty_requested' => (int)$row['qty_requested'],
                'qty_shipped'   => $row['qty_shipped'] === null ? null : (int)$row['qty_shipped'],
                'applied'       => (int)$row['applied'],
                'on_hand'       => $row['on_hand'] === null ? null : (int)$row['on_hand'],
            ];
        }
    }
    return array_values($rows);
}

$incoming = load_transfers($conn, 'source_branch', $branch, $branch);  // others asking us (+ our on-hand)
$outgoing = load_transfers($conn, 'requesting_branch', $branch);        // us asking others

$incomingToAction = 0;
foreach ($incoming as $t) if ($t['status'] === 'requested') $incomingToAction++;
$toReceive = 0;
foreach ($outgoing as $t) if ($t['status'] === 'shipped') $toReceive++;
$myOpen = 0;
foreach ($outgoing as $t) if (in_array($t['status'], ['requested', 'shipped'], true)) $myOpen++;

$conn->close();

function trfBadge(string $s): array {
    return [
        'requested' => ['PENDING',   'trf-badge--new'],
        'shipped'   => ['IN TRANSIT','trf-badge--transit'],
        'received'  => ['RECEIVED',  'trf-badge--done'],
        'rejected'  => ['DECLINED',  'trf-badge--warn'],
        'cancelled' => ['CANCELLED', 'trf-badge--muted'],
    ][$s] ?? [strtoupper($s), 'trf-badge--muted'];
}
function trfWhen(?string $s): string {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y · g:i A', $t) : $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 POS — Inter-Branch Transfers</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="../style/base.css">
<link rel="stylesheet" href="../style/header.css?v=20260901">
<link rel="stylesheet" href="../style/modal.css">
<link rel="stylesheet" href="../style/deliveries.css?v=20260830j">
<link rel="stylesheet" href="../style/transfers.css?v=20260902">
</head>
<body>

<header class="pos-header">
  <div class="header-left">
    <div class="logo-box">L8</div>
    <div class="header-store">
      <div class="store-name">INTER-BRANCH TRANSFERS</div>
      <div class="store-branch"><?= htmlspecialchars($branch) ?></div>
    </div>
  </div>
  <div class="header-center">
    <div class="session-badge"><span class="session-dot"></span>SESSION: <?= htmlspecialchars($cashier) ?></div>
  </div>
  <div class="header-right">
    <a href="deliveries.php" class="btn-header btn-header--deliveries">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M1 4h9v7H1zM10 6h3l2 2v3h-5M3.5 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM12.5 13.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      DELIVERIES
    </a>
    <a href="index.php" class="btn-header">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M10 2L4 8l6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      BACK TO POS
    </a>
    <a href="logout.php" class="btn-header btn-exit">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M6 2H2v12h4M11 5l3 3-3 3M14 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      EXIT
    </a>
  </div>
</header>

<div class="dlv-page">

  <div class="dlv-intro">
    <h1>Inter-Branch Stock Transfers</h1>
    <p>Find which branches are holding spare stock of a product, request a transfer, and confirm what arrives. Requests are handled branch-to-branch by staff.</p>
  </div>

  <div id="trfSuccess" class="dlv-success" style="display:none">
    <i class="fa-solid fa-circle-check"></i>
    <div>
      <div class="dlv-success-title" id="trfSuccessTitle"></div>
      <div class="dlv-success-sub" id="trfSuccessSub"></div>
    </div>
    <button class="dlv-success-close" onclick="document.getElementById('trfSuccess').style.display='none'">&times;</button>
  </div>

  <?php if ($incomingToAction || $toReceive): ?>
  <div class="dlv-alert">
    <i class="fa-solid fa-right-left"></i>
    <div>
      <?php if ($incomingToAction): ?><strong><?= $incomingToAction ?></strong> incoming request<?= $incomingToAction > 1 ? 's' : '' ?> waiting for your approval. <?php endif; ?>
      <?php if ($toReceive): ?><strong><?= $toReceive ?></strong> shipment<?= $toReceive > 1 ? 's' : '' ?> on the way to you — confirm receipt when they arrive.<?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="kpi-grid">
    <div class="kpi-card<?= $incomingToAction ? ' kpi-card--alert' : '' ?>">
      <div class="kpi-top"><span class="kpi-label">Incoming — To Approve</span><div class="kpi-icon orange"><i class="fa-solid fa-inbox"></i></div></div>
      <div class="kpi-value"><?= $incomingToAction ?></div>
      <div class="kpi-meta">other branches asking you</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-top"><span class="kpi-label">In Transit — To Receive</span><div class="kpi-icon green"><i class="fa-solid fa-truck-ramp-box"></i></div></div>
      <div class="kpi-value"><?= $toReceive ?></div>
      <div class="kpi-meta">shipped to you, not yet confirmed</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-top"><span class="kpi-label">My Open Requests</span><div class="kpi-icon red"><i class="fa-solid fa-paper-plane"></i></div></div>
      <div class="kpi-value"><?= $myOpen ?></div>
      <div class="kpi-meta">pending or in transit</div>
    </div>
  </div>

  <!-- ── Cross-branch stock lookup ── -->
  <div class="chart-card">
    <div class="chart-title">Where's the stock?</div>
    <div class="chart-subtitle">Type a product name or SKU to see which other branches are holding spare stock. Surplus = on-hand beyond a <?= TRANSFER_SURPLUS_BUFFER ?>-unit safety buffer.</div>

    <div class="trf-lookup-bar">
      <input type="text" id="trfLookupInput" class="trf-input" list="trfSkuList" placeholder="e.g. Hydraulic Hose 1/2&quot; or HYD-050…" autocomplete="off">
      <datalist id="trfSkuList">
        <?php foreach ($myProducts as $p): ?>
        <option value="<?= htmlspecialchars($p['sku']) ?>"><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <button class="trf-btn-primary" id="trfLookupBtn" onclick="runLookup()">
        <i class="fa-solid fa-magnifying-glass"></i> Check branches
      </button>
    </div>
    <div id="trfLookupResult" class="trf-lookup-result"></div>
  </div>

  <!-- ── New request ── -->
  <div class="chart-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
      <div>
        <div class="chart-title">New Transfer Request</div>
        <div class="chart-subtitle">Pick the branch to request from, set quantities, and send. Stock only moves once they approve and you confirm receipt.</div>
      </div>
      <button class="trf-btn-primary" id="trfSendBtn" onclick="openRequestConfirm()" disabled>
        <i class="fa-solid fa-paper-plane"></i> Send Request
      </button>
    </div>

    <?php if (empty($branches)): ?>
      <div class="dlv-td-empty">No other branches are on file yet.</div>
    <?php elseif (empty($myProducts)): ?>
      <div class="dlv-td-empty">Your branch has no products in its catalogue yet. Add products in the POS first, then request a transfer to restock them.</div>
    <?php else: ?>
      <div class="trf-form-row">
        <label>Request from</label>
        <?php $hasNearby = (bool) array_filter($branches, fn($b) => $b['nearby']); ?>
        <div class="pos-csel" id="trfSourceCsel">
          <button type="button" class="pos-csel-btn" aria-haspopup="listbox" aria-expanded="false">
            <span class="pos-csel-val is-ph">— choose a branch —</span>
            <svg class="pos-csel-chev" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 4.5L6 8l3.5-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div class="pos-csel-panel" role="listbox" aria-label="Request from branch">
            <?php if ($hasNearby): ?>
            <div class="pos-csel-group"><i class="fa-solid fa-location-dot"></i> Nearby<?= $myRegion !== '' ? ' — ' . htmlspecialchars($myRegion) : '' ?></div>
            <?php foreach ($branches as $b): if (!$b['nearby']) continue; ?>
            <div class="pos-csel-opt" role="option" data-value="<?= htmlspecialchars($b['branch']) ?>"><span><?= htmlspecialchars($b['branch']) ?></span><i class="fa-solid fa-check pos-csel-check"></i></div>
            <?php endforeach; ?>
            <div class="pos-csel-group"><i class="fa-solid fa-store"></i> Other branches</div>
            <?php else: ?>
            <div class="pos-csel-group"><i class="fa-solid fa-store"></i> Branches</div>
            <?php endif; ?>
            <?php foreach ($branches as $b): if ($b['nearby']) continue; ?>
            <div class="pos-csel-opt" role="option" data-value="<?= htmlspecialchars($b['branch']) ?>"><span><?= htmlspecialchars($b['branch']) ?><?= $b['region'] !== '' ? ' <span class="pos-csel-sub">· ' . htmlspecialchars($b['region']) . '</span>' : '' ?></span><i class="fa-solid fa-check pos-csel-check"></i></div>
            <?php endforeach; ?>
          </div>
          <select id="trfSourceBranch" class="pos-csel-native" tabindex="-1" aria-hidden="true">
            <option value="">— choose a branch —</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= htmlspecialchars($b['branch']) ?>"><?= htmlspecialchars($b['branch']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label>Note</label>
        <input type="text" id="trfNote" class="trf-input trf-grow" placeholder="e.g. Ran low after a big corporate order">
        <label>Search</label>
        <input type="text" id="trfProductSearch" class="trf-input trf-grow" placeholder="Product name or SKU…" oninput="renderRequestRows()">
      </div>

      <p class="preview-note" id="trfRequestNote"></p>

      <div class="report-table-wrap">
        <table class="dlv-doc-table trf-req-table">
          <thead><tr>
            <th>Product</th><th>SKU</th><th class="col-r">Here Now</th><th class="col-r">Request Qty</th>
          </tr></thead>
          <tbody id="trfReqBody"></tbody>
        </table>
      </div>
      <div class="pagination" id="trfReqPager"></div>
    <?php endif; ?>
  </div>

  <!-- ── Incoming requests ── -->
  <div class="chart-card">
    <div class="chart-title">Incoming Requests</div>
    <div class="chart-subtitle">Other branches asking <?= htmlspecialchars($branch) ?> for stock</div>
    <div class="report-table-wrap">
      <table class="dlv-doc-table">
        <thead><tr>
          <th>Reference</th><th>From Branch</th><th>Requested</th><th class="col-r">Lines</th><th class="col-r">Units</th>
          <th>Status</th><th class="col-r">Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($incoming)): ?>
          <tr><td colspan="7" class="dlv-td-empty">No branch has requested a transfer from you yet.</td></tr>
        <?php else: foreach ($incoming as $i => $t): [$lbl, $cls] = trfBadge($t['status']); $act = $t['status'] === 'requested'; ?>
          <tr class="<?= $act ? 'dlv-tr-new' : '' ?>">
            <td class="dlv-mono"><?= htmlspecialchars($t['reference']) ?></td>
            <td><strong><?= htmlspecialchars($t['requesting_branch']) ?></strong></td>
            <td class="dlv-dim"><?= htmlspecialchars(trfWhen($t['requested_at'])) ?><br><span class="dlv-sub">by <?= htmlspecialchars($t['requested_by_name'] ?? '—') ?></span></td>
            <td class="col-r"><strong><?= (int)$t['line_count'] ?></strong></td>
            <td class="col-r"><strong><?= (int)$t['unit_count'] ?></strong></td>
            <td><span class="trf-badge <?= $cls ?>"><?= $lbl ?></span></td>
            <td class="col-r">
              <button class="dlv-open-btn <?= $act ? '' : 'dlv-open-btn--ghost' ?>" onclick="openIncoming(<?= $i ?>)"><?= $act ? 'REVIEW' : 'VIEW' ?></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── My requests ── -->
  <div class="chart-card">
    <div class="chart-title">My Requests</div>
    <div class="chart-subtitle">Transfers <?= htmlspecialchars($branch) ?> has asked other branches for</div>
    <div class="report-table-wrap">
      <table class="dlv-doc-table">
        <thead><tr>
          <th>Reference</th><th>Source Branch</th><th>Requested</th><th class="col-r">Lines</th><th class="col-r">Units</th>
          <th>Status</th><th>Notes</th><th class="col-r">Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($outgoing)): ?>
          <tr><td colspan="8" class="dlv-td-empty">You haven't requested any transfers yet. Use the form above to ask a nearby branch for stock.</td></tr>
        <?php else: foreach ($outgoing as $i => $t): [$lbl, $cls] = trfBadge($t['status']);
            $canReceive = $t['status'] === 'shipped';
            $canCancel  = $t['status'] === 'requested';
        ?>
          <tr class="<?= $canReceive ? 'dlv-tr-new' : '' ?>">
            <td class="dlv-mono"><?= htmlspecialchars($t['reference']) ?></td>
            <td><strong><?= htmlspecialchars($t['source_branch']) ?></strong></td>
            <td class="dlv-dim"><?= htmlspecialchars(trfWhen($t['requested_at'])) ?></td>
            <td class="col-r"><strong><?= (int)$t['line_count'] ?></strong></td>
            <td class="col-r"><strong><?= (int)$t['unit_count'] ?></strong></td>
            <td><span class="trf-badge <?= $cls ?>"><?= $lbl ?></span></td>
            <td class="dlv-dim">
              <?php if ($t['status'] === 'rejected'): ?>
                <span class="dlv-sub dlv-sub--warn"><?= htmlspecialchars($t['source_remarks'] ?: 'Declined — no details') ?></span>
              <?php elseif ($t['status'] === 'received'): ?>
                <span class="dlv-sub"><?= htmlspecialchars(trfWhen($t['received_at'])) ?></span>
              <?php else: ?><span class="dlv-sub">—</span><?php endif; ?>
            </td>
            <td class="col-r" style="white-space:nowrap;">
              <button class="dlv-open-btn <?= $canReceive ? '' : 'dlv-open-btn--ghost' ?>" onclick="openOutgoing(<?= $i ?>)"><?= $canReceive ? 'RECEIVE' : 'VIEW' ?></button>
              <?php if ($canCancel): ?><button class="trf-btn-danger" onclick="cancelRequest(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['reference'])) ?>')">Cancel</button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Incoming review / approve modal ── -->
<div class="modal-overlay" id="trfIncomingModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 class="modal-title" id="trfIncRef">TRANSFER</h2>
        <p class="modal-subtitle" id="trfIncMeta"></p>
      </div>
      <button class="modal-close" onclick="closeModals()">&times;</button>
    </div>

    <div id="trfIncNote" class="dlv-note" style="display:none"></div>
    <p class="dlv-instruct" id="trfIncInstruct">Set how many of each you can actually send. Anything above what's on your shelf is capped automatically.</p>

    <div class="dlv-check-wrap">
      <table class="dlv-check-table">
        <thead><tr><th>Product</th><th>SKU</th><th class="col-r">Requested</th><th class="col-r">Here</th><th class="col-c">Ship</th></tr></thead>
        <tbody id="trfIncBody"></tbody>
      </table>
    </div>

    <div id="trfIncError" class="dlv-error" style="display:none"></div>

    <div class="modal-footer" id="trfIncFooter">
      <button class="btn-cancel" onclick="openDecline()">Decline request</button>
      <button class="btn-complete" id="trfApproveBtn" onclick="submitApprove()">APPROVE &amp; SHIP</button>
    </div>
    <div class="modal-footer" id="trfIncViewFooter" style="display:none">
      <button class="btn-cancel" onclick="closeModals()">Close</button>
    </div>
  </div>
</div>

<!-- ── Decline modal ── -->
<div class="modal-overlay" id="trfDeclineModal" style="display:none">
  <div class="modal modal--sm">
    <div class="modal-header"><div><h2 class="modal-title">Decline request</h2></div><button class="modal-close" onclick="closeDecline()">&times;</button></div>
    <p class="dlv-instruct">Let the requesting branch know why (out of stock, needed here, etc.). No stock changes.</p>
    <textarea id="trfDeclineText" class="dlv-textarea" placeholder="e.g. We're low on this ourselves after weekend sales."></textarea>
    <div id="trfDeclineError" class="dlv-error" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeDecline()">Cancel</button>
      <button class="btn-complete" id="trfDeclineBtn" onclick="submitDecline()">Send</button>
    </div>
  </div>
</div>

<!-- ── Outgoing receive / view modal ── -->
<div class="modal-overlay" id="trfOutgoingModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 class="modal-title" id="trfOutRef">TRANSFER</h2>
        <p class="modal-subtitle" id="trfOutMeta"></p>
      </div>
      <button class="modal-close" onclick="closeModals()">&times;</button>
    </div>

    <div id="trfOutNote" class="dlv-note" style="display:none"></div>
    <p class="dlv-instruct" id="trfOutInstruct">Check what physically arrived against the shipped quantities, then confirm to add it to your stock.</p>

    <div class="dlv-check-wrap">
      <table class="dlv-check-table">
        <thead><tr><th>Product</th><th>SKU</th><th class="col-r">Requested</th><th class="col-r">Shipped</th><th class="col-c">OK?</th></tr></thead>
        <tbody id="trfOutBody"></tbody>
      </table>
    </div>

    <div id="trfOutError" class="dlv-error" style="display:none"></div>

    <div class="modal-footer" id="trfOutFooter">
      <button class="btn-cancel" onclick="closeModals()">Not yet</button>
      <button class="btn-complete" id="trfReceiveBtn" onclick="submitReceive()" disabled>CONFIRM RECEIPT</button>
    </div>
    <div class="modal-footer" id="trfOutViewFooter" style="display:none">
      <button class="btn-cancel" onclick="closeModals()">Close</button>
    </div>
  </div>
</div>

<!-- ── Send-request confirm modal ── -->
<div class="modal-overlay" id="trfRequestModal" style="display:none">
  <div class="modal modal--sm">
    <div class="modal-header"><div><h2 class="modal-title">Send this request?</h2></div><button class="modal-close" onclick="closeRequestConfirm()">&times;</button></div>
    <p class="dlv-instruct">Going to <strong id="trfReqBranch"></strong>. They review it before any stock moves.</p>
    <div id="trfReqList" class="trf-line-list"></div>
    <div id="trfReqError" class="dlv-error" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeRequestConfirm()">Back</button>
      <button class="btn-complete" id="trfReqConfirmBtn" onclick="submitRequest()">Confirm &amp; Send</button>
    </div>
  </div>
</div>

<!-- ── Page data for transfers.js ── -->
<script>
const TRF = {
  branch:   <?= json_encode($branch) ?>,
  myRegion: <?= json_encode($myRegion) ?>,
  products: <?= json_encode($myProducts) ?>,
  branches: <?= json_encode($branches) ?>,
  incoming: <?= json_encode($incoming) ?>,
  outgoing: <?= json_encode($outgoing) ?>,
  buffer:   <?= (int) TRANSFER_SURPLUS_BUFFER ?>,
};
</script>
<script src="../src/transfers.js?v=20260902"></script>
</body>
</html>
