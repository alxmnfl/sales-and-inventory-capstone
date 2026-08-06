async function completeSale() {
  const { subtotal, vat, total } = cartTotals();
  const cashTendered = selectedPayment === 'CASH'
    ? parseFloat(document.getElementById('cashTendered').value) || 0
    : null;

  if (selectedPayment === 'CASH' && cashTendered < total) {
    alert('Insufficient cash tendered.');
    return;
  }

  const btn = document.getElementById('btnCompleteSale');
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  const payload = {
    cashier:        CASHIER,
    branch:         BRANCH,
    payment_method: selectedPayment,
    subtotal, vat, total,
    cash_tendered:  cashTendered,
    items: cart.map(c => ({
      product_id:   c.id,
      product_name: c.name,
      sku:          c.sku,
      quantity:     c.qty,
      unit_price:   c.price,
      total_price:  c.price * c.qty
    }))
  };

  try {
    const res  = await fetch('../api/complete_sale.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      closeCheckout();
      cart = [];
      updateCart();
      loadProducts();       // refresh stock counts
      showReceipt(data);
    } else {
      alert(data.message || 'Failed to complete sale.');
      btn.disabled    = false;
      btn.textContent = '✓ COMPLETE SALE';
    }
  } catch {
    alert('Network error. Please try again.');
    btn.disabled    = false;
    btn.textContent = '✓ COMPLETE SALE';
  }
}