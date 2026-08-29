// Lucky 8 POS — Frontend Logic

const VAT_RATE = 0.12;
let products = [];
let cart = [];
let selectedPayment = 'CASH';
let currentCategory = 'All Products';
let searchQuery = '';

// ─── INIT

document.addEventListener('DOMContentLoaded', () => {
  loadProducts();

  document.getElementById('searchInput').addEventListener('input', e => {
    searchQuery = e.target.value.trim().toLowerCase();
    renderProducts();
  });

  // Category tabs are built from real data in renderCategoryTabs() (products.js)
  // once the branch's catalogue has loaded.
});

// ─── HELPERS

function fmt(amount) {
  return '₱' + parseFloat(amount).toLocaleString('en-US', {
    minimumFractionDigits: 2, maximumFractionDigits: 2
  });
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}