<?php
session_start();

// Accept session from main login (branch_staff) or POS-direct login
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'branch_staff') {
    $_SESSION['pos_cashier']        = strtoupper($_SESSION['user_name']);
    $_SESSION['pos_cashier_branch'] = strtoupper($_SESSION['user_branch'] ?? '');
}

if (!isset($_SESSION['pos_cashier'])) {
    header('Location: ../Landing Page/login.php');
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
<title>Lucky 8 POS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="pos.css">
</head>
<body>

<header class="pos-header">
  <div class="header-left">
    <div class="logo-box">L8</div>
    <div class="header-store">
      <div class="store-name">LUCKY 8 POS</div>
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
    <a href="logout.php" class="btn-header btn-exit">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M6 2H2v12h4M11 5l3 3-3 3M14 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      EXIT
    </a>
  </div>
</header>

<div class="pos-layout">

  <div class="pos-products">

    <div class="search-bar-wrap">
      <div class="search-bar">
        <svg class="search-icon" width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="#9CA3AF" stroke-width="1.8"/><path d="M13.5 13.5l3 3" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/></svg>
        <input type="text" id="searchInput" placeholder="Search by product name, SKU, or scan barcode…" autocomplete="off">
        <svg class="barcode-icon" width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="2" y="4" width="2" height="16" fill="#D1D5DB"/><rect x="6" y="4" width="1" height="16" fill="#D1D5DB"/><rect x="9" y="4" width="2" height="16" fill="#D1D5DB"/><rect x="13" y="4" width="1" height="16" fill="#D1D5DB"/><rect x="16" y="4" width="2" height="16" fill="#D1D5DB"/><rect x="20" y="4" width="2" height="16" fill="#D1D5DB"/></svg>
      </div>
      <div class="item-count" id="itemCount">— items</div>
      <button class="btn-add-product" onclick="openAddProduct()">+ Add Product</button>
    </div>

    <div class="category-tabs" id="categoryTabs">
      <button class="tab active" data-cat="All Products">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="1" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="1" width="6" height="6" rx="1" fill="currentColor"/><rect x="1" y="9" width="6" height="6" rx="1" fill="currentColor"/><rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor"/></svg>
        All Products
      </button>
      <button class="tab" data-cat="Hoses">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 8c0-3.3 2.7-6 6-6s6 2.7 6 6-2.7 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
        Hoses
      </button>
      <button class="tab" data-cat="Fittings">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 2l12 12M2 14L14 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Fittings
      </button>
      <button class="tab" data-cat="Couplers">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="5" cy="8" r="3" stroke="currentColor" stroke-width="1.8" fill="none"/><circle cx="11" cy="8" r="3" stroke="currentColor" stroke-width="1.8" fill="none"/></svg>
        Couplers
      </button>
      <button class="tab" data-cat="Adapters">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v12M4 6h8M4 10h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        Adapters
      </button>
      <button class="tab" data-cat="Accessories">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.2 3.2l1.4 1.4M11.4 11.4l1.4 1.4M3.2 12.8l1.4-1.4M11.4 4.6l1.4-1.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        Accessories
      </button>
    </div>

    <div class="product-grid" id="productGrid">
      <div class="grid-msg">Loading products…</div>
    </div>

  </div>

  <div class="pos-cart">
    <div class="cart-header">
      <div>
        <div class="cart-title">ACTIVE SALE</div>
        <div class="cart-count" id="cartCount">0 ITEMS</div>
      </div>
      <button class="btn-clear" id="btnClear" onclick="clearCart()">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M6 4V2h4v2M7 7v5M9 7v5M3 4l1 9h8l1-9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        CLEAR
      </button>
    </div>

    <div class="cart-items" id="cartItems">
      <div class="cart-empty">No items added yet.</div>
    </div>

    <div class="cart-footer">
      <div class="cart-totals">
        <div class="total-row">
          <span>Subtotal</span>
          <span id="cartSubtotal">₱0.00</span>
        </div>
        <div class="total-row">
          <span>VAT (12%)</span>
          <span id="cartVat">₱0.00</span>
        </div>
        <div class="total-row total-row--grand">
          <span>TOTAL</span>
          <span id="cartTotal">₱0.00</span>
        </div>
      </div>
      <button class="btn-pay" id="btnPay" onclick="openCheckout()" disabled>
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="1" y="5" width="18" height="13" rx="2" stroke="white" stroke-width="1.6"/><path d="M1 9h18" stroke="white" stroke-width="1.6"/></svg>
        PAY <span id="btnPayAmount">₱0.00</span>
      </button>
    </div>
  </div>

</div>


<div class="modal-overlay" id="checkoutModal" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h2 class="modal-title">CHECKOUT</h2>
        <p class="modal-subtitle" id="modalSubtitle"></p>
      </div>
      <button class="modal-close" onclick="closeCheckout()">✕</button>
    </div>

    <div class="payment-methods">
      <button class="payment-btn active" data-method="CASH" onclick="selectPayment(this,'CASH')">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v10M9 9.5C9 8.1 10.3 7 12 7s3 1.1 3 2.5-1.3 2.5-3 2.5-3 1.1-3 2.5S10.3 17 12 17s3-1.1 3-2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        CASH
      </button>
      <button class="payment-btn" data-method="CREDIT CARD" onclick="selectPayment(this,'CREDIT CARD')">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="1" y="5" width="22" height="15" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M1 10h22" stroke="currentColor" stroke-width="1.6"/><rect x="4" y="14" width="4" height="2" rx="0.5" fill="currentColor"/></svg>
        CREDIT CARD
      </button>
      <button class="payment-btn" data-method="CORPORATE ACCOUNT" onclick="selectPayment(this,'CORPORATE ACCOUNT')">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="2" y="7" width="20" height="15" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M8 7V5a4 4 0 018 0v2" stroke="currentColor" stroke-width="1.6"/><path d="M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        CORPORATE ACCOUNT
      </button>
    </div>

    <div id="cashSection">
      <label class="cash-label">CASH TENDERED</label>
      <div class="cash-input-wrap">
        <span class="peso-sign">₱</span>
        <input type="number" id="cashTendered" class="cash-input"
               placeholder="0.00" step="0.01" min="0" oninput="updateChange()">
      </div>
      <div class="quick-amounts" id="quickAmounts"></div>
      <div class="change-display" id="changeDisplay" style="display:none">
        <span>Change</span>
        <span id="changeAmount">₱0.00</span>
      </div>
    </div>

    <div class="order-summary">
      <div class="summary-title">ORDER SUMMARY</div>
      <div id="summaryItems"></div>
      <div class="summary-total-due">
        <span>TOTAL DUE</span>
        <span id="summaryTotal" class="total-amount">₱0.00</span>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeCheckout()">CANCEL</button>
      <button class="btn-complete" id="btnCompleteSale" onclick="completeSale()" disabled>
        ✓ COMPLETE SALE
      </button>
    </div>
  </div>
</div>


<div class="sale-complete-overlay" id="saleComplete" style="display:none">
  <div class="sale-complete-content">
    <div class="success-icon">✓</div>
    <h1 class="sale-complete-title">SALE COMPLETE</h1>
    <p class="sale-complete-date" id="saleCompleteDate"></p>

    <div class="receipt">
      <div class="receipt-header">
        <div class="logo-box" style="width:32px;height:32px;font-size:11px">L8</div>
        <div>
          <div class="receipt-store">LUCKY 8 HYDRAULICS</div>
          <div class="receipt-branch"><?= htmlspecialchars($branch) ?></div>
        </div>
      </div>
      <div class="receipt-body">
        <div class="receipt-row">
          <span>TRANSACTION ID</span>
          <span id="receiptTxId" class="receipt-val-bold"></span>
        </div>
        <div class="receipt-row">
          <span>TIME</span>
          <span id="receiptTime"></span>
        </div>
        <div class="receipt-row">
          <span>CASHIER</span>
          <span id="receiptCashier"></span>
        </div>
        <div class="receipt-divider"></div>
        <div id="receiptItems"></div>
        <div class="receipt-divider"></div>
        <div class="receipt-row">
          <span>Subtotal</span>
          <span id="receiptSubtotal"></span>
        </div>
        <div class="receipt-row">
          <span>VAT (12%)</span>
          <span id="receiptVat"></span>
        </div>
        <div class="receipt-row receipt-total">
          <span>TOTAL</span>
          <span id="receiptTotal"></span>
        </div>
      </div>
    </div>

    <div class="sale-complete-actions">
      <button class="btn-new-sale" onclick="newSale()">+ NEW SALE</button>
    </div>
  </div>
</div>

<!-- ═══ PRODUCT FORM MODAL ═══ -->
<div class="modal-overlay" id="productModal" style="display:none">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <div>
        <h2 class="modal-title" id="productModalTitle">Add Product</h2>
        <p class="modal-subtitle">Fill in the product details below</p>
      </div>
      <button class="modal-close" onclick="closeProductModal()">✕</button>
    </div>

    <div id="productFormError" class="pf-error" style="display:none"></div>

    <div class="form-group">
      <label>SKU</label>
      <input type="text" id="pSku" placeholder="e.g. HH-R2AT-038-050" autocomplete="off">
    </div>
    <div class="form-group">
      <label>Product Name</label>
      <input type="text" id="pName" placeholder="Full product name" autocomplete="off">
    </div>
    <div class="form-group">
      <label>Category</label>
      <div class="cat-drop" id="catDrop">
        <button type="button" class="cat-drop__btn" id="catDropBtn">
          <span class="cat-drop__icon"><i class="fa-solid fa-tag" id="catDropIco"></i></span>
          <span class="cat-drop__text" id="catDropText">— Select category —</span>
          <i class="fa-solid fa-chevron-down cat-drop__arrow" id="catDropArrow"></i>
        </button>
        <div class="cat-drop__menu" id="catDropMenu">
          <div class="cat-drop__item" data-val="Hoses"       data-ico="fa-droplet">
            <span class="cat-drop__item-icon"><i class="fa-solid fa-droplet"></i></span>Hoses
          </div>
          <div class="cat-drop__item" data-val="Fittings"    data-ico="fa-wrench">
            <span class="cat-drop__item-icon"><i class="fa-solid fa-wrench"></i></span>Fittings
          </div>
          <div class="cat-drop__item" data-val="Couplers"    data-ico="fa-link">
            <span class="cat-drop__item-icon"><i class="fa-solid fa-link"></i></span>Couplers
          </div>
          <div class="cat-drop__item" data-val="Adapters"    data-ico="fa-plug">
            <span class="cat-drop__item-icon"><i class="fa-solid fa-plug"></i></span>Adapters
          </div>
          <div class="cat-drop__item" data-val="Accessories" data-ico="fa-toolbox">
            <span class="cat-drop__item-icon"><i class="fa-solid fa-toolbox"></i></span>Accessories
          </div>
        </div>
        <select id="pCategory" style="display:none">
          <option value="">— Select category —</option>
          <option value="Hoses">Hoses</option>
          <option value="Fittings">Fittings</option>
          <option value="Couplers">Couplers</option>
          <option value="Adapters">Adapters</option>
          <option value="Accessories">Accessories</option>
        </select>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label>Price (₱)</label>
        <input type="number" id="pPrice" step="0.01" min="0.01" placeholder="0.00">
      </div>
      <div class="form-group">
        <label>Stock (qty)</label>
        <input type="number" id="pStock" min="0" placeholder="0">
      </div>
    </div>
    <input type="hidden" id="pId">

    <div class="modal-footer" style="margin-top:8px">
      <button class="btn-cancel" id="btnDeleteProduct" onclick="confirmDeleteProduct()" style="display:none;border-color:#FCA5A5;color:#DC2626">
        🗑 DELETE
      </button>
      <button class="btn-cancel" onclick="closeProductModal()">CANCEL</button>
      <button class="btn-complete" id="btnSaveProduct" onclick="saveProduct()">
        SAVE PRODUCT
      </button>
    </div>
  </div>
</div>

<script>const CASHIER = <?= json_encode($cashier) ?>;</script>
<script src="pos.js"></script>
</body>
</html>
