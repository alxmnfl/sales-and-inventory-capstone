const branches = [
    { code: 'DIN', name: 'Lucky 8 — Dinalupihan',    id: 'DIN-01', region: 'Bataan · Central Luzon' },
    { code: 'LPC', name: 'Lucky 8 — Las Piñas City', id: 'LPC-01', region: 'Las Piñas · NCR' },
    { code: 'VIZ', name: 'Lucky 8 — Viscaya',         id: 'VIZ-01', region: 'Nueva Vizcaya · Cagayan Valley' },
    { code: 'BAM', name: 'Lucky 8 — Bambang',         id: 'BAM-01', region: 'Bambang · Nueva Vizcaya' },
    { code: 'BGB', name: 'Lucky 8 — Bagabag',         id: 'BGB-01', region: 'Bagabag · Nueva Vizcaya' },
    { code: 'BAG', name: 'Win Flex — Baguio',          id: 'BAG-01', region: 'Baguio City · CAR' },
    { code: 'DSM', name: 'LIMA — Dasmariñas',          id: 'DSM-01', region: 'Dasmariñas · CALABARZON' },
    { code: 'STR', name: 'SMDA — Sta. Rosa',           id: 'STR-01', region: 'Sta. Rosa · CALABARZON' },
    { code: 'CST', name: 'Win Flex — Castilla',        id: 'CST-01', region: 'Castilla · Bicol' },
    { code: 'SPA', name: "Matthew's — San Pablo",      id: 'SPA-01', region: 'San Pablo · CALABARZON' },
    { code: 'NAG', name: 'Win Flex — Naga',            id: 'NAG-01', region: 'Naga City · Bicol' },
    { code: 'SUC', name: 'Win Flex — Sucat',           id: 'SUC-01', region: 'Sucat · NCR' },
    { code: 'BNA', name: 'Win Flex — Bañag',           id: 'BNA-01', region: 'Bañag · CALABARZON' },
    { code: 'LIG', name: 'Win Flex — Ligao',           id: 'LIG-01', region: 'Ligao City · Bicol' },
    { code: 'SP2', name: 'Win Flex — San Pablo',       id: 'SP2-01', region: 'San Pablo · CALABARZON' },
    { code: 'LIP', name: "Matthew's — Lipa",           id: 'LIP-01', region: 'Lipa City · CALABARZON' },
    { code: 'MOL', name: 'Crown Flex — Molino',        id: 'MOL-01', region: 'Molino · CALABARZON' },
    { code: 'CAS', name: 'Win Flex — Castellejos',     id: 'CAS-01', region: 'Castellejos · Zambales' },
];

function renderBranches(list) {
    const container = document.getElementById('branchList');
    if (!container) return;
    if (list.length === 0) {
        container.innerHTML = '<div class="branch-empty">No branches found.</div>';
        return;
    }
    container.innerHTML = list.map(b => `
        <div class="branch-item" onclick="selectBranch('${b.name}', '${b.code}')">
            <div class="branch-badge">${b.code}</div>
            <div class="branch-info">
                <span class="branch-name">${b.name}</span>
                <span class="branch-meta"><span class="branch-id">${b.id}</span> · ${b.region}</span>
            </div>
        </div>
    `).join('');
}

function toggleBranchDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('branchDropdown');
    const arrow    = document.getElementById('branchArrow');
    const isOpen   = dropdown.classList.contains('open');
    dropdown.classList.toggle('open');
    arrow.classList.toggle('open', !isOpen);
    if (!isOpen) {
        setTimeout(() => document.getElementById('branchSearch').focus(), 50);
    }
}

function filterBranches() {
    const q = document.getElementById('branchSearch').value.toLowerCase();
    renderBranches(branches.filter(b =>
        b.name.toLowerCase().includes(q) ||
        b.code.toLowerCase().includes(q) ||
        b.id.toLowerCase().includes(q) ||
        b.region.toLowerCase().includes(q)
    ));
}

function selectBranch(name, code) {
    const display = document.getElementById('branchDisplay');
    display.textContent = name;
    display.classList.add('selected');

    const hiddenInput = document.getElementById('branchInput');
    if (hiddenInput) hiddenInput.value = name;

    document.getElementById('branchDropdown').classList.remove('open');
    document.getElementById('branchArrow').classList.remove('open');
    document.getElementById('branchSearch').value = '';
    renderBranches(branches);
}


function switchTab(tab) {
    const signinTab    = document.getElementById('tab-signin');
    const registerTab  = document.getElementById('tab-register');
    const signinForm   = document.getElementById('form-signin');
    const registerForm = document.getElementById('form-register');

    if (tab === 'signin') {
        signinTab.classList.add('active');
        registerTab.classList.remove('active');
        signinForm.style.display   = 'flex';
        registerForm.style.display = 'none';
    } else {
        registerTab.classList.add('active');
        signinTab.classList.remove('active');
        registerForm.style.display = 'flex';
        signinForm.style.display   = 'none';
    }
}

function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.querySelector('.toggle-pw');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function toggleRegPassword(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function selectRole(card, roleValue) {
    document.querySelectorAll('.role-card').forEach(c => c.classList.remove('active'));
    card.classList.add('active');

    const hiddenInput = document.getElementById('roleInput');
    if (hiddenInput) hiddenInput.value = roleValue;

    const trigger     = document.getElementById('branchSelect')?.querySelector('.custom-select-trigger');
    const display     = document.getElementById('branchDisplay');
    const branchInput = document.getElementById('branchInput');

    if (roleValue === 'administrator') {
        if (trigger) trigger.classList.add('disabled');
        if (display) { display.textContent = 'All Branches — not applicable'; display.classList.add('selected'); }
        if (branchInput) branchInput.value = '';

        document.getElementById('branchDropdown')?.classList.remove('open');
        document.getElementById('branchArrow')?.classList.remove('open');
    } else {

        if (trigger) trigger.classList.remove('disabled');
        if (display) { display.textContent = 'Select one of 19 branches...'; display.classList.remove('selected'); }
        if (branchInput) branchInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderBranches(branches);

    const tab = typeof initialTab !== 'undefined' ? initialTab : 'signin';
    switchTab(tab);

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#branchSelect')) {
            const dropdown = document.getElementById('branchDropdown');
            const arrow    = document.getElementById('branchArrow');
            if (dropdown) dropdown.classList.remove('open');
            if (arrow)    arrow.classList.remove('open');
        }
    });
});
