/* ── Chart defaults ── (skip if the Chart.js CDN didn't load) */
if (window.Chart) {
    Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = '#9ca3af';
}

/* ── Init ──
   Each step is isolated: a failing chart (e.g. the Chart.js CDN is blocked)
   must not stop the others. The branch filter is wired up separately by
   branch-filter-widget.js, which every other admin page uses too. */
document.addEventListener('DOMContentLoaded', () => {
    try { buildSalesChart('revenue'); } catch (e) { console.error('sales chart failed:', e); }
    try { buildAbcChart(); }            catch (e) { console.error('ABC chart failed:', e); }
    try { loadAllSections(); }          catch (e) { console.error('dashboard sections failed:', e); }
});
