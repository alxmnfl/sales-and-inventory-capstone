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