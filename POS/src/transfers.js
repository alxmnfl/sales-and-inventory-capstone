// Lucky 8 POS — Inter-branch transfers

const REQ_PER_PAGE = 12;
let reqPage = 1;
const reqQty = new Map();           // sku -> qty
let currentIncoming = null;
let currentOutgoing = null;

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', () => {
  // Flash stashed just before a reload
  try {
    const raw = sessionStorage.getItem('trfFlash');
    if (raw) {
      sessionStorage.removeItem('trfFlash');
      const f = JSON.parse(raw);
      showSuccess(f.title, f.sub);
    }
  } catch (e) { /* ignore */ }

  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeAll(); });
  });

  const lookup = document.getElementById('trfLookupInput');
  if (lookup) lookup.addEventListener('keydown', e => { if (e.key === 'Enter') runLookup(); });

  initSourceCsel();
  if (document.getElementById('trfReqBody')) renderRequestRows();
});

function showSuccess(title, sub) {
  document.getElementById('trfSuccessTitle').textContent = title;
  document.getElementById('trfSuccessSub').textContent = sub;
  document.getElementById('trfSuccess').style.display = 'flex';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function closeModals() {
  ['trfIncomingModal', 'trfOutgoingModal'].forEach(id => {
    const el = document.getElementById(id); if (el) el.style.display = 'none';
  });
}
function closeAll() {
  document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}

/* ─────────────── Cross-branch stock lookup ─────────────── */

async function runLookup() {
  const input = document.getElementById('trfLookupInput');
  const box = document.getElementById('trfLookupResult');
  const term = input.value.trim();
  if (!term) { box.innerHTML = ''; return; }

  // Resolve a typed name to a SKU from our own catalogue when possible
  let sku = term;
  const hit = TRF.products.find(p =>
    p.sku.toLowerCase() === term.toLowerCase() ||
    p.name.toLowerCase() === term.toLowerCase());
  if (hit) sku = hit.sku;

  box.innerHTML = '<div class="trf-muted">Checking branches…</div>';
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'stock_lookup', sku }),
    });
    const data = await res.json();
    if (!data.success) { box.innerHTML = `<div class="dlv-error">${esc(data.message || 'Lookup failed.')}</div>`; return; }
    renderLookup(data);
  } catch {
    box.innerHTML = '<div class="dlv-error">Could not reach the server.</div>';
  }
}

function renderLookup(data) {
  const box = document.getElementById('trfLookupResult');
  const total = data.nearby.length + data.other.length;
  if (!total) {
    box.innerHTML = `<div class="trf-muted">No other branch is holding <strong>${esc(data.sku)}</strong> right now.</div>`;
    return;
  }

  const rows = (list) => list.map(r => `
    <tr${r.surplus <= 0 ? ' class="trf-row-dim"' : ''}>
      <td><strong>${esc(r.branch)}</strong></td>
      <td class="dlv-sub">${esc(r.region || '—')}</td>
      <td class="col-r">${r.on_hand}</td>
      <td class="col-r"><strong>${r.surplus}</strong></td>
      <td class="col-r"><button class="trf-btn-ghost" onclick="requestFrom('${esc(r.branch).replace(/'/g, "\\'")}')">Request from here</button></td>
    </tr>`).join('');

  const table = (list) => `
    <div class="report-table-wrap" style="margin-top:8px">
      <table class="dlv-doc-table">
        <thead><tr><th>Branch</th><th>Region</th><th class="col-r">On Hand</th><th class="col-r">Surplus</th><th class="col-r"></th></tr></thead>
        <tbody>${rows(list)}</tbody>
      </table>
    </div>`;

  let html = `<div class="trf-lookup-head">Stock of <strong>${esc(data.product_name || data.sku)}</strong> at other branches</div>`;
  if (data.nearby.length) {
    html += `<div class="trf-group-label"><i class="fa-solid fa-location-dot"></i> Nearby${data.my_region ? ' — ' + esc(data.my_region) : ''}</div>` + table(data.nearby);
  }
  if (data.other.length) {
    html += `<div class="trf-group-label"><i class="fa-solid fa-store"></i> Other branches</div>` + table(data.other);
  }
  box.innerHTML = html;
}

function requestFrom(branch) {
  const sel = document.getElementById('trfSourceBranch');
  if (sel) {
    sel.value = branch;
    sel.dispatchEvent(new Event('change'));   // refreshes the custom dropdown + send button
  }
  const card = document.getElementById('trfSendBtn');
  if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  updateSendBtn();
}

/* ─────────────── Custom "choose a branch" dropdown (pos-csel) ─────────────── */

function initSourceCsel() {
  const csel = document.getElementById('trfSourceCsel');
  if (!csel) return;
  const btn   = csel.querySelector('.pos-csel-btn');
  const panel = csel.querySelector('.pos-csel-panel');
  const valEl = csel.querySelector('.pos-csel-val');
  const sel   = csel.querySelector('.pos-csel-native');

  btn.addEventListener('click', e => {
    e.stopPropagation();
    const open = csel.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  panel.querySelectorAll('.pos-csel-opt').forEach(opt => {
    opt.addEventListener('click', () => {
      sel.value = opt.dataset.value;
      sel.dispatchEvent(new Event('change'));
      csel.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    });
  });

  // Keep the visible button + option highlight in step with the hidden <select>
  sel.addEventListener('change', () => {
    const v = sel.value;
    let text = '';
    panel.querySelectorAll('.pos-csel-opt').forEach(o => {
      const hit = o.dataset.value === v;
      o.classList.toggle('is-sel', hit);
      if (hit) text = o.querySelector('span').textContent.trim();
    });
    valEl.textContent = (v && text) ? text : '— choose a branch —';
    valEl.classList.toggle('is-ph', !(v && text));
  });

  document.addEventListener('click', e => {
    if (!csel.contains(e.target)) { csel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { csel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
  });

  sel.dispatchEvent(new Event('change'));   // initial sync
}

/* ─────────────── New request picker ─────────────── */

function reqFiltered() {
  const q = (document.getElementById('trfProductSearch')?.value || '').trim().toLowerCase();
  if (!q) return TRF.products;
  return TRF.products.filter(p =>
    p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q));
}

function renderRequestRows() {
  const body = document.getElementById('trfReqBody');
  if (!body) return;
  const rows = reqFiltered();
  const totalPages = Math.max(1, Math.ceil(rows.length / REQ_PER_PAGE));
  if (reqPage > totalPages) reqPage = totalPages;
  if (reqPage < 1) reqPage = 1;

  const pageRows = rows.slice((reqPage - 1) * REQ_PER_PAGE, reqPage * REQ_PER_PAGE);
  body.innerHTML = pageRows.length ? pageRows.map(p => {
    const val = reqQty.get(p.sku) || '';
    return `<tr${val ? ' class="dlv-tr-new"' : ''}>
      <td><div class="dlv-ci-name">${esc(p.name)}</div><div class="dlv-ci-cat">${esc(p.category || '—')}</div></td>
      <td class="dlv-mono">${esc(p.sku)}</td>
      <td class="col-r">${p.stock}</td>
      <td class="col-r"><input type="number" min="0" step="1" class="trf-qty" value="${val}" placeholder="0"
           data-sku="${esc(p.sku)}" oninput="onReqQty(this)"></td>
    </tr>`;
  }).join('') : '<tr><td colspan="4" class="dlv-td-empty">No products match.</td></tr>';

  renderReqPager(totalPages);
  refreshReqNote(rows.length, totalPages);
  updateSendBtn();
}

function renderReqPager(totalPages) {
  const pager = document.getElementById('trfReqPager');
  if (!pager) return;
  if (totalPages <= 1) { pager.innerHTML = ''; return; }
  let html = `<button class="pg-btn${reqPage <= 1 ? ' disabled' : ''}" onclick="reqGoto(${reqPage - 1})">‹</button>`;
  for (let i = 1; i <= totalPages; i++) {
    html += `<button class="pg-btn${i === reqPage ? ' active' : ''}" onclick="reqGoto(${i})">${i}</button>`;
  }
  html += `<button class="pg-btn${reqPage >= totalPages ? ' disabled' : ''}" onclick="reqGoto(${reqPage + 1})">›</button>`;
  pager.innerHTML = html;
}
function reqGoto(p) { reqPage = p; renderRequestRows(); }

function refreshReqNote(total, totalPages) {
  const note = document.getElementById('trfRequestNote');
  if (!note) return;
  const items = selectedItems();
  let txt = total
    ? `Showing page ${reqPage}/${totalPages} · ${total} product${total !== 1 ? 's' : ''}`
    : 'No products match your search.';
  if (items.length) {
    const units = items.reduce((s, i) => s + i.qty, 0);
    txt += ` · ${items.length} product${items.length !== 1 ? 's' : ''} / ${units} unit${units !== 1 ? 's' : ''} queued`;
  }
  note.textContent = txt;
}

function onReqQty(input) {
  const sku = input.dataset.sku;
  const n = parseInt(input.value, 10);
  if (!isNaN(n) && n > 0) reqQty.set(sku, n);
  else reqQty.delete(sku);
  input.closest('tr').classList.toggle('dlv-tr-new', reqQty.has(sku));
  const rows = reqFiltered();
  refreshReqNote(rows.length, Math.max(1, Math.ceil(rows.length / REQ_PER_PAGE)));
  updateSendBtn();
}

function selectedItems() {
  const bySku = {};
  TRF.products.forEach(p => { bySku[p.sku] = p; });
  const out = [];
  reqQty.forEach((qty, sku) => {
    const p = bySku[sku];
    if (!p || qty <= 0) return;
    out.push({ sku, name: p.name, qty });
  });
  return out;
}

function updateSendBtn() {
  const btn = document.getElementById('trfSendBtn');
  if (!btn) return;
  const src = document.getElementById('trfSourceBranch');
  btn.disabled = selectedItems().length === 0 || !src || !src.value;
}
document.addEventListener('change', e => { if (e.target && e.target.id === 'trfSourceBranch') updateSendBtn(); });

function openRequestConfirm() {
  const items = selectedItems();
  const src = document.getElementById('trfSourceBranch').value;
  if (!items.length || !src) return;
  document.getElementById('trfReqBranch').textContent = src;
  document.getElementById('trfReqList').innerHTML = items.map(i =>
    `<div class="trf-line"><span>${esc(i.name)} <span class="dlv-sub">${esc(i.sku)}</span></span><span class="trf-line-qty">×${i.qty}</span></div>`
  ).join('');
  document.getElementById('trfReqError').style.display = 'none';
  document.getElementById('trfReqConfirmBtn').disabled = false;
  document.getElementById('trfRequestModal').style.display = 'flex';
}
function closeRequestConfirm() { document.getElementById('trfRequestModal').style.display = 'none'; }

async function submitRequest() {
  const btn = document.getElementById('trfReqConfirmBtn');
  const err = document.getElementById('trfReqError');
  const src = document.getElementById('trfSourceBranch').value;
  const note = document.getElementById('trfNote').value.trim();
  const items = selectedItems();
  btn.disabled = true; err.style.display = 'none';
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'request', source_branch: src, note, items }),
    });
    const data = await res.json();
    if (!data.success) { err.textContent = data.message || 'Could not send the request.'; err.style.display = 'block'; btn.disabled = false; return; }
    stash(`Request ${data.reference} sent to ${data.source_branch}.`, 'They will review it before any stock moves.');
    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.'; err.style.display = 'block'; btn.disabled = false;
  }
}

/* ─────────────── Incoming: approve & ship / decline ─────────────── */

function openIncoming(idx) {
  currentIncoming = TRF.incoming[idx];
  if (!currentIncoming) return;
  const t = currentIncoming;
  const actionable = t.status === 'requested';

  document.getElementById('trfIncRef').textContent = t.reference;
  document.getElementById('trfIncMeta').textContent =
    `${t.requesting_branch} · ${t.line_count} product(s) · ${t.unit_count} unit(s) · requested ${t.requested_at}`;

  const noteEl = document.getElementById('trfIncNote');
  if (t.note) { noteEl.textContent = 'Note from ' + t.requesting_branch + ': ' + t.note; noteEl.style.display = 'block'; }
  else noteEl.style.display = 'none';

  document.getElementById('trfIncInstruct').style.display = actionable ? 'block' : 'none';
  document.getElementById('trfIncBody').innerHTML = (t.items || []).map(it => {
    const here = it.on_hand === null ? 0 : it.on_hand;
    const dflt = Math.min(it.qty_requested, here);
    const shipCell = actionable
      ? `<input type="number" min="0" max="${here}" step="1" class="trf-qty" value="${dflt}" data-item="${it.item_id}">`
      : (it.qty_shipped === null ? '—' : it.qty_shipped);
    const hereCell = it.on_hand === null
      ? '<span class="dlv-sub">not stocked</span>'
      : `<span${here < it.qty_requested ? ' class="dlv-sub dlv-sub--warn"' : ''}>${here}</span>`;
    return `<tr>
      <td><div class="dlv-ci-name">${esc(it.name)}</div><div class="dlv-ci-cat">${esc(it.category || '—')}</div></td>
      <td class="dlv-mono">${esc(it.sku)}</td>
      <td class="col-r dlv-ci-qty">${it.qty_requested}</td>
      <td class="col-r">${hereCell}</td>
      <td class="col-c">${shipCell}</td>
    </tr>`;
  }).join('');

  document.getElementById('trfIncError').style.display = 'none';
  document.getElementById('trfIncFooter').style.display     = actionable ? 'flex' : 'none';
  document.getElementById('trfIncViewFooter').style.display = actionable ? 'none' : 'flex';
  document.getElementById('trfIncomingModal').style.display = 'flex';
}

async function submitApprove() {
  const btn = document.getElementById('trfApproveBtn');
  const err = document.getElementById('trfIncError');
  const shipments = [...document.querySelectorAll('#trfIncBody input[data-item]')].map(inp => ({
    item_id: parseInt(inp.dataset.item, 10),
    qty: Math.max(0, parseInt(inp.value, 10) || 0),
  }));
  if (!shipments.some(s => s.qty > 0)) {
    err.textContent = 'Set a ship quantity of at least 1 on one line, or decline the request.';
    err.style.display = 'block'; return;
  }
  btn.disabled = true; err.style.display = 'none';
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'approve', transfer_id: currentIncoming.id, shipments }),
    });
    const data = await res.json();
    if (!data.success) { err.textContent = data.message || 'Could not approve.'; err.style.display = 'block'; btn.disabled = false; return; }
    stash(`Transfer ${data.reference} shipped.`, `${data.units} unit(s) across ${data.lines} line(s) deducted from ${TRF.branch} stock.`);
    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.'; err.style.display = 'block'; btn.disabled = false;
  }
}

function openDecline() {
  document.getElementById('trfDeclineText').value = '';
  document.getElementById('trfDeclineError').style.display = 'none';
  document.getElementById('trfIncomingModal').style.display = 'none';
  document.getElementById('trfDeclineModal').style.display = 'flex';
}
function closeDecline() {
  document.getElementById('trfDeclineModal').style.display = 'none';
  document.getElementById('trfIncomingModal').style.display = 'flex';
}
async function submitDecline() {
  const text = document.getElementById('trfDeclineText').value.trim();
  const err = document.getElementById('trfDeclineError');
  if (!text) { err.textContent = 'Please give a reason.'; err.style.display = 'block'; return; }
  const btn = document.getElementById('trfDeclineBtn');
  btn.disabled = true; err.style.display = 'none';
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'reject', transfer_id: currentIncoming.id, remarks: text }),
    });
    const data = await res.json();
    if (!data.success) { err.textContent = data.message || 'Could not send.'; err.style.display = 'block'; btn.disabled = false; return; }
    stash(`Request ${data.reference} declined.`, 'The requesting branch has been notified. No stock changed.');
    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.'; err.style.display = 'block'; btn.disabled = false;
  }
}

/* ─────────────── Outgoing: confirm receipt ─────────────── */

function openOutgoing(idx) {
  currentOutgoing = TRF.outgoing[idx];
  if (!currentOutgoing) return;
  const t = currentOutgoing;
  const actionable = t.status === 'shipped';

  document.getElementById('trfOutRef').textContent = t.reference;
  document.getElementById('trfOutMeta').textContent =
    `${t.source_branch} · ${t.line_count} product(s) · ${t.unit_count} unit(s) · ${t.status.toUpperCase()}`;

  const noteEl = document.getElementById('trfOutNote');
  const parts = [];
  if (t.note) parts.push('Your note: ' + t.note);
  if (t.source_remarks) parts.push(t.source_branch + ' said: ' + t.source_remarks);
  if (parts.length) { noteEl.textContent = parts.join('  •  '); noteEl.style.display = 'block'; }
  else noteEl.style.display = 'none';

  document.getElementById('trfOutInstruct').style.display = actionable ? 'block' : 'none';
  document.getElementById('trfOutBody').innerHTML = (t.items || []).map(it => {
    const shipped = it.qty_shipped === null ? '—' : it.qty_shipped;
    const okCell = actionable && it.qty_shipped > 0
      ? '<label class="dlv-chk-wrap"><input type="checkbox" class="dlv-chk" onchange="trfOutToggle()"></label>'
      : (it.applied ? '<i class="fa-solid fa-check dlv-ci-ok"></i>' : '<span class="dlv-sub">—</span>');
    return `<tr>
      <td><div class="dlv-ci-name">${esc(it.name)}</div><div class="dlv-ci-cat">${esc(it.category || '—')}</div></td>
      <td class="dlv-mono">${esc(it.sku)}</td>
      <td class="col-r dlv-ci-qty">${it.qty_requested}</td>
      <td class="col-r dlv-ci-qty">${shipped}</td>
      <td class="col-c">${okCell}</td>
    </tr>`;
  }).join('');

  document.getElementById('trfOutError').style.display = 'none';
  document.getElementById('trfOutFooter').style.display     = actionable ? 'flex' : 'none';
  document.getElementById('trfOutViewFooter').style.display = actionable ? 'none' : 'flex';
  document.getElementById('trfReceiveBtn').disabled = true;
  document.getElementById('trfOutgoingModal').style.display = 'flex';
}

function trfOutToggle() {
  const boxes = [...document.querySelectorAll('#trfOutBody .dlv-chk')];
  document.getElementById('trfReceiveBtn').disabled = !(boxes.length && boxes.every(b => b.checked));
}

async function submitReceive() {
  const btn = document.getElementById('trfReceiveBtn');
  const err = document.getElementById('trfOutError');
  btn.disabled = true; err.style.display = 'none';
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'receive', transfer_id: currentOutgoing.id }),
    });
    const data = await res.json();
    if (!data.success) { err.textContent = data.message || 'Could not confirm receipt.'; err.style.display = 'block'; btn.disabled = false; return; }
    stash(`Transfer ${data.reference} received.`, `${data.units} unit(s) across ${data.lines} line(s) added to ${TRF.branch} stock.`);
    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.'; err.style.display = 'block'; btn.disabled = false;
  }
}

/* ─────────────── Cancel a pending request ─────────────── */

async function cancelRequest(id, ref) {
  if (!confirm('Cancel request ' + ref + '?\nThe source branch will no longer see it.')) return;
  try {
    const res = await fetch('../api/transfers.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'cancel', transfer_id: id }),
    });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Could not cancel.'); return; }
    stash(`Request ${ref} cancelled.`, 'It has been withdrawn.');
    location.reload();
  } catch {
    alert('Could not reach the server.');
  }
}

function stash(title, sub) {
  try { sessionStorage.setItem('trfFlash', JSON.stringify({ title, sub })); } catch (e) { /* ignore */ }
}
