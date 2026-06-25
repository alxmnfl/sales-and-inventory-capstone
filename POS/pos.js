// Lucky 8 POS — Frontend Logic

const VAT_RATE = 0.12;
let products = [];
let cart = [];
let selectedPayment = 'CASH';
let currentCategory = 'All Products';
let searchQuery = '';

// ─── INIT ───────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  loadProducts();

  document.getElementById('searchInput').addEventListener('input', e => {
    searchQuery = e.target.value.trim().toLowerCase();
    renderProducts();
  });

  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      currentCategory = tab.dataset.cat;
      renderProducts();
    });
  });
});

// ─── PRODUCTS ───────────────────────────────────────────────────────

async function loadProducts() {
  try {
    const res = await fetch('api/products.php');
    const data = await res.json();
    if (data.success) {
      products = data.products;
      renderProducts();
    } else {
      setGridMsg('Failed to load products.');
    }
  } catch {
    setGridMsg('Could not reach the server.');
  }
}

function setGridMsg(msg) {
  document.getElementById('productGrid').innerHTML = `<div class="grid-msg">${msg}</div>`;
}

function getFiltered() {
  return products.filter(p => {
    const catOk  = currentCategory === 'All Products' || p.category === currentCategory;
    const srchOk = !searchQuery || p.name.toLowerCase().includes(searchQuery) || p.sku.toLowerCase().includes(searchQuery);
    return catOk && srchOk;
  });
}

function stockBadge(stock) {
  if (stock === 0)  return { label: 'OUT OF STOCK',         cls: 'badge-gray' };
  if (stock <= 3)   return { label: `ONLY ${stock} LEFT`,   cls: 'badge-amber' };
  if (stock >= 15)  return { label: '15+ AVAILABLE',        cls: 'badge-green' };
  return               { label: `${stock} IN STOCK`,        cls: 'badge-blue' };
}

function catIcon(cat) {
  const icons = { Hoses: '🪢', Fittings: '🔧', Couplers: '🔗', Adapters: '⚙️', Accessories: '🔩' };
  return icons[cat] || '📦';
}

function renderProducts() {
  const list = getFiltered();
  document.getElementById('itemCount').textContent = `${list.length} item${list.length !== 1 ? 's' : ''}`;

  if (!list.length) { setGridMsg('No products found.'); return; }

  document.getElementById('productGrid').innerHTML = list.map(p => {
    const inCart = cart.find(c => c.id === p.id)?.qty || 0;
    const b = stockBadge(p.stock);
    return `
    <div class="product-card">
      <div class="product-img">
        <span>${catIcon(p.category)}</span>
        <div class="stock-badge ${b.cls}">${b.label}</div>
        ${inCart ? `<div class="cart-qty-badge">${inCart}</div>` : ''}
        <div class="card-edit-overlay">
          <button class="card-action-btn card-action-btn--edit"
                  onclick="openEditProduct(${p.id})" title="Edit product">✏</button>
          <button class="card-action-btn card-action-btn--delete"
                  onclick="confirmDeleteProduct(${p.id})" title="Delete product">🗑</button>
        </div>
      </div>
      <div class="product-info">
        <div class="product-sku">${escHtml(p.sku)}</div>
        <div class="product-name">${escHtml(p.name)}</div>
        <div class="product-footer">
          <span class="product-price">${fmt(p.price)}</span>
          <button class="btn-add" ${p.stock === 0 ? 'disabled' : ''}
                  onclick="addToCart(${p.id})">+</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

// ─── CART ────────────────────────────────────────────────────────────

function addToCart(productId) {
  const p = products.find(x => x.id === productId);
  if (!p || p.stock === 0) return;
  const item = cart.find(c => c.id === productId);
  if (item) {
    if (item.qty >= p.stock) return;
    item.qty++;
  } else {
    cart.push({ id: p.id, sku: p.sku, name: p.name, price: p.price, qty: 1 });
  }
  updateCart();
}

function removeFromCart(productId) {
  cart = cart.filter(c => c.id !== productId);
  updateCart();
}

function changeQty(productId, delta) {
  const item = cart.find(c => c.id === productId);
  if (!item) return;
  const p    = products.find(x => x.id === productId);
  const next = item.qty + delta;
  if (next < 1)                    { removeFromCart(productId); return; }
  if (next > (p ? p.stock : 999)) return;
  item.qty = next;
  updateCart();
}

function clearCart() {
  if (!cart.length) return;
  if (!confirm('Clear all items from the cart?')) return;
  cart = [];
  updateCart();
}

function cartTotals() {
  const subtotal = cart.reduce((s, c) => s + c.price * c.qty, 0);
  const vat      = subtotal * VAT_RATE;
  return { subtotal, vat, total: subtotal + vat };
}

function updateCart() {
  const { subtotal, vat, total } = cartTotals();
  const count = cart.reduce((s, c) => s + c.qty, 0);

  document.getElementById('cartCount').textContent   = `${count} ITEM${count !== 1 ? 'S' : ''}`;
  document.getElementById('cartSubtotal').textContent = fmt(subtotal);
  document.getElementById('cartVat').textContent      = fmt(vat);
  document.getElementById('cartTotal').textContent    = fmt(total);
  document.getElementById('btnPayAmount').textContent = fmt(total);
  document.getElementById('btnPay').disabled          = cart.length === 0;

  if (!cart.length) {
    document.getElementById('cartItems').innerHTML = '<div class="cart-empty">No items added yet.</div>';
    renderProducts();
    return;
  }

  document.getElementById('cartItems').innerHTML = cart.map(item => `
    <div class="cart-item">
      <div class="cart-item-name">${escHtml(item.name)}</div>
      <div class="cart-item-price-unit">${fmt(item.price)} × ${item.qty}</div>
      <div class="cart-item-row">
        <div class="qty-controls">
          <button class="qty-btn" onclick="changeQty(${item.id}, -1)">−</button>
          <span class="qty-num">${item.qty}</span>
          <button class="qty-btn" onclick="changeQty(${item.id}, 1)">+</button>
        </div>
        <span class="cart-item-total">${fmt(item.price * item.qty)}</span>
      </div>
      <button class="btn-remove" onclick="removeFromCart(${item.id})">REMOVE</button>
    </div>
  `).join('');

  renderProducts();
}

// ─── CHECKOUT ────────────────────────────────────────────────────────

function openCheckout() {
  if (!cart.length) return;
  const { total } = cartTotals();
  const count     = cart.reduce((s, c) => s + c.qty, 0);

  document.getElementById('modalSubtitle').textContent = `${count} item${count !== 1 ? 's' : ''} · CEB-MAIN`;

  // Reset to CASH
  selectedPayment = 'CASH';
  document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('[data-method="CASH"]').classList.add('active');
  document.getElementById('cashSection').style.display     = '';
  document.getElementById('cashTendered').value            = '';
  document.getElementById('changeDisplay').style.display   = 'none';
  document.getElementById('btnCompleteSale').disabled      = true;

  // Quick amounts — nearest ₱500 above total, plus 3 more
  const quickAmts = buildQuickAmounts(total);
  document.getElementById('quickAmounts').innerHTML = quickAmts.map(a =>
    `<button class="quick-btn" onclick="setQuickAmount(${a})">${fmt(a)}</button>`
  ).join('');

  // Summary
  document.getElementById('summaryItems').innerHTML = cart.map(c => `
    <div class="summary-item">
      <span class="summary-item-name">
        ${escHtml(c.name)} <span class="summary-item-qty">×${c.qty}</span>
      </span>
      <span class="summary-item-price">${fmt(c.price * c.qty)}</span>
    </div>
  `).join('');
  document.getElementById('summaryTotal').textContent = fmt(total);

  document.getElementById('checkoutModal').style.display = 'flex';
}

function closeCheckout() {
  document.getElementById('checkoutModal').style.display = 'none';
}

function selectPayment(btn, method) {
  selectedPayment = method;
  document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const cashSection = document.getElementById('cashSection');
  cashSection.style.display = method === 'CASH' ? '' : 'none';

  if (method === 'CASH') {
    document.getElementById('btnCompleteSale').disabled = true;
    updateChange();
  } else {
    document.getElementById('changeDisplay').style.display  = 'none';
    document.getElementById('btnCompleteSale').disabled     = false;
  }
}

function setQuickAmount(amount) {
  document.getElementById('cashTendered').value = amount.toFixed(2);
  updateChange();
}

function updateChange() {
  const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
  const { total } = cartTotals();
  const change    = tendered - total;

  const displayEl = document.getElementById('changeDisplay');
  if (tendered > 0 && change >= 0) {
    displayEl.style.display = 'flex';
    document.getElementById('changeAmount').textContent = fmt(change);
    document.getElementById('btnCompleteSale').disabled = false;
  } else {
    displayEl.style.display = 'none';
    document.getElementById('btnCompleteSale').disabled = true;
  }
}

function buildQuickAmounts(total) {
  const step = 500;
  const base = Math.ceil(total / step) * step;
  return [base, base + step, base + step * 2, base + step * 4];
}

// ─── COMPLETE SALE ───────────────────────────────────────────────────

async function completeSale() {
  const { subtotal, vat, total } = cartTotals();
  const cashTendered = selectedPayment === 'CASH'
    ? parseFloat(document.getElementById('cashTendered').value) || 0
    : null;

  if (selectedPayment === 'CASH' && cashTendered < total) {
    alert('Insufficient cash tendered.');
    return;
  }

  const btn = document.getElementById('btnCompleteSale');
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  const payload = {
    cashier:        CASHIER,
    payment_method: selectedPayment,
    subtotal, vat, total,
    cash_tendered:  cashTendered,
    items: cart.map(c => ({
      product_id:   c.id,
      product_name: c.name,
      sku:          c.sku,
      quantity:     c.qty,
      unit_price:   c.price,
      total_price:  c.price * c.qty
    }))
  };

  try {
    const res  = await fetch('api/complete_sale.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      closeCheckout();
      cart = [];
      updateCart();
      loadProducts();       // refresh stock counts
      showReceipt(data);
    } else {
      alert(data.message || 'Failed to complete sale.');
      btn.disabled    = false;
      btn.textContent = '✓ COMPLETE SALE';
    }
  } catch {
    alert('Network error. Please try again.');
    btn.disabled    = false;
    btn.textContent = '✓ COMPLETE SALE';
  }
}

// ─── RECEIPT / SALE COMPLETE ─────────────────────────────────────────

function showReceipt(data) {
  const now = data.created_at
    ? new Date(data.created_at.replace(' ', 'T'))
    : new Date();

  document.getElementById('saleCompleteDate').textContent = now.toLocaleDateString('en-US', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });
  document.getElementById('receiptTxId').textContent    = data.transaction_id;
  document.getElementById('receiptTime').textContent    = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
  document.getElementById('receiptCashier').textContent = data.cashier;

  document.getElementById('receiptItems').innerHTML = data.items.map(i => `
    <div class="receipt-row">
      <span>${escHtml(i.product_name)} ×${i.quantity}</span>
      <span>${fmt(i.total_price)}</span>
    </div>
  `).join('');

  document.getElementById('receiptSubtotal').textContent = fmt(data.subtotal);
  document.getElementById('receiptVat').textContent      = fmt(data.vat);
  document.getElementById('receiptTotal').textContent    = fmt(data.total);

  document.getElementById('saleComplete').style.display = 'flex';
}

function newSale() {
  document.getElementById('saleComplete').style.display = 'none';
}

// ─── PRODUCT MANAGEMENT ──────────────────────────────────────────────

function openAddProduct() {
  document.getElementById('productModalTitle').textContent = 'Add Product';
  document.getElementById('pId').value    = '';
  document.getElementById('pSku').value   = '';
  document.getElementById('pName').value  = '';
  document.getElementById('pCategory').value = '';
  if (window.resetCatDrop) window.resetCatDrop();
  document.getElementById('pPrice').value = '';
  document.getElementById('pStock').value = '';
  document.getElementById('productFormError').style.display = 'none';
  document.getElementById('btnSaveProduct').textContent     = 'ADD PRODUCT';
  document.getElementById('btnDeleteProduct').style.display = 'none';
  document.getElementById('productModal').style.display     = 'flex';
  document.getElementById('pSku').focus();
}

function openEditProduct(id) {
  const p = products.find(x => x.id === id);
  if (!p) return;
  document.getElementById('productModalTitle').textContent = 'Edit Product';
  document.getElementById('pId').value       = p.id;
  document.getElementById('pSku').value      = p.sku;
  document.getElementById('pName').value     = p.name;
  document.getElementById('pCategory').value = p.category;
  if (window.setCatDrop) window.setCatDrop(p.category);
  document.getElementById('pPrice').value    = p.price;
  document.getElementById('pStock').value    = p.stock;
  document.getElementById('productFormError').style.display = 'none';
  document.getElementById('btnSaveProduct').textContent     = 'SAVE CHANGES';
  document.getElementById('btnDeleteProduct').style.display = '';
  document.getElementById('productModal').style.display     = 'flex';
}

function closeProductModal() {
  document.getElementById('productModal').style.display = 'none';
}

async function saveProduct() {
  const id       = parseInt(document.getElementById('pId').value)       || 0;
  const sku      = document.getElementById('pSku').value.trim();
  const name     = document.getElementById('pName').value.trim();
  const category = document.getElementById('pCategory').value;
  const price    = parseFloat(document.getElementById('pPrice').value);
  const stock    = parseInt(document.getElementById('pStock').value)     || 0;
  const errEl    = document.getElementById('productFormError');

  if (!sku || !name || !category || !price || price <= 0) {
    errEl.textContent = 'All fields are required and price must be greater than 0.';
    errEl.style.display = 'block';
    return;
  }
  errEl.style.display = 'none';

  const btn = document.getElementById('btnSaveProduct');
  btn.disabled    = true;
  btn.textContent = 'Saving…';

  try {
    const res  = await fetch('api/save_product.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id, sku, name, category, price, stock })
    });
    const data = await res.json();

    if (data.success) {
      closeProductModal();
      loadProducts();
    } else {
      errEl.textContent   = data.message || 'Failed to save product.';
      errEl.style.display = 'block';
    }
  } catch {
    errEl.textContent   = 'Network error. Please try again.';
    errEl.style.display = 'block';
  } finally {
    btn.disabled    = false;
    btn.textContent = id ? 'SAVE CHANGES' : 'ADD PRODUCT';
  }
}

function confirmDeleteProduct(id) {
  const targetId = id || parseInt(document.getElementById('pId').value);
  const p = products.find(x => x.id === targetId);
  if (!p) return;
  if (!confirm(`Delete "${p.name}"?\n\nThis cannot be undone.`)) return;
  deleteProduct(targetId);
}

async function deleteProduct(targetId) {
  if (!targetId) return;

  try {
    const res  = await fetch('api/delete_product.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id: targetId })
    });
    const data = await res.json();

    if (data.success) {
      cart = cart.filter(c => c.id !== targetId);
      updateCart();
      closeProductModal();
      loadProducts();
    } else {
      alert(data.message || 'Failed to delete product.');
    }
  } catch {
    alert('Network error. Please try again.');
  }
}

// ─── HELPERS ─────────────────────────────────────────────────────────

function fmt(amount) {
  return '₱' + parseFloat(amount).toLocaleString('en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2
  });
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ------ Dropdown (used in product modal) -----
(function() {
  var btn    = document.getElementById('catDropBtn');
  var menu   = document.getElementById('catDropMenu');
  var text   = document.getElementById('catDropText');
  var ico    = document.getElementById('catDropIco');
  var arrow  = document.getElementById('catDropArrow');
  var hidden = document.getElementById('pCategory');
  var wrap   = document.getElementById('catDrop');
  var open   = false;

  function show() {
    menu.style.display = 'block';
    arrow.style.transform = 'rotate(180deg)';
    btn.classList.add('cat-drop__btn--open');
    open = true;
  }
  function hide() {
    menu.style.display = 'none';
    arrow.style.transform = '';
    btn.classList.remove('cat-drop__btn--open');
    open = false;
  }

  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    open ? hide() : show();
  });

  menu.querySelectorAll('.cat-drop__item').forEach(function(item) {
    item.addEventListener('click', function() {
      var val = item.dataset.val;
      var ic  = item.dataset.ico;
      hidden.value = val;
      text.textContent = val;
      text.classList.remove('cat-drop__text--empty');
      ico.className = 'fa-solid ' + ic;
      menu.querySelectorAll('.cat-drop__item').forEach(function(i) { i.classList.remove('cat-drop__item--active'); });
      item.classList.add('cat-drop__item--active');
      hide();
    });
  });

  document.addEventListener('click', function(e) {
    if (!wrap.contains(e.target)) hide();
  });

  window.resetCatDrop = function() {
    hidden.value = '';
    text.textContent = '— Select category —';
    text.classList.add('cat-drop__text--empty');
    ico.className = 'fa-solid fa-tag';
    menu.querySelectorAll('.cat-drop__item').forEach(function(i) { i.classList.remove('cat-drop__item--active'); });
    hide();
  };

  window.setCatDrop = function(val) {
    var item = menu.querySelector('[data-val="' + val + '"]');
    if (!item) { window.resetCatDrop(); return; }
    hidden.value = val;
    text.textContent = val;
    text.classList.remove('cat-drop__text--empty');
    ico.className = 'fa-solid ' + item.dataset.ico;
    menu.querySelectorAll('.cat-drop__item').forEach(function(i) { i.classList.remove('cat-drop__item--active'); });
    item.classList.add('cat-drop__item--active');
  };

  text.classList.add('cat-drop__text--empty');
  menu.style.display = 'none';
})();