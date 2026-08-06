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