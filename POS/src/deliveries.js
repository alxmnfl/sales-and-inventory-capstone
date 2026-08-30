// Lucky 8 POS — Inventory delivery receiving

let currentDelivery = null;

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Show a success message stashed just before a reload
document.addEventListener('DOMContentLoaded', () => {
  try {
    const raw = sessionStorage.getItem('dlvFlash');
    if (raw) {
      sessionStorage.removeItem('dlvFlash');
      const f = JSON.parse(raw);
      showSuccess(f.title, f.sub);
    }
  } catch (e) { /* ignore */ }

  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
      if (e.target !== m) return;
      if (m.id === 'dlvDisputeModal') { closeDispute(); return; }
      if (m.id === 'dlvReviewModal') {
        const ic = document.getElementById('dlvInlineConfirm');
        if (ic && !ic.hidden) { cancelConfirm(); return; }  // back out of the confirm, keep the checklist open
      }
      m.style.display = 'none';
    });
  });
});

function showSuccess(title, sub) {
  document.getElementById('dlvSuccessTitle').textContent = title;
  document.getElementById('dlvSuccessSub').textContent = sub;
  document.getElementById('dlvSuccess').style.display = 'flex';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ─── Review / checklist ───

function openReview(idx) {
  currentDelivery = DELIVERIES[idx];
  if (!currentDelivery) return;
  const d = currentDelivery;
  const actionable = d.status === 'sent';

  document.getElementById('dlvReviewRef').textContent = d.reference;
  document.getElementById('dlvReviewMeta').textContent =
    `${d.branch} · ${d.line_count} products · ${d.unit_count} units · sent ${d.created_at}`;

  const noteEl = document.getElementById('dlvReviewNote');
  if (d.note) { noteEl.textContent = 'Note from head office: ' + d.note; noteEl.style.display = 'block'; }
  else noteEl.style.display = 'none';

  document.getElementById('dlvCheckBody').innerHTML = (d.items || []).map(it => `
    <tr>
      <td>
        <div class="dlv-ci-name">${esc(it.name)}</div>
        <div class="dlv-ci-cat">${esc(it.category || '—')}</div>
      </td>
      <td class="dlv-mono">${esc(it.sku)}</td>
      <td class="col-r dlv-ci-qty">${it.qty_sent}</td>
      <td class="col-c">${actionable
        ? '<label class="dlv-chk-wrap"><input type="checkbox" class="dlv-chk" onchange="toggleOk(this)"></label>'
        : '<i class="fa-solid fa-check dlv-ci-ok"></i>'}</td>
    </tr>`).join('');

  document.getElementById('dlvReviewError').style.display = 'none';
  document.getElementById('dlvInlineConfirm').hidden = true;
  document.getElementById('dlvReviewFooter').style.display = actionable ? 'flex' : 'none';
  document.getElementById('dlvViewFooter').style.display   = actionable ? 'none' : 'flex';
  document.getElementById('dlvCompleteBtn').disabled = true;

  document.getElementById('dlvReviewModal').style.display = 'flex';
}

function closeReview() {
  document.getElementById('dlvReviewModal').style.display = 'none';
}

function allChecked() {
  const boxes = [...document.querySelectorAll('#dlvCheckBody .dlv-chk')];
  return boxes.length > 0 && boxes.every(b => b.checked);
}

function toggleOk(box) {
  box.closest('tr').classList.toggle('is-ok', box.checked);
  updateCompleteBtn();
}

function updateCompleteBtn() {
  document.getElementById('dlvCompleteBtn').disabled = !allChecked();
}

// ─── Inline "are you sure?" confirmation (pops inside the review modal) ───

function askConfirm() {
  if (!allChecked()) return;
  document.getElementById('dlvConfirmBranch').textContent = currentDelivery.branch;
  document.getElementById('dlvConfirmError').style.display = 'none';
  document.getElementById('dlvConfirmBtn').disabled = false;
  document.getElementById('dlvReviewFooter').style.display = 'none';
  const box = document.getElementById('dlvInlineConfirm');
  box.hidden = false;
  box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function cancelConfirm() {
  const box = document.getElementById('dlvInlineConfirm');
  if (!box || box.hidden) return;
  box.hidden = true;
  document.getElementById('dlvConfirmError').style.display = 'none';
  document.getElementById('dlvReviewFooter').style.display = 'flex';
}

async function submitComplete() {
  const btn = document.getElementById('dlvConfirmBtn');
  const err = document.getElementById('dlvConfirmError');
  btn.disabled = true;
  err.style.display = 'none';

  try {
    const res = await fetch('../api/deliveries.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'complete', delivery_id: currentDelivery.id }),
    });
    const data = await res.json();

    if (!data.success) {
      err.textContent = data.message || 'Could not complete the delivery.';
      err.style.display = 'block';
      return;
    }

    try {
      sessionStorage.setItem('dlvFlash', JSON.stringify({
        title: `Delivery ${data.reference} received.`,
        sub: `${data.lines} product${data.lines !== 1 ? 's' : ''} · ${data.units} unit${data.units !== 1 ? 's' : ''} added to ${BRANCH} stock.`,
      }));
    } catch (e) { /* ignore */ }

    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.';
    err.style.display = 'block';
  } finally {
    btn.disabled = false;
  }
}

// ─── Report a problem ───

function openDispute() {
  document.getElementById('dlvDisputeText').value = '';
  document.getElementById('dlvDisputeError').style.display = 'none';
  document.getElementById('dlvReviewModal').style.display = 'none';
  document.getElementById('dlvDisputeModal').style.display = 'flex';
}

function closeDispute() {
  document.getElementById('dlvDisputeModal').style.display = 'none';
  document.getElementById('dlvReviewModal').style.display = 'flex';
}

async function submitDispute() {
  const text = document.getElementById('dlvDisputeText').value.trim();
  const err = document.getElementById('dlvDisputeError');
  if (!text) {
    err.textContent = 'Please describe the problem.';
    err.style.display = 'block';
    return;
  }

  const btn = document.getElementById('dlvDisputeBtn');
  btn.disabled = true;
  err.style.display = 'none';

  try {
    const res = await fetch('../api/deliveries.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'dispute', delivery_id: currentDelivery.id, remarks: text }),
    });
    const data = await res.json();

    if (!data.success) {
      err.textContent = data.message || 'Could not send the report.';
      err.style.display = 'block';
      return;
    }

    try {
      sessionStorage.setItem('dlvFlash', JSON.stringify({
        title: `Problem reported for ${data.reference}.`,
        sub: 'Head office has been notified. Stock was not changed.',
      }));
    } catch (e) { /* ignore */ }

    location.reload();
  } catch {
    err.textContent = 'Could not reach the server.';
    err.style.display = 'block';
  } finally {
    btn.disabled = false;
  }
}
