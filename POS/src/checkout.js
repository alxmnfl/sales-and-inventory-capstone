function openCheckout() {
  if (!cart.length) return;
  const { total } = cartTotals();
  const count     = cart.reduce((s, c) => s + c.qty, 0);

  document.getElementById('modalSubtitle').textContent = `${count} item${count !== 1 ? 's' : ''} · ${BRANCH}`;

  // Reset to CASH
  selectedPayment = 'CASH';
  document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('[data-method="CASH"]').classList.add('active');
  document.getElementById('cashSection').style.display     = '';
  document.getElementById('cashTendered').value            = '';
  document.getElementById('changeDisplay').style.display   = 'none';
  document.getElementById('btnCompleteSale').disabled      = true;

  // Quick amounts — nearest ₱500 above total, plus 3 more
  const quickAmts = buildQuickAmounts(total);
  document.getElementById('quickAmounts').innerHTML = quickAmts.map(a =>
    `<button class="quick-btn" onclick="setQuickAmount(${a})">${fmt(a)}</button>`
  ).join('');

  // Summary
  document.getElementById('summaryItems').innerHTML = cart.map(c => `
    <div class="summary-item">
      <span class="summary-item-name">
        ${escHtml(c.name)} <span class="summary-item-qty">×${c.qty}</span>
      </span>
      <span class="summary-item-price">${fmt(c.price * c.qty)}</span>
    </div>
  `).join('');
  document.getElementById('summaryTotal').textContent = fmt(total);

  document.getElementById('checkoutModal').style.display = 'flex';
}

function closeCheckout() {
  document.getElementById('checkoutModal').style.display = 'none';
}

function selectPayment(btn, method) {
  selectedPayment = method;
  document.querySelectorAll('.payment-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const cashSection = document.getElementById('cashSection');
  cashSection.style.display = method === 'CASH' ? '' : 'none';

  if (method === 'CASH') {
    document.getElementById('btnCompleteSale').disabled = true;
    updateChange();
  } else {
    document.getElementById('changeDisplay').style.display  = 'none';
    document.getElementById('btnCompleteSale').disabled     = false;
  }
}

function setQuickAmount(amount) {
  document.getElementById('cashTendered').value = amount.toFixed(2);
  updateChange();
}

function updateChange() {
  const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
  const { total } = cartTotals();
  const change    = tendered - total;

  const displayEl = document.getElementById('changeDisplay');
  if (tendered > 0 && change >= 0) {
    displayEl.style.display = 'flex';
    document.getElementById('changeAmount').textContent = fmt(change);
    document.getElementById('btnCompleteSale').disabled = false;
  } else {
    displayEl.style.display = 'none';
    document.getElementById('btnCompleteSale').disabled = true;
  }
}

function buildQuickAmounts(total) {
  const step = 500;
  const base = Math.ceil(total / step) * step;
  return [base, base + step, base + step * 2, base + step * 4];
}