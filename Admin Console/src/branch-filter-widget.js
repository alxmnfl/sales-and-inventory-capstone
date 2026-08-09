/* ── Custom branch-filter dropdown (multi-instance) ──
   Powers any .branch-filter widget: toggles its .branch-dropdown-panel,
   mirrors the chosen option into the hidden <select class="branch-filter-hidden-select">
   so the surrounding <form> submits it like a normal select. */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.branch-filter').forEach(function (wrapper) {
        var btn     = wrapper.querySelector('.branch-select-btn');
        var panel   = wrapper.querySelector('.branch-dropdown-panel');
        var label   = wrapper.querySelector('.branch-selected-label');
        var chevron = wrapper.querySelector('.branch-chevron');
        var hidden  = wrapper.querySelector('.branch-filter-hidden-select');
        if (!btn || !panel || !hidden) return;

        function openPanel() {
            panel.classList.add('open');
            if (chevron) chevron.classList.add('rotated');
            btn.setAttribute('aria-expanded', 'true');
        }
        function closePanel() {
            panel.classList.remove('open');
            if (chevron) chevron.classList.remove('rotated');
            btn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.contains('open') ? closePanel() : openPanel();
        });

        panel.querySelectorAll('.branch-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var val = opt.dataset.value;
                if (label) label.textContent = opt.querySelector('span').textContent;
                hidden.value = val;

                panel.querySelectorAll('.branch-option').forEach(function (o) {
                    o.classList.remove('branch-option--selected');
                    o.setAttribute('aria-selected', 'false');
                });
                opt.classList.add('branch-option--selected');
                opt.setAttribute('aria-selected', 'true');

                hidden.dispatchEvent(new Event('change'));
                closePanel();
            });
        });

        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) closePanel();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.branch-dropdown-panel.open').forEach(function (p) {
            p.classList.remove('open');
        });
        document.querySelectorAll('.branch-chevron.rotated').forEach(function (c) {
            c.classList.remove('rotated');
        });
    });
});
