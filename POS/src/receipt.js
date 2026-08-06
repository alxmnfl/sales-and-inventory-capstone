function showReceipt(data) {
  const now = data.created_at
    ? new Date(data.created_at.replace(' ', 'T'))
    : new Date();

  document.getElementById('saleCompleteDate').textContent = now.toLocaleDateString('en-US', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });
  document.getElementById('receiptTxId').textContent    = data.transaction_id;
  document.getElementById('receiptTime').textContent    = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
  document.getElementById('receiptCashier').textContent = data.cashier;

  document.getElementById('receiptItems').innerHTML = data.items.map(i => `
    <div class="receipt-row">
      <span>${escHtml(i.product_name)} ×${i.quantity}</span>
      <span>${fmt(i.total_price)}</span>
    </div>
  `).join('');

  document.getElementById('receiptSubtotal').textContent = fmt(data.subtotal);
  document.getElementById('receiptVat').textContent      = fmt(data.vat);
  document.getElementById('receiptTotal').textContent    = fmt(data.total);

  document.getElementById('saleComplete').style.display = 'flex';
}

function newSale() {
  document.getElementById('saleComplete').style.display = 'none';
}
