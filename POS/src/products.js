async function loadProducts() {
  try {
    const res = await fetch('../api/products.php');
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
      </div>
      <div class="product-info">
        <div class="product-sku">${escHtml(p.sku)}</div>
        <div class="product-name">${escHtml(p.name)}</div>
        <div class="product-footer">
          <span class="product-price">${fmt(p.price)}</span>
          <div class="product-footer-actions">
            <button class="btn-stock" title="Adjust stock" onclick="event.stopPropagation(); openStockModal(${p.id})">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M11.3 2.3a1.5 1.5 0 0 1 2.1 2.1L5 12.8l-2.8.7.7-2.8 8.4-8.4Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
            </button>
            <button class="btn-add" ${p.stock === 0 ? 'disabled' : ''}
                    onclick="addToCart(${p.id})">+</button>
          </div>
        </div>
      </div>
    </div>`;
  }).join('');
}