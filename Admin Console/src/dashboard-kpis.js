function loadDashboardKpis(branch) {
    fetch(`../api/dashboard-kpis.php?branch=${encodeURIComponent(branch)}&_t=${Date.now()}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            // KPI 1: Total Revenue (MTD)
            const revCard = document.querySelector('.kpi-card:nth-child(1)');
            if (revCard) {
                revCard.querySelector('.kpi-value').textContent = '₱' + Number(data.mtd_revenue).toLocaleString();
                const meta = revCard.querySelector('.kpi-meta');
                if (meta) {
                    let badge = '';
                    if (data.rev_pct !== null) {
                        const cls = data.rev_pct >= 0 ? 'badge-up' : 'badge-down';
                        const sign = data.rev_pct >= 0 ? '+' : '';
                        badge = `<span class="${cls}">${data.rev_pct >= 0 ? '↑' : '↓'} ${sign}${data.rev_pct}%</span>`;
                    }
                    const scope = branch ? ` for ${esc(branch)}` : ' Across all branches';
                    meta.innerHTML = badge + scope;
                }
            }

            // KPI 2: Units Sold (MTD)
            const unitsCard = document.querySelector('.kpi-card:nth-child(2)');
            if (unitsCard) {
                unitsCard.querySelector('.kpi-value').textContent = Number(data.mtd_units).toLocaleString();
                const meta = unitsCard.querySelector('.kpi-meta');
                if (meta) {
                    if (data.avg_units_per_txn > 0) {
                        meta.innerHTML = `<span class="badge-up">↑ +${data.avg_units_per_txn}%</span> ${data.avg_units_per_txn} units avg per transaction`;
                    } else {
                        meta.innerHTML = 'No transactions this month';
                    }
                }
            }

            // KPI 3: Low-Stock Alerts
            const stockCard = document.querySelector('.kpi-card:nth-child(3)');
            if (stockCard) {
                stockCard.querySelector('.kpi-value').textContent = data.low_stock_count;
                const meta = stockCard.querySelector('.kpi-meta');
                if (meta) {
                    let criticalBadge = '';
                    if (data.critical_count > 0) {
                        criticalBadge = `<span class="badge-pill red">↓ ${data.critical_count} CRITICAL</span>`;
                    }
                    const scope = branch ? ` for ${esc(branch)}` : '';
                    meta.innerHTML = criticalBadge + scope;
                }
            }

            // KPI 4: Active Branches
            const branchCard = document.querySelector('.kpi-card:nth-child(4)');
            if (branchCard) {
                if (branch) {
                    branchCard.querySelector('.kpi-value').textContent = '1';
                    const meta = branchCard.querySelector('.kpi-meta');
                    if (meta) {
                        meta.innerHTML = `<span class="badge-pill green">↑ ACTIVE</span> ${esc(branch)}`;
                    }
                } else {
                    branchCard.querySelector('.kpi-value').textContent = `${data.active_branches}/${data.active_branches}`;
                    const meta = branchCard.querySelector('.kpi-meta');
                    if (meta) {
                        meta.innerHTML = `<span class="badge-pill green">↑ ${data.active_branches} ACTIVE</span>`;
                    }
                }
            }

            // Summary stats
            const statCards = document.querySelectorAll('.stats-row .stat-card');
            if (statCards.length >= 3) {
                statCards[0].querySelector('.stat-value').textContent = abbrevPeso(data.mtd_revenue);
                statCards[1].querySelector('.stat-value').textContent = abbrevPeso(data.avg_daily_rev);
                statCards[2].querySelector('.stat-value').textContent = Number(data.mtd_units).toLocaleString();
            }

            // Update chart data object
            if (typeof chartData !== 'undefined') {
                chartData.labels  = data.daily_labels;
                chartData.revenue = data.daily_revenue;
                chartData.units   = data.daily_units;
                chartData.abc = {
                    a: data.abc.a,
                    b: data.abc.b,
                    c: data.abc.c
                };
            } else {
                window.chartData = {
                    labels:  data.daily_labels,
                    revenue: data.daily_revenue,
                    units:   data.daily_units,
                    abc: {
                        a: data.abc.a,
                        b: data.abc.b,
                        c: data.abc.c
                    }
                };
            }

            // Update chart subtitle
            const salesChartCard = document.querySelector('.charts-row .chart-card:first-child .chart-subtitle');
            if (salesChartCard) {
                const now = new Date();
                const monthLabel = now.toLocaleString('default', { month: 'long', year: 'numeric' });
                const scope = branch ? ` · ${esc(branch)}` : '';
                salesChartCard.textContent = `Daily across ${data.active_branches} branches${scope} · ${monthLabel}`;
            }

            // Update ABC legend counts
            const abcItems = document.querySelectorAll('.abc-legend .abc-item');
            if (abcItems.length >= 3 && data.abc.total_sku > 0) {
                const counts = [data.abc.a, data.abc.b, data.abc.c];
                abcItems.forEach((item, i) => {
                    const countEl = item.querySelector('.abc-count');
                    if (countEl) {
                        const pct = Math.round(counts[i] / data.abc.total_sku * 100);
                        countEl.textContent = `${counts[i]} (${pct}%)`;
                    }
                });
            }

            // Re-render charts
            if (typeof buildSalesChart === 'function') {
                buildSalesChart(typeof activeMode !== 'undefined' ? activeMode : 'revenue');
            }
            if (typeof buildAbcChart === 'function') {
                buildAbcChart();
            }
        })
        .catch(err => console.error('Dashboard KPIs load error:', err));
}

function abbrevPeso(n) {
    if (n >= 1_000_000) return '₱' + (n / 1_000_000).toFixed(2) + 'M';
    if (n >= 1_000)     return '₱' + (n / 1_000).toFixed(1) + 'K';
    return '₱' + Number(n).toFixed(0);
}
