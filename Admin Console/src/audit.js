let auditPage   = 1;
let auditBranch = '';
const AUDIT_PER_PAGE = 15;

function loadAuditTrail(branch, page) {
    auditBranch = branch;
    auditPage   = page || 1;

    const body  = document.getElementById('audit-trail-body');
    const pager = document.getElementById('audit-pagination');

    body.innerHTML = '<div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
    pager.innerHTML = '';

    fetch(`../api/audit_trail.php?branch=${encodeURIComponent(branch)}&page=${auditPage}&limit=${AUDIT_PER_PAGE}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div class="intel-empty">Error loading audit trail.</div>';
                return;
            }

            if (!data.items.length) {
                const notice = data.notice ? `<br><small>${esc(data.notice)}</small>` : '';
                body.innerHTML = `<div class="intel-empty">No audit records found.${notice}</div>`;
                return;
            }

            const rows = data.items.map(item => {
                const ac = item.action.includes('DELETE') ? 'action-delete'
                         : item.action.includes('ADD')    ? 'action-add'
                         : item.action.includes('EDIT')   ? 'action-edit'
                         :                                   'action-sale';
                return `<tr>
                    <td class="audit-time">${esc(item.created_at)}</td>
                    <td>${esc(item.user_name)}</td>
                    <td>${esc(item.branch)}</td>
                    <td><span class="action-badge ${ac}">${esc(item.action)}</span></td>
                    <td class="audit-item">${esc(item.entity_name ?? '—')}</td>
                    <td class="audit-detail">${esc(item.details ?? '')}</td>
                </tr>`;
            }).join('');

            body.innerHTML = `<table class="intel-table audit-table">
                <thead><tr>
                    <th>Time</th><th>User</th><th>Branch</th>
                    <th>Action</th><th>Item</th><th>Details</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;

            renderAuditPagination(data.page, data.pages);
        })
        .catch(() => {
            body.innerHTML = '<div class="intel-empty">Failed to load audit trail.</div>';
        });
}

function renderAuditPagination(page, pages) {
    const pager = document.getElementById('audit-pagination');
    if (pages <= 1) { pager.innerHTML = ''; return; }

    const start = Math.max(1, page - 2);
    const end   = Math.min(pages, page + 2);

    let html = `<button type="button" class="pg-btn${page<=1?' disabled':''}" onclick="loadAuditTrail(auditBranch, ${Math.max(1,page-1)})"><i class="fa-solid fa-chevron-left"></i></button>`;
    for (let p = start; p <= end; p++) {
        html += `<button type="button" class="pg-btn${p===page?' active':''}" onclick="loadAuditTrail(auditBranch, ${p})">${p}</button>`;
    }
    html += `<button type="button" class="pg-btn${page>=pages?' disabled':''}" onclick="loadAuditTrail(auditBranch, ${Math.min(pages,page+1)})"><i class="fa-solid fa-chevron-right"></i></button>`;

    pager.innerHTML = html;
}
