/* ── Header hamburger menu (Transfers / Deliveries / Logout) ──
   Toggles the .hdr-menu-panel next to the trigger button; closes on
   outside click or Escape. */
document.addEventListener('DOMContentLoaded', function () {
    var btn   = document.getElementById('hdrMenuBtn');
    var panel = document.getElementById('hdrMenuPanel');
    if (!btn || !panel) return;

    function openMenu() {
        panel.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
    }
    function closeMenu() {
        panel.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.contains('open') ? closeMenu() : openMenu();
    });

    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && e.target !== btn) closeMenu();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });
});
