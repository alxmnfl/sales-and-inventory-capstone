let auditPage = 1;
const AUDIT_PER_PAGE = 15;

function loadAuditTrail(branch, reset) {
    if (reset) auditPage = 1;

    const body     = document.getElementById('audit-trail-body');
    const loadMore = document.getElementById('audit-load-more');

    if (reset) body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`../api/audit_trail.php?branch=${encodeURIComponent(branch)}&page=${auditPage}&limit=${AUDIT_PER_PAGE}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div class="intel-empty">Error loading audit trail.</div>';
                loadMore.style.display = 'none';
                return;
            }

            if (!data.items.length && reset) {
                const notice = data.notice ? `<br><small>${esc(data.notice)}</small>` : '';
                body.innerHTML = `<div class="intel-empty">No audit records found.${notice}</div>`;
                loadMore.style.display = 'none';
                return;
            }

            if (reset) {
                body.innerHTML = `<table class="intel-table audit-table">
                    <thead><tr>
                        <th>Time</th><th>User</th><th>Branch</th>
                        <th>Action</th><th>Item</th><th>Details</th>
                    </tr></thead>
                    <tbody id="audit-tbody"></tbody>
                </table>`;
            }

            const tbody = document.getElementById('audit-tbody');
            data.items.forEach(item => {
                const ac = item.action.includes('DELETE') ? 'action-delete'
                         : item.action.includes('ADD')    ? 'action-add'
                         : item.action.includes('EDIT')   ? 'action-edit'
                         :                                   'action-sale';
                tbody.innerHTML += `<tr>
                    <td class="audit-time">${esc(item.created_at)}</td>
                    <td>${esc(item.user_name)}</td>
                    <td>${esc(item.branch)}</td>
                    <td><span class="action-badge ${ac}">${esc(item.action)}</span></td>
                    <td class="audit-item">${esc(item.entity_name ?? '—')}</td>
                    <td class="audit-detail">${esc(item.details ?? '')}</td>
                </tr>`;
            });

            loadMore.style.display = data.has_more ? 'flex' : 'none';
        })
        .catch(() => {
            body.innerHTML = '<div class="intel-empty">Failed to load audit trail.</div>';
            loadMore.style.display = 'none';
        });
}

function loadMoreAudit() {
    auditPage++;
    loadAuditTrail(selectedBranch(), false);
}
