function loadFastMoving(branch) {
    const body = document.getElementById('fast-moving-body');
    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`../api/fast_moving.php?branch=${encodeURIComponent(branch)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items.length) {
                body.innerHTML = '<div class="intel-empty">No sales data in the last 30 days.</div>';
                return;
            }
            let html = `<table class="intel-table">
                <thead><tr>
                    <th>#</th><th>Product</th><th>SKU</th><th>Branch</th>
                    <th class="col-r">Units Sold</th><th class="col-r">Revenue</th>
                </tr></thead><tbody>`;
            data.items.forEach((item, i) => {
                html += `<tr>
                    <td class="col-rank">${i + 1}</td>
                    <td><div class="prod-name">${esc(item.name)}</div><div class="prod-cat">${esc(item.category)}</div></td>
                    <td class="col-mono">${esc(item.sku)}</td>
                    <td>${esc(item.branch)}</td>
                    <td class="col-r col-num">${item.total_units.toLocaleString()}</td>
                    <td class="col-r col-num">₱${Number(item.total_revenue).toLocaleString()}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div class="intel-empty">Failed to load data.</div>'; });
}
