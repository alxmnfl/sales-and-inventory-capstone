function loadCriticalStock(branch) {
    const body = document.getElementById('critical-stock-body');
    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`api/critical_stock.php?branch=${encodeURIComponent(branch)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items.length) {
                body.innerHTML = '<div class="intel-empty">No critical stock alerts.</div>';
                return;
            }
            let html = `<table class="intel-table">
                <thead><tr>
                    <th>Product</th><th>SKU</th><th>Branch</th>
                    <th class="col-r">Stock</th><th>Status</th>
                </tr></thead><tbody>`;
            data.items.forEach(item => {
                const crit       = item.stock < 5;
                const rowClass   = crit ? 'row-critical' : 'row-warning';
                const badgeClass = crit ? 'badge-pill red' : 'badge-pill orange';
                const badgeText  = crit ? 'CRITICAL' : 'LOW';
                const numClass   = crit ? 'stock-critical' : 'stock-low';
                html += `<tr class="${rowClass}">
                    <td><div class="prod-name">${esc(item.name)}</div><div class="prod-cat">${esc(item.category)}</div></td>
                    <td class="col-mono">${esc(item.sku)}</td>
                    <td>${esc(item.branch)}</td>
                    <td class="col-r col-num ${numClass}">${item.stock}</td>
                    <td><span class="${badgeClass}">${badgeText}</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div class="intel-empty">Failed to load data.</div>'; });
}
