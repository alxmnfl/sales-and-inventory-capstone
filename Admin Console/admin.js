/* ── Chart defaults ── */
Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9ca3af';

/* ── Sales trend line chart ── */
let activeMode = 'revenue';
let salesChart = null;

function buildSalesChart(mode) {
    const ctx = document.getElementById('salesChart').getContext('2d');

    const isRevenue = mode === 'revenue';
    const data      = isRevenue ? chartData.revenue : chartData.units;
    const color     = '#e8611a';

    const gradient = ctx.createLinearGradient(0, 0, 0, 200);
    gradient.addColorStop(0,   'rgba(232,97,26,0.25)');
    gradient.addColorStop(1,   'rgba(232,97,26,0)');

    if (salesChart) salesChart.destroy();

    salesChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels:   chartData.labels,
            datasets: [{
                data:            data,
                borderColor:     color,
                borderWidth:     2,
                backgroundColor: gradient,
                fill:            true,
                tension:         0.4,
                pointRadius:     0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: color,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor:      '#fff',
                    bodyColor:       '#9ca3af',
                    padding:         10,
                    cornerRadius:    8,
                    callbacks: {
                        label: ctx => isRevenue
                            ? ' ₱' + Number(ctx.parsed.y).toLocaleString()
                            : ' ' + ctx.parsed.y + ' units'
                    }
                }
            },
            scales: {
                x: {
                    grid:   { display: false },
                    border: { display: false },
                    ticks:  { maxTicksLimit: 8, color: '#9ca3af' }
                },
                y: {
                    grid:   { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                    border: { display: false },
                    ticks: {
                        color: '#9ca3af',
                        callback: v => isRevenue
                            ? '₱' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v)
                            : v
                    }
                }
            }
        }
    });
}

function switchChart(mode) {
    activeMode = mode;

    document.getElementById('btn-revenue').classList.toggle('active', mode === 'revenue');
    document.getElementById('btn-units').classList.toggle('active',   mode === 'units');

    buildSalesChart(mode);
}

/* ── ABC donut chart ── */
function buildAbcChart() {
    const ctx = document.getElementById('abcChart').getContext('2d');

    const total = chartData.abc.a + chartData.abc.b + chartData.abc.c;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels:   ['A — Fast Movers', 'B — Steady Movers', 'C — Slow/Non-Movers'],
            datasets: [{
                data:            [chartData.abc.a, chartData.abc.b, chartData.abc.c],
                backgroundColor: ['#e8611a', '#374151', '#d1d5db'],
                borderWidth:     0,
                hoverOffset:     6
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: false,
            cutout:              '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111827',
                    titleColor:      '#fff',
                    bodyColor:       '#9ca3af',
                    padding:         10,
                    cornerRadius:    8,
                    callbacks: {
                        label: ctx => {
                            const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                            return ` ${ctx.parsed} SKUs (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

/* ════════════════════════════════════════════════════════════════
   BRANCH INTELLIGENCE SECTIONS
   ════════════════════════════════════════════════════════════════ */

function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function selectedBranch() {
    return document.getElementById('globalBranchFilter')?.value ?? '';
}

/* ── Load all sections (called on branch change or page init) ── */
function loadAllSections() {
    const branch = selectedBranch();
    loadFastMoving(branch);
    loadCriticalStock(branch);
    loadPredictiveAlerts(branch);
    auditPage = 1;
    loadAuditTrail(branch, true);
}

/* ── Fast-Moving Items ── */
function loadFastMoving(branch) {
    const body = document.getElementById('fast-moving-body');
    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`api/fast_moving.php?branch=${encodeURIComponent(branch)}`)
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

/* ── Critical Stock ── */
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

/* ── Predictive Stock Alerts ── */
function loadPredictiveAlerts(branch) {
    const body = document.getElementById('predictive-body');
    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`api/predictive_alerts.php?branch=${encodeURIComponent(branch)}`)
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

/* ── Audit Trail ── */
let auditPage = 1;
const AUDIT_PER_PAGE = 15;

function loadAuditTrail(branch, reset) {
    if (reset) auditPage = 1;

    const body     = document.getElementById('audit-trail-body');
    const loadMore = document.getElementById('audit-load-more');

    if (reset) body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`api/audit_trail.php?branch=${encodeURIComponent(branch)}&page=${auditPage}&limit=${AUDIT_PER_PAGE}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div class="intel-empty">Error loading audit trail.</div>';
                loadMore.style.display = 'none';
                return;
            }

            if (!data.items.length && reset) {
                const notice = data.notice ? `<br><small>${esc(data.notice)}</small>` : '';
                body.innerHTML = `<div class="intel-empty">No audit records found.${notice}</div>`;
                loadMore.style.display = 'none';
                return;
            }

            if (reset) {
                body.innerHTML = `<table class="intel-table audit-table">
                    <thead><tr>
                        <th>Time</th><th>User</th><th>Branch</th>
                        <th>Action</th><th>Item</th><th>Details</th>
                    </tr></thead>
                    <tbody id="audit-tbody"></tbody>
                </table>`;
            }

            const tbody = document.getElementById('audit-tbody');
            data.items.forEach(item => {
                const ac = item.action.includes('DELETE') ? 'action-delete'
                         : item.action.includes('ADD')    ? 'action-add'
                         : item.action.includes('EDIT')   ? 'action-edit'
                         :                                   'action-sale';
                tbody.innerHTML += `<tr>
                    <td class="audit-time">${esc(item.created_at)}</td>
                    <td>${esc(item.user_name)}</td>
                    <td>${esc(item.branch)}</td>
                    <td><span class="action-badge ${ac}">${esc(item.action)}</span></td>
                    <td class="audit-item">${esc(item.entity_name ?? '—')}</td>
                    <td class="audit-detail">${esc(item.details ?? '')}</td>
                </tr>`;
            });

            loadMore.style.display = data.has_more ? 'flex' : 'none';
        })
        .catch(() => {
            body.innerHTML = '<div class="intel-empty">Failed to load audit trail.</div>';
            loadMore.style.display = 'none';
        });
}

function loadMoreAudit() {
    auditPage++;
    loadAuditTrail(selectedBranch(), false);
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    buildSalesChart('revenue');
    buildAbcChart();
    loadAllSections();
});
