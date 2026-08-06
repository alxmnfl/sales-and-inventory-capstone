function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function selectedBranch() {
    return document.getElementById('globalBranchFilter')?.value ?? '';
}

/* ── Load all sections (called on branch change or page init) ── */
function loadAllSections() {
    const branch = selectedBranch();
    loadDashboardKpis(branch);
    loadFastMoving(branch);
    loadCriticalStock(branch);
    loadPredictiveAlerts(branch);
    auditPage = 1;
    loadAuditTrail(branch, true);
}
