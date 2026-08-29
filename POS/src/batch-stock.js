// Lucky 8 POS — Batch Stock Adjustment

let products = [];
let currentCategory = 'All Products';
let searchQuery = '';

// productId -> { type, quantity, reason }
const pendingEdits = new Map();

// ─── HELPERS (copied from pos.js — this page intentionally doesn't load pos.js,
// since its DOMContentLoaded handler depends on functions from products.js
// that this page doesn't include) ───

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

function formatToday() {
  return new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// Appends today's date to a reason so staff don't have to type it themselves.
function reasonWithDate(reason) {
  return `${reason.trim()} (${formatToday()})`;
}

// ─── INIT ───

document.addEventListener('DOMContentLoaded', () => {
  loadProducts();

  document.getElementById('searchInput').addEventListener('input', e => {
    searchQuery = e.target.value.trim().toLowerCase();
    renderTable();
  });

  document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      currentCategory = tab.dataset.cat;
      renderTable();
    });
  });
});

async function loadProducts() {
  try {
    const res = await fetch('../api/products.php');
    const data = await res.json();
    if (data.success) {
      products = data.products;
      renderTable();
    } else {
      setTableMsg('Failed to load products.');
    }
  } catch {
    setTableMsg('Could not reach the server.');
  }
}

function setTableMsg(msg) {
  document.getElementById('batchTableBody').innerHTML =
    `<tr><td colspan="7" class="batch-loading">${escHtml(msg)}</td></tr>`;
}

function getFiltered() {
  return products.filter(p => {
    const catOk  = currentCategory === 'All Products' || p.category === currentCategory;
    const srchOk = !searchQuery || p.name.toLowerCase().includes(searchQuery) || p.sku.toLowerCase().includes(searchQuery);
    return catOk && srchOk;
  });
}

// ─── TABLE RENDER ───

function renderTable() {
  const list = getFiltered();

  if (!list.length) { setTableMsg('No products found.'); return; }

  document.getElementById('batchTableBody').innerHTML = list.map(p => {
    const edit = pendingEdits.get(p.id);
    const type = edit ? edit.type : 'add';
    const qty  = edit ? edit.quantity : '';
    const reason = edit ? edit.reason : '';
    const newStock = computeNewStock(p.stock, type, qty);
    const rowClass = edit ? 'row-modified' : '';

    return `
    <tr class="${rowClass}" data-id="${p.id}">
      <td>
        <div class="batch-prod-name">${escHtml(p.name)}</div>
        <div class="batch-prod-cat">${escHtml(p.category)}</div>
      </td>
      <td class="col-mono">${escHtml(p.sku)}</td>
      <td class="col-r">${p.stock}</td>
      <td>
        <div class="batch-type-toggle">
          <button type="button" class="batch-type-btn type-add ${type === 'add' ? 'active' : ''}"
                  data-type="add" onclick="setRowType(${p.id}, 'add')">Add (+)</button>
          <button type="button" class="batch-type-btn type-remove ${type === 'remove' ? 'active' : ''}"
                  data-type="remove" onclick="setRowType(${p.id}, 'remove')">Remove (−)</button>
        </div>
      </td>
      <td class="col-r">
        <input type="number" class="batch-qty-input" min="1" step="1" placeholder="0"
               value="${qty}" oninput="onRowChange(${p.id})">
      </td>
      <td class="col-r batch-new-stock" id="newStock-${p.id}">${edit ? newStock : '—'}</td>
      <td>
        <input type="text" class="batch-reason-input" placeholder="e.g. Stock arrival"
               value="${escHtml(reason)}" oninput="onRowChange(${p.id})">
        <div class="batch-reason-hint">Date added automatically on save</div>
      </td>
    </tr>`;
  }).join('');
}

function computeNewStock(current, type, qty) {
  const q = parseInt(qty, 10);
  if (isNaN(q) || q <= 0) return current;
  return type === 'add' ? current + q : current - q;
}

function onRowChange(productId) {
  const row = document.querySelector(`tr[data-id="${productId}"]`);
  if (!row) return;

  const product = products.find(p => p.id === productId);
  const type    = row.querySelector('.batch-type-btn.active').dataset.type;
  const qtyRaw  = row.querySelector('.batch-qty-input').value;
  const reason  = row.querySelector('.batch-reason-input').value;
  const qty     = parseInt(qtyRaw, 10);

  const newStockCell = document.getElementById(`newStock-${productId}`);

  if (!qtyRaw || isNaN(qty) || qty <= 0) {
    pendingEdits.delete(productId);
    row.classList.remove('row-modified');
    newStockCell.textContent = '—';
    newStockCell.classList.remove('batch-new-negative');
  } else {
    const newStock = computeNewStock(product.stock, type, qty);
    pendingEdits.set(productId, { type, quantity: qty, reason });
    row.classList.add('row-modified');
    newStockCell.textContent = newStock;
    newStockCell.classList.toggle('batch-new-negative', newStock < 0);
  }

  updateSummary();
}

function setRowType(productId, type) {
  const row = document.querySelector(`tr[data-id="${productId}"]`);
  if (!row) return;

  row.querySelectorAll('.batch-type-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.type === type);
  });

  onRowChange(productId);
}

// ─── SUMMARY BAR ───

function updateSummary() {
  let positive = 0;
  let negative = 0;

  pendingEdits.forEach((edit, productId) => {
    const product = products.find(p => p.id === productId);
    if (!product) return;
    const delta = edit.type === 'add' ? edit.quantity : -edit.quantity;
    if (delta >= 0) positive += delta; else negative += delta;
  });

  document.getElementById('statCount').textContent = pendingEdits.size;
  document.getElementById('statPositive').textContent = `+${positive}`;
  document.getElementById('statNegative').textContent = `−${Math.abs(negative)}`;
  document.getElementById('btnReview').disabled = pendingEdits.size === 0;
}

function resetAll() {
  pendingEdits.clear();
  loadProducts();
  updateSummary();
  dismissBanner();
}

// ─── CONFIRMATION MODAL ───

function openConfirmModal() {
  if (!pendingEdits.size) return;

  const rows = [];
  pendingEdits.forEach((edit, productId) => {
    const product = products.find(p => p.id === productId);
    if (!product) return;
    rows.push({ product, edit });
  });

  document.getElementById('confirmModalSubtitle').textContent =
    `${rows.length} product${rows.length !== 1 ? 's' : ''} · ${BRANCH}`;

  document.getElementById('confirmItems').innerHTML = rows.map(({ product, edit }) => {
    const newStock = computeNewStock(product.stock, edit.type, edit.quantity);
    const sign = edit.type === 'add' ? '+' : '−';
    return `
    <div class="summary-item">
      <span class="summary-item-name">
        ${escHtml(product.name)} <span class="summary-item-qty">${escHtml(product.sku)} · ${sign}${edit.quantity} → ${newStock}</span>
        <div class="batch-confirm-reason">${escHtml(reasonWithDate(edit.reason))}</div>
      </span>
    </div>`;
  }).join('');

  document.getElementById('confirmError').style.display = 'none';
  document.getElementById('confirmModal').style.display = 'flex';
}

function closeConfirmModal() {
  document.getElementById('confirmModal').style.display = 'none';
}

// ─── SUBMIT ───

async function submitBatch() {
  const adjustments = [];
  pendingEdits.forEach((edit, productId) => {
    adjustments.push({
      product_id: productId,
      type:       edit.type,
      quantity:   edit.quantity,
      reason:     reasonWithDate(edit.reason),
    });
  });

  if (!adjustments.length) return;

  const btn = document.getElementById('btnSubmitBatch');
  btn.disabled = true;
  const errBox = document.getElementById('confirmError');
  errBox.style.display = 'none';

  try {
    const res = await fetch('../api/batch_update_stock.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ adjustments }),
    });
    const data = await res.json();

    if (!data.success) {
      errBox.textContent = data.message || 'Failed to save batch.';
      errBox.style.display = 'block';
      return;
    }

    closeConfirmModal();
    showBanner(data.batch_id, data.count);
    pendingEdits.clear();
    updateSummary();
    await loadProducts();
  } catch {
    errBox.textContent = 'Could not reach the server.';
    errBox.style.display = 'block';
  } finally {
    btn.disabled = false;
  }
}

// ─── SUCCESS BANNER ───

function showBanner(batchId, count) {
  document.getElementById('successBannerTitle').textContent =
    `Batch saved — ${count} product${count !== 1 ? 's' : ''} adjusted.`;
  document.getElementById('successBannerSub').textContent = `Reference: ${batchId}`;
  document.getElementById('successBanner').style.display = 'flex';
}

function dismissBanner() {
  document.getElementById('successBanner').style.display = 'none';
}
