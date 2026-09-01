/* Lucky 8 Admin — Inter-branch transfers (read-only monitor + region editor).
   Leans on admin.css / reports.css / deliveries.css for the shell. */

function trfEsc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function viewTransfer(t) {
    document.getElementById('trfViewRef').textContent = t.reference;

    const bits = [
        t.requesting_branch + ' ← ' + t.source_branch,
        t.line_count + ' line(s)',
        t.status.toUpperCase(),
    ];
    if (t.note) bits.push('“' + t.note + '”');
    if (t.source_remarks) bits.push('Source: “' + t.source_remarks + '”');
    document.getElementById('trfViewMeta').textContent = bits.join(' · ');

    document.getElementById('trfViewList').innerHTML = (t.items || []).map(i => {
        const ship = i.qty_shipped === null
            ? ''
            : ' <span class="dlv-sub">' + (i.applied ? 'received ' : 'shipped ') + i.qty_shipped + '</span>';
        return '<div class="dlv-line"><span class="dlv-line-name">' + trfEsc(i.name)
             + ' <span class="dlv-sub">' + trfEsc(i.sku) + '</span></span>'
             + '<span class="dlv-line-qty">×' + i.qty_requested + ship + '</span></div>';
    }).join('') || '<div class="dlv-line"><span class="dlv-sub">No line items.</span></div>';

    document.getElementById('trfViewModal').classList.add('open');
}

document.querySelectorAll('.modal-bg').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
