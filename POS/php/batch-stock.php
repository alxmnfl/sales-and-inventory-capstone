<?php
session_start();

// Accept session from main login (branch_staff) or POS-direct login
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'branch_staff') {
    $_SESSION['pos_cashier']        = strtoupper($_SESSION['user_name']);
    $_SESSION['pos_cashier_branch'] = strtoupper($_SESSION['user_branch'] ?? '');
}

if (!isset($_SESSION['pos_cashier'])) {
    header('Location: ../../Landing Page/login.php');
    exit;
}

$cashier = $_SESSION['pos_cashier'];
$branch  = $_SESSION['pos_cashier_branch'] ?? 'MAIN HUB';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 POS — Batch Stock Adjustment</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet" href="../style/base.css">
<link rel="stylesheet" href="../style/header.css">
<link rel="stylesheet" href="../style/products.css">
<link rel="stylesheet" href="../style/modal.css">
<link rel="stylesheet" href="../style/batch-stock.css">

</head>
<body>

<header class="pos-header">
  <div class="header-left">
    <div class="logo-box">L8</div>
    <div class="header-store">
      <div class="store-name">BATCH STOCK ADJUSTMENT</div>
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

<div class="batch-layout">

  <div id="successBanner" class="success-banner" style="display:none">
    <i class="fa-solid fa-circle-check"></i>
    <div>
      <div class="success-banner-title" id="successBannerTitle"></div>
      <div class="success-banner-sub" id="successBannerSub"></div>
    </div>
    <button class="success-banner-close" onclick="dismissBanner()">✕</button>
  </div>

  <div class="batch-toolbar">
    <div class="search-bar-wrap">
      <div class="search-bar">
        <svg class="search-icon" width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="#9CA3AF" stroke-width="1.8"/><path d="M13.5 13.5l3 3" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/></svg>
        <input type="text" id="searchInput" placeholder="Search by product name or SKU…" autocomplete="off">
      </div>
    </div>
    <div class="category-tabs" id="categoryTabs">
      <button class="tab active" data-cat="All Products">All Products</button>
      <button class="tab" data-cat="Hoses">Hoses</button>
      <button class="tab" data-cat="Fittings">Fittings</button>
      <button class="tab" data-cat="Couplers">Couplers</button>
      <button class="tab" data-cat="Adapters">Adapters</button>
      <button class="tab" data-cat="Accessories">Accessories</button>
    </div>
  </div>

  <div class="batch-table-wrap">
    <table class="batch-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>SKU</th>
          <th class="col-r">Current Stock</th>
          <th>Adjustment Type</th>
          <th class="col-r">Quantity</th>
          <th class="col-r">New Stock</th>
          <th>Reason / Remarks</th>
        </tr>
      </thead>
      <tbody id="batchTableBody">
        <tr><td colspan="7" class="batch-loading">Loading products…</td></tr>
      </tbody>
    </table>
  </div>

</div>

<div class="batch-summary-bar">
  <div class="batch-summary-stats">
    <div class="batch-stat">
      <span class="batch-stat-value" id="statCount">0</span>
      <span class="batch-stat-label">Products Adjusted</span>
    </div>
    <div class="batch-stat">
      <span class="batch-stat-value batch-stat-pos" id="statPositive">+0</span>
      <span class="batch-stat-label">Total Increase</span>
    </div>
    <div class="batch-stat">
      <span class="batch-stat-value batch-stat-neg" id="statNegative">−0</span>
      <span class="batch-stat-label">Total Decrease</span>
    </div>
  </div>
  <div class="batch-summary-actions">
    <button class="btn-cancel" id="btnReset" onclick="resetAll()">RESET</button>
    <button class="btn-complete" id="btnReview" onclick="openConfirmModal()" disabled>REVIEW &amp; SAVE ALL ADJUSTMENTS</button>
  </div>
</div>

<div class="modal-overlay" id="confirmModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 class="modal-title">Confirm Batch Adjustment</h2>
        <p class="modal-subtitle" id="confirmModalSubtitle"></p>
      </div>
      <button class="modal-close" onclick="closeConfirmModal()">✕</button>
    </div>

    <div class="order-summary" style="margin-top:0;padding-top:0;border-top:none;">
      <div class="summary-title">PRODUCTS TO BE ADJUSTED</div>
      <div id="confirmItems"></div>
    </div>

    <div id="confirmError" class="batch-error" style="display:none"></div>

    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeConfirmModal()">CANCEL</button>
      <button class="btn-complete" id="btnSubmitBatch" onclick="submitBatch()">✓ SAVE ALL ADJUSTMENTS</button>
    </div>
  </div>
</div>

<script>const CASHIER = <?= json_encode($cashier) ?>; const BRANCH = <?= json_encode($branch) ?>;</script>

<script src="../src/batch-stock.js"></script>

</body>
</html>
