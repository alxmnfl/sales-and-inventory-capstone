/* Lucky 8 Admin — Deliveries (create + history) */

// sku -> qty
const dlvQty = new Map();

const DLV_PER_PAGE = 15;
let dlvPage = 1;

function escHtml(s){
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function dlvFiltered(){
    const el = document.getElementById('dlvSearch');
    const q = (el ? el.value : '').trim().toLowerCase();
    if (!q) return DLV_PRODUCTS;
    return DLV_PRODUCTS.filter(p =>
        p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q));
}

function dlvGoto(page){
    dlvPage = page;
    renderDlvRows();
}

/* Single "preview-note" line, mirroring reports.php:
   "Showing A–B of N products · page P/T"  (+ " · K products / U units queued") */
function refreshNote(total, totalPages){
    const note = document.getElementById('dlvPreviewNote');
    if (!note) return;
    if (!total){
        const searching = (document.getElementById('dlvSearch') || {}).value;
        note.textContent = searching ? 'No products match your search.'
                                     : 'No products stocked at this branch yet.';
        return;
    }
    const from = (dlvPage - 1) * DLV_PER_PAGE + 1;
    const to   = Math.min(dlvPage * DLV_PER_PAGE, total);
    let txt = 'Showing ' + from + '–' + to + ' of ' + total
            + ' product' + (total !== 1 ? 's' : '')
            + ' · page ' + dlvPage + '/' + totalPages;

    const items = selectedItems();
    if (items.length){
        const units = items.reduce((s, i) => s + i.qty, 0);
        txt += ' · ' + items.length + ' product' + (items.length !== 1 ? 's' : '')
             + ' / ' + units + ' unit' + (units !== 1 ? 's' : '') + ' queued';
    }
    note.textContent = txt;
}

function renderDlvPager(totalPages){
    const pager = document.getElementById('dlvPagination');
    if (!pager) return;
    if (totalPages <= 1){ pager.innerHTML = ''; return; }

    let html = '<button type="button" class="pg-btn'+(dlvPage<=1?' disabled':'')+'" onclick="dlvGoto('+(dlvPage-1)+')"><i class="fa-solid fa-chevron-left"></i></button>';
    const start = Math.max(1, dlvPage - 2);
    const end   = Math.min(totalPages, dlvPage + 2);
    if (start > 1){
        html += '<button type="button" class="pg-btn" onclick="dlvGoto(1)">1</button>';
        if (start > 2) html += '<span class="pg-gap">…</span>';
    }
    for (let i = start; i <= end; i++){
        html += '<button type="button" class="pg-btn'+(i===dlvPage?' active':'')+'" onclick="dlvGoto('+i+')">'+i+'</button>';
    }
    if (end < totalPages){
        if (end < totalPages - 1) html += '<span class="pg-gap">…</span>';
        html += '<button type="button" class="pg-btn" onclick="dlvGoto('+totalPages+')">'+totalPages+'</button>';
    }
    html += '<button type="button" class="pg-btn'+(dlvPage>=totalPages?' disabled':'')+'" onclick="dlvGoto('+(dlvPage+1)+')"><i class="fa-solid fa-chevron-right"></i></button>';
    pager.innerHTML = html;
}

function renderDlvRows(){
    const body = document.getElementById('dlvTableBody');
    if (!body) return;
    const rows = dlvFiltered();

    if (!rows.length){
        body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:28px;color:#9ca3af;">No products match.</td></tr>';
        renderDlvPager(0);
        refreshNote(0, 0);
        updateDlvSummary();
        return;
    }

    const totalPages = Math.ceil(rows.length / DLV_PER_PAGE);
    if (dlvPage > totalPages) dlvPage = totalPages;
    if (dlvPage < 1) dlvPage = 1;
    const pageRows = rows.slice((dlvPage - 1) * DLV_PER_PAGE, dlvPage * DLV_PER_PAGE);

    body.innerHTML = pageRows.map(p => {
        const val = dlvQty.get(p.sku) || '';
        return '<tr'+(val ? ' class="dlv-row-active"' : '')+'>'
             + '<td><div class="prod-name">'+escHtml(p.name)+'</div><div class="dlv-sub">'+escHtml(p.category || '—')+'</div></td>'
             + '<td class="mono">'+escHtml(p.sku)+'</td>'
             + '<td class="r">'+p.stock+'</td>'
             + '<td class="c"><input type="number" min="0" step="1" class="dlv-qty" '
             +   'value="'+val+'" placeholder="0" '
             +   'data-sku="'+escHtml(p.sku)+'" oninput="onDlvQty(this)"></td>'
             + '</tr>';
    }).join('');

    renderDlvPager(totalPages);
    refreshNote(rows.length, totalPages);
    updateDlvSummary();
}

function onDlvQty(input){
    const sku = input.dataset.sku;
    const n = parseInt(input.value, 10);
    if (!isNaN(n) && n > 0) dlvQty.set(sku, n);
    else dlvQty.delete(sku);
    input.closest('tr').classList.toggle('dlv-row-active', dlvQty.has(sku));
    updateDlvSummary();

    // keep the "queued" tail of the note current without re-rendering rows
    const rows = dlvFiltered();
    refreshNote(rows.length, Math.max(1, Math.ceil(rows.length / DLV_PER_PAGE)));
}

function selectedItems(){
    const bySku = {};
    DLV_PRODUCTS.forEach(p => { bySku[p.sku] = p; });
    const out = [];
    dlvQty.forEach((qty, sku) => {
        const p = bySku[sku];
        if (!p || qty <= 0) return;
        out.push({ product_id: p.id, sku: p.sku, name: p.name, category: p.category || '', qty: qty });
    });
    return out;
}

function updateDlvSummary(){
    const btn = document.getElementById('dlvSendBtn');
    if (!btn) return;
    btn.disabled = selectedItems().length === 0;
}

function openDlvConfirm(){
    const items = selectedItems();
    if (!items.length) return;

    document.getElementById('dlvConfirmBranch').textContent = DLV_BRANCH;
    document.getElementById('dlvConfirmList').innerHTML = items.map(i =>
        '<div class="dlv-line"><span class="dlv-line-name">'+escHtml(i.name)
        + ' <span class="dlv-sub">'+escHtml(i.sku)+'</span></span><span class="dlv-line-qty">×'+i.qty+'</span></div>'
    ).join('');

    document.getElementById('dlvSendBranch').value = DLV_BRANCH;
    document.getElementById('dlvSendNote').value   = document.getElementById('dlvNote').value.trim();
    document.getElementById('dlvSendItems').value  = JSON.stringify(items);
    document.getElementById('dlvConfirmModal').classList.add('open');
}

function viewDelivery(id){
    const d = DLV_HISTORY.find(x => x.id === id);
    if (!d) return;
    document.getElementById('dlvViewRef').textContent = d.reference;
    const bits = [d.branch, d.line_count + ' lines', d.unit_count + ' units', d.status.toUpperCase()];
    if (d.note) bits.push('“' + d.note + '”');
    document.getElementById('dlvViewMeta').textContent = bits.join(' · ');
    document.getElementById('dlvViewList').innerHTML = (d.items || []).map(i => {
        const got = i.qty_received === null ? '' : ' <span class="dlv-sub">received ' + i.qty_received + '</span>';
        return '<div class="dlv-line"><span class="dlv-line-name">'+escHtml(i.name)
             + ' <span class="dlv-sub">'+escHtml(i.sku)+'</span></span>'
             + '<span class="dlv-line-qty">×'+i.qty_sent+got+'</span></div>';
    }).join('');
    document.getElementById('dlvViewModal').classList.add('open');
}

function cancelDelivery(id, ref){
    if (!confirm('Cancel delivery ' + ref + '?\nThe branch will no longer be able to receive it.')) return;
    document.getElementById('dlvCancelId').value = id;
    document.getElementById('dlvCancelForm').submit();
}

function closeModal(id){
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

renderDlvRows();
