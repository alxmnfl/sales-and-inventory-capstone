/* ── Chart defaults ── */
Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#9ca3af';

/* ── Init ── */
document.addEventListener('DOMContentLoaded', () => {
    buildSalesChart('revenue');
    buildAbcChart();
    loadAllSections();
    initBranchDropdown();
});

function initBranchDropdown() {
    const wrapper  = document.getElementById('branchFilterWrapper');
    const btn      = document.getElementById('branchSelectBtn');
    const panel    = document.getElementById('branchDropdownPanel');
    const label    = document.getElementById('branchSelectedLabel');
    const chevron  = document.getElementById('branchChevron');
    const hidden   = document.getElementById('globalBranchFilter');
    if (!wrapper || !btn || !panel) return;

    function openPanel() {
        panel.classList.remove('flip-right');
        panel.classList.add('open');
        chevron.classList.add('rotated');
        btn.setAttribute('aria-expanded', 'true');
        if (panel.getBoundingClientRect().right > window.innerWidth - 8) {
            panel.classList.add('flip-right');
        }
    }
    function closePanel() {
        panel.classList.remove('open');
        panel.classList.remove('flip-right');
        chevron.classList.remove('rotated');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        panel.classList.contains('open') ? closePanel() : openPanel();
    });

    panel.querySelectorAll('.branch-option').forEach(opt => {
        opt.addEventListener('click', () => {
            const val = opt.dataset.value;
            label.textContent = opt.querySelector('span').textContent;
            hidden.value = val;

            panel.querySelectorAll('.branch-option').forEach(o => {
                o.classList.remove('branch-option--selected');
                o.setAttribute('aria-selected', 'false');
            });
            opt.classList.add('branch-option--selected');
            opt.setAttribute('aria-selected', 'true');

            hidden.dispatchEvent(new Event('change'));
            closePanel();
        });
    });

    document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) closePanel();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePanel();
    });
}
