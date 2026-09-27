/* ── Shared modal-close helpers (used by every page with .modal-bg dialogs:
   Inventory, Deliveries, Transfers, Users) ── */
function closeModal(id) {
    var m = document.getElementById(id);
    m.classList.remove('open');
    m.querySelectorAll('.cselect.open').forEach(function (c) { c.classList.remove('open'); });
}

document.querySelectorAll('.modal-bg').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) closeModal(m.id); });
});
