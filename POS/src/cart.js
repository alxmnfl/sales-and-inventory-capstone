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
      <div class="cart-item-head">
        <div class="cart-item-name">${escHtml(item.name)}</div>
        <button class="cart-item-del" onclick="removeFromCart(${item.id})" aria-label="Remove ${escHtml(item.name)}" title="Remove">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M6 4V2h4v2M7 7v5M9 7v5M3 4l1 9h8l1-9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>
      <div class="cart-item-row">
        <div class="qty-controls">
          <button class="qty-btn" onclick="changeQty(${item.id}, -1)">−</button>
          <span class="qty-num">${item.qty}</span>
          <button class="qty-btn" onclick="changeQty(${item.id}, 1)">+</button>
        </div>
        <div class="cart-item-amounts">
          <span class="cart-item-unit">${fmt(item.price)} each</span>
          <span class="cart-item-total">${fmt(item.price * item.qty)}</span>
        </div>
      </div>
    </div>
  `).join('');

  renderProducts();
}