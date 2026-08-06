function loadPredictiveAlerts(branch) {
    const body = document.getElementById('predictive-body');
    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`../api/predictive_alerts.php?branch=${encodeURIComponent(branch)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items.length) {
                body.innerHTML = '<div class="intel-empty">No stock-out risks detected in the next 14 days.</div>';
                return;
            }
            let html = `<table class="intel-table">
                <thead><tr>
                    <th>Product</th><th>Branch</th>
                    <th class="col-r">Stock</th><th class="col-r">Daily Avg</th>
                    <th class="col-r">Days Left</th><th>Risk</th>
                </tr></thead><tbody>`;
            data.items.forEach(item => {
                const days      = item.days_remaining;
                const urgent    = days < 3;
                const high      = days >= 3 && days < 7;
                const daysClass = urgent ? 'days-urgent' : high ? 'days-high' : 'days-med';
                const badgeClass = urgent ? 'badge-pill red' : high ? 'badge-pill orange' : 'badge-pill amber';
                const badgeText  = urgent ? 'URGENT' : high ? 'HIGH' : 'MEDIUM';
                html += `<tr>
                    <td><div class="prod-name">${esc(item.name)}</div><div class="prod-cat">${esc(item.category)}</div></td>
                    <td>${esc(item.branch)}</td>
                    <td class="col-r col-num">${item.stock}</td>
                    <td class="col-r col-num">${item.avg_daily_units}/day</td>
                    <td class="col-r col-num ${daysClass}">${days} days</td>
                    <td><span class="${badgeClass}">${badgeText}</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        })
        .catch(() => { body.innerHTML = '<div class="intel-empty">Failed to load data.</div>'; });
}
