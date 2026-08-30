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

require_once '../../Landing Page/php/delivery_schema.php';
ensure_delivery_schema($conn);

$cashier = $_SESSION['pos_cashier'];
$branch  = strtoupper($_SESSION['pos_cashier_branch'] ?? 'MAIN HUB');

/* ── Deliveries addressed to this branch ── */
$deliveries = [];
$stmt = $conn->prepare("
    SELECT d.*,
           (SELECT COUNT(*) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) AS line_count,
           (SELECT COALESCE(SUM(qty_sent),0) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) AS unit_count
    FROM inventory_deliveries d
    WHERE d.branch = ?
    ORDER BY (d.status = 'sent') DESC, d.created_at DESC
    LIMIT 100
");
$stmt->bind_param('s', $branch);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['items'] = [];
    $deliveries[$row['id']] = $row;
}
$stmt->close();

if ($deliveries) {
    $ids = implode(',', array_map('intval', array_keys($deliveries)));
    $res = $conn->query("SELECT delivery_id, sku, name, category, qty_sent
                         FROM inventory_delivery_items WHERE delivery_id IN ($ids) ORDER BY name");
    while ($row = $res->fetch_assoc()) {
        $deliveries[(int)$row['delivery_id']]['items'][] = [
            'sku'      => $row['sku'],
            'name'     => $row['name'],
            'category' => $row['category'],
            'qty_sent' => (int)$row['qty_sent'],
        ];
    }
}
$deliveries = array_values($deliveries);
$pendingCount = $receivedCount = $disputedCount = 0;
foreach ($deliveries as $d) {
    if ($d['status'] === 'sent')     $pendingCount++;
    if ($d['status'] === 'received') $receivedCount++;
    if ($d['status'] === 'disputed') $disputedCount++;
}

$conn->close();

function dlvBadge(string $s): array {
    return [
        'sent'      => ['NEEDS CHECKING', 'dlv-badge--new'],
        'received'  => ['RECEIVED',       'dlv-badge--done'],
        'disputed'  => ['REPORTED',       'dlv-badge--warn'],
        'cancelled' => ['CANCELLED',      'dlv-badge--muted'],
    ][$s] ?? [strtoupper($s), 'dlv-badge--muted'];
}

function dlvWhen(?string $s): string {
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
<title>Lucky 8 POS — Deliveries</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="../style/base.css">
<link rel="stylesheet" href="../style/header.css?v=20260830">
<link rel="stylesheet" href="../style/modal.css">
<link rel="stylesheet" href="../style/deliveries.css?v=20260830j">
</head>
<body>

<header class="pos-header">
  <div class="header-left">
    <div class="logo-box">L8</div>
    <div class="header-store">
      <div class="store-name">INVENTORY DELIVERIES</div>
      <div class="store-branch"><?= htmlspecialchars($branch) ?></div>
    </div>
  </div>
  <div class="header-center">
    <div class="session-badge">
      <span class="session-dot"></span>
      SESSION: <?= htmlspecialchars($cashier) ?>
    </div>
  </div>
  <div class="header-right">
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
    <h1>Incoming Deliveries</h1>
    <p>Open a delivery document, check every product and quantity against what physically arrived, then confirm.</p>
  </div>

  <div id="dlvSuccess" class="dlv-success" style="display:none">
    <i class="fa-solid fa-circle-check"></i>
    <div>
      <div class="dlv-success-title" id="dlvSuccessTitle"></div>
      <div class="dlv-success-sub" id="dlvSuccessSub"></div>
    </div>
    <button class="dlv-success-close" onclick="document.getElementById('dlvSuccess').style.display='none'">&times;</button>
  </div>

  <?php if ($pendingCount): ?>
  <div class="dlv-alert">
    <i class="fa-solid fa-truck-fast"></i>
    <div><strong><?= $pendingCount ?> delivery <?= $pendingCount > 1 ? 'documents are' : 'document is' ?></strong> waiting to be checked and confirmed.</div>
  </div>
  <?php endif; ?>

  <div class="kpi-grid">
    <div class="kpi-card<?= $pendingCount ? ' kpi-card--alert' : '' ?>">
      <div class="kpi-top"><span class="kpi-label">Awaiting Check</span><div class="kpi-icon orange"><i class="fa-solid fa-truck-fast"></i></div></div>
      <div class="kpi-value"><?= $pendingCount ?></div>
      <div class="kpi-meta">sent, not yet confirmed</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-top"><span class="kpi-label">Received</span><div class="kpi-icon green"><i class="fa-solid fa-circle-check"></i></div></div>
      <div class="kpi-value"><?= $receivedCount ?></div>
      <div class="kpi-meta">confirmed &amp; added to stock</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-top"><span class="kpi-label">Reported</span><div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
      <div class="kpi-value"><?= $disputedCount ?></div>
      <div class="kpi-meta">flagged to head office</div>
    </div>
  </div>

  <div class="chart-card">
    <div class="chart-title">Delivery Documents</div>
    <div class="chart-subtitle">Most recent documents for <?= htmlspecialchars($branch) ?></div>

    <div class="report-table-wrap">
      <table class="dlv-doc-table">
        <thead><tr>
          <th>Reference</th><th>Sent</th><th class="col-r">Products</th><th class="col-r">Units</th>
          <th>Status</th><th>Received / Notes</th><th class="col-r">Action</th>
        </tr></thead>
        <tbody>
        <?php if (empty($deliveries)): ?>
          <tr><td colspan="7" class="dlv-td-empty">No deliveries for <?= htmlspecialchars($branch) ?> yet. When head office sends a delivery document, it shows up here.</td></tr>
        <?php else: foreach ($deliveries as $i => $d):
            [$blabel, $bclass] = dlvBadge($d['status']);
            $actionable = $d['status'] === 'sent';
        ?>
          <tr class="<?= $actionable ? 'dlv-tr-new' : '' ?>">
            <td class="dlv-mono"><?= htmlspecialchars($d['reference']) ?></td>
            <td class="dlv-dim"><?= htmlspecialchars(dlvWhen($d['created_at'])) ?><br><span class="dlv-sub">by <?= htmlspecialchars($d['created_by_name'] ?? 'Head office') ?></span></td>
            <td class="col-r"><strong><?= (int)$d['line_count'] ?></strong></td>
            <td class="col-r"><strong><?= (int)$d['unit_count'] ?></strong></td>
            <td><span class="dlv-badge <?= $bclass ?>"><?= $blabel ?></span></td>
            <td class="dlv-dim">
              <?php if ($d['status'] === 'received'): ?>
                <?= htmlspecialchars(dlvWhen($d['received_at'])) ?><br><span class="dlv-sub">by <?= htmlspecialchars($d['received_by_name'] ?? '—') ?></span>
              <?php elseif ($d['status'] === 'disputed'): ?>
                <span class="dlv-sub dlv-sub--warn"><?= htmlspecialchars($d['staff_remarks'] ?: 'Flagged — no details') ?></span>
              <?php else: ?>
                <span class="dlv-sub">—</span>
              <?php endif; ?>
            </td>
            <td class="col-r">
              <button class="dlv-open-btn <?= $actionable ? '' : 'dlv-open-btn--ghost' ?>" onclick="openReview(<?= $i ?>)">
                <?= $actionable ? 'OPEN &amp; CHECK' : 'VIEW' ?>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Review / checklist modal ── -->
<div class="modal-overlay" id="dlvReviewModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 class="modal-title" id="dlvReviewRef">DELIVERY</h2>
        <p class="modal-subtitle" id="dlvReviewMeta"></p>
      </div>
      <button class="modal-close" onclick="closeReview()">&times;</button>
    </div>

    <div id="dlvReviewNote" class="dlv-note" style="display:none"></div>

    <p class="dlv-instruct">Tick each line once you've confirmed the product and quantity match what arrived.</p>

    <div class="dlv-check-wrap">
      <table class="dlv-check-table">
        <thead><tr>
          <th>Product</th><th>SKU</th><th class="col-r">Qty Sent</th><th class="col-c">OK?</th>
        </tr></thead>
        <tbody id="dlvCheckBody"></tbody>
      </table>
    </div>

    <div id="dlvReviewError" class="dlv-error" style="display:none"></div>

    <div class="modal-footer" id="dlvReviewFooter">
      <button class="btn-cancel" onclick="openDispute()">Report a problem</button>
      <button class="btn-complete" id="dlvCompleteBtn" onclick="askConfirm()" disabled>YES, COMPLETE</button>
    </div>

    <!-- inline confirmation — pops in place of the footer -->
    <div class="dlv-inline-confirm" id="dlvInlineConfirm" hidden>
      <div class="dlv-ic-q">Are all delivered items complete and correct?</div>
      <div class="dlv-ic-sub">This adds every listed quantity to <strong id="dlvConfirmBranch"></strong>'s stock and can't be undone here.</div>
      <div id="dlvConfirmError" class="dlv-error" style="display:none"></div>
      <div class="dlv-ic-actions">
        <button class="btn-cancel" onclick="cancelConfirm()">Go back</button>
        <button class="btn-complete" id="dlvConfirmBtn" onclick="submitComplete()">Yes, add to stock</button>
      </div>
    </div>

    <div class="modal-footer" id="dlvViewFooter" style="display:none">
      <button class="btn-cancel" onclick="closeReview()">Close</button>
    </div>
  </div>
</div>

<!-- ── Report a problem ── -->
<div class="modal-overlay" id="dlvDisputeModal" style="display:none">
  <div class="modal modal--sm">
    <div class="modal-header">
      <div><h2 class="modal-title">Report a problem</h2></div>
      <button class="modal-close" onclick="closeDispute()">&times;</button>
    </div>
    <p class="dlv-instruct">Tell head office what's wrong (missing items, wrong quantities, damage). Stock will not be changed.</p>
    <textarea id="dlvDisputeText" class="dlv-textarea" placeholder="e.g. FERRULE 1&quot; 2-WIRE — only 8 arrived, document says 12."></textarea>
    <div id="dlvDisputeError" class="dlv-error" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeDispute()">Cancel</button>
      <button class="btn-complete" id="dlvDisputeBtn" onclick="submitDispute()">Send report</button>
    </div>
  </div>
</div>

<script>
const DELIVERIES = <?= json_encode($deliveries) ?>;
const BRANCH = <?= json_encode($branch) ?>;
</script>
<script src="../src/deliveries.js?v=20260830d"></script>
</body>
</html>
