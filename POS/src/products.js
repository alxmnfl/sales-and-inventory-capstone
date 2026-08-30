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
  const icons = { 'Hydraulic Hose': '🚰', 'Other Hose': '➰', Fittings: '🔧', Ferrule: '🔩' };
  return icons[cat] || '📦';
}

function renderProducts() {
  const list = getFiltered();
  document.getElementById('itemCount').textContent = `${list.length} item${list.length !== 1 ? 's' : ''}`;

  if (!list.length) { setGridMsg('No products found.'); return; }

  document.getElementById('productGrid').innerHTML = list.map(p => {
    const inCart = cart.find(c => c.id === p.id)?.qty || 0;
    const b = stockBadge(p.stock);
    const out = p.stock === 0;
    return `
    <div class="product-card${out ? ' product-card--out' : ''}"
         ${out ? '' : `onclick="addToCart(${p.id})"`}>
      <div class="product-img">
        <span>${catIcon(p.category)}</span>
        <div class="stock-badge ${b.cls}">${b.label}</div>
        ${inCart ? `<div class="cart-qty-badge">${inCart}</div>` : ''}
      </div>
      <div class="product-info">
        <div class="product-meta">
          <span class="product-sku">${escHtml(p.sku)}</span>
          <span class="product-category">${escHtml(p.category)}</span>
        </div>
        <div class="product-name">${escHtml(p.name)}</div>
        <div class="product-footer">
          <span class="product-price">${fmt(p.price)}</span>
        </div>
      </div>
    </div>`;
  }).join('');
}