/* ── Chart defaults ── */
Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9ca3af';

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    buildSalesChart('revenue');
    buildAbcChart();
    loadAllSections();
});
