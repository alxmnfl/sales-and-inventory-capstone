// Lucky 8 POS — Stock adjustment (any branch, quantity only)

let stockEditId = null;

function openStockModal(id) {
  const p = products.find(x => x.id === id);
  if (!p) return;

  stockEditId = id;
  document.getElementById('stockModalSubtitle').textContent = `${p.name} — ${p.sku}`;
  document.getElementById('stockCurrentLabel').textContent = `Current stock: ${p.stock}`;
  document.getElementById('stockNewValue').value = p.stock;
  document.getElementById('stockModal').style.display = 'flex';
}

function closeStockModal() {
  document.getElementById('stockModal').style.display = 'none';
  stockEditId = null;
}

async function saveStock() {
  if (stockEditId === null) return;

  const input = document.getElementById('stockNewValue');
  const newStock = parseInt(input.value, 10);

  if (isNaN(newStock) || newStock < 0) {
    alert('Enter a valid stock quantity (0 or more).');
    return;
  }

  const btn = document.getElementById('btnSaveStock');
  btn.disabled = true;

  try {
    const res = await fetch('../api/update_stock.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ product_id: stockEditId, new_stock: newStock }),
    });
    const data = await res.json();

    if (!data.success) {
      alert(data.message || 'Failed to update stock.');
      return;
    }

    const p = products.find(x => x.id === stockEditId);
    if (p) p.stock = data.stock;
    renderProducts();
    closeStockModal();
  } catch {
    alert('Could not reach the server.');
  } finally {
    btn.disabled = false;
  }
}
