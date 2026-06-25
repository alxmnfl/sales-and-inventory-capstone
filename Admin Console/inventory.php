<?php
session_start();
require_once '../Landing Page/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── CRUD handling ── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $sku   = trim($_POST['sku']      ?? '');
        $name  = trim($_POST['name']     ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock']   ?? 0);
        $branch= trim($_POST['branch']   ?? '');
        $stmt  = $conn->prepare("INSERT INTO pos_products (sku,name,category,price,stock,branch,added_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssdiis',$sku,$name,$cat,$price,$stock,$branch,$_SESSION['user_id']);
        $stmt->execute() ? $flash='added' : $flash='err:'.$conn->error;
        $stmt->close();
    }

    if ($action === 'edit') {
        $id    = (int)($_POST['id']       ?? 0);
        $name  = trim($_POST['name']      ?? '');
        $cat   = trim($_POST['category']  ?? '');
        $price = (float)($_POST['price']  ?? 0);
        $stock = (int)($_POST['stock']    ?? 0);
        $branch= trim($_POST['branch']    ?? '');
        $stmt  = $conn->prepare("UPDATE pos_products SET name=?,category=?,price=?,stock=?,branch=? WHERE id=?");
        $stmt->bind_param('sssdsi',$name,$cat,$price,$stock,$branch,$id);
        $stmt->execute();
        $stmt->close();
        $flash = 'edited';
    }

    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM pos_products WHERE id=?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $stmt->close();
        $flash = 'deleted';
    }

    header('Location: inventory.php'.($flash?'?flash='.$flash:'')); exit;
}

/* ── KPIs ── */
$r  = $conn->query("SELECT COUNT(*), COUNT(DISTINCT category), SUM(stock), SUM(price*stock) FROM pos_products");
[$total_products, $total_cats, $total_stock, $total_value] = $r->fetch_row();

$r  = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock=0");
$out_of_stock = (int)$r->fetch_row()[0];

$r  = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock>0 AND stock<10");
$low_stock = (int)$r->fetch_row()[0];

/* ── Product list ── */
$products = [];
$r = $conn->query("SELECT * FROM pos_products ORDER BY branch, name");
while ($row = $r->fetch_assoc()) $products[] = $row;

/* ── Branch & category lists for dropdowns ── */
$branches = [];
$r = $conn->query("SELECT DISTINCT UPPER(branch) b FROM pos_products WHERE branch!='' ORDER BY b");
while ($row = $r->fetch_row()) $branches[] = $row[0];

$categories = [];
$r = $conn->query("SELECT DISTINCT category FROM pos_products WHERE category!='' ORDER BY category");
while ($row = $r->fetch_row()) $categories[] = $row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Inventory</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.inv-filters{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.inv-filters input,.inv-filters select{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.inv-filters input:focus,.inv-filters select:focus{border-color:#e8611a;}
.inv-filters input{flex:1;min-width:180px;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background 0.2s;}
.btn-orange:hover{background:#c94e0f;}
.btn-ghost{background:transparent;color:#6b7280;border:1px solid #e5e7eb;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;transition:all 0.2s;}
.btn-ghost:hover{border-color:#e8611a;color:#e8611a;}
.btn-danger{background:transparent;color:#ef4444;border:1px solid #fecaca;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;transition:all 0.2s;}
.btn-danger:hover{background:#fef2f2;}
.stock-ok{color:#10b981;font-weight:600;}
.stock-low{color:#f59e0b;font-weight:600;}
.stock-out{color:#ef4444;font-weight:600;}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:500;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px;width:480px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,0.2);}
.modal h3{font-size:16px;font-weight:700;color:#111827;margin-bottom:20px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px;}
.form-group label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;}
.form-group input,.form-group select{padding:8px 11px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#111827;outline:none;}
.form-group input:focus,.form-group select:focus{border-color:#e8611a;}
.modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;}
.flash{padding:10px 16px;border-radius:8px;font-size:13px;font-weight:600;margin-bottom:16px;}
.flash.ok{background:#ecfdf5;color:#10b981;border:1px solid #a7f3d0;}
.flash.err{background:#fef2f2;color:#ef4444;border:1px solid #fecaca;}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Inventory</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <?php if (isset($_GET['flash'])): ?>
        <div class="flash ok">
            <?php
            $f = $_GET['flash'];
            echo $f==='added'?'Product added successfully.':($f==='edited'?'Product updated.':($f==='deleted'?'Product deleted.':'Done.'));
            ?>
        </div>
        <?php endif; ?>

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Products</span><div class="kpi-icon orange"><i class="fa-solid fa-boxes-stacked"></i></div></div>
                <div class="kpi-value"><?=number_format((int)$total_products)?></div>
                <div class="kpi-meta"><?=number_format((int)$total_cats)?> categories</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Stock</span><div class="kpi-icon green"><i class="fa-solid fa-cubes"></i></div></div>
                <div class="kpi-value"><?=number_format((int)$total_stock)?></div>
                <div class="kpi-meta">units across all branches</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Out of Stock</span><div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
                <div class="kpi-value"><?=(int)$out_of_stock?></div>
                <div class="kpi-meta"><?=(int)$low_stock?> more items low stock</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Inventory Value</span><div class="kpi-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fa-solid fa-peso-sign"></i></div></div>
                <div class="kpi-value">₱<?=number_format((float)$total_value,0)?></div>
                <div class="kpi-meta">estimated total value</div>
            </div>
        </div>

        <!-- Table -->
        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Product List</div><div class="chart-subtitle">All products across all branches</div></div>
                <button class="btn-orange" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Product</button>
            </div>

            <div class="inv-filters">
                <input type="text" id="searchInp" placeholder="Search name or SKU…" oninput="filterTable()">
                <select id="branchSel" onchange="filterTable()">
                    <option value="">All Branches</option>
                    <?php foreach($branches as $b):?><option><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
                <select id="catSel" onchange="filterTable()">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $c):?><option><?=htmlspecialchars($c)?></option><?php endforeach;?>
                </select>
            </div>

            <table class="intel-table" id="invTable">
                <thead><tr>
                    <th>SKU</th><th>Product</th><th>Category</th><th>Branch</th>
                    <th class="col-r">Price</th><th class="col-r">Stock</th><th class="col-r">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($products as $p):
                    $sc = $p['stock']==0?'stock-out':($p['stock']<10?'stock-low':'stock-ok');
                ?>
                <tr data-name="<?=strtolower(htmlspecialchars($p['name']))?>"
                    data-sku="<?=strtolower(htmlspecialchars($p['sku']))?>"
                    data-branch="<?=strtoupper(htmlspecialchars($p['branch']))?>"
                    data-cat="<?=htmlspecialchars($p['category'])?>">
                    <td class="col-mono"><?=htmlspecialchars($p['sku'])?></td>
                    <td><div class="prod-name"><?=htmlspecialchars($p['name'])?></div></td>
                    <td><?=htmlspecialchars($p['category'])?></td>
                    <td><?=htmlspecialchars(strtoupper($p['branch']))?></td>
                    <td class="col-r">₱<?=number_format((float)$p['price'],2)?></td>
                    <td class="col-r <?=$sc?>"><?=(int)$p['stock']?></td>
                    <td class="col-r" style="white-space:nowrap;">
                        <button class="btn-ghost" onclick='openEditModal(<?=json_encode($p)?>)'>Edit</button>
                        <button class="btn-danger" onclick="confirmDelete(<?=(int)$p['id']?>, '<?=addslashes(htmlspecialchars($p['name']))?>')">Delete</button>
                    </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-bg" id="addModal">
    <div class="modal">
        <h3><i class="fa-solid fa-plus" style="color:#e8611a;margin-right:8px;"></i>Add Product</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group"><label>SKU</label><input name="sku" required placeholder="e.g. PVC-001"></div>
                <div class="form-group"><label>Category</label><input name="category" required list="catList" placeholder="e.g. Hoses"></div>
            </div>
            <div class="form-group"><label>Product Name</label><input name="name" required placeholder="Full product name"></div>
            <div class="form-row">
                <div class="form-group"><label>Price (₱)</label><input name="price" type="number" step="0.01" min="0" required placeholder="0.00"></div>
                <div class="form-group"><label>Stock</label><input name="stock" type="number" min="0" required placeholder="0"></div>
            </div>
            <div class="form-group"><label>Branch</label>
                <select name="branch" required>
                    <option value="">— Select branch —</option>
                    <?php foreach($branches as $b):?><option><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
            </div>
            <datalist id="catList"><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?php endforeach;?></datalist>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-orange">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-bg" id="editModal">
    <div class="modal">
        <h3><i class="fa-solid fa-pen" style="color:#e8611a;margin-right:8px;"></i>Edit Product</h3>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="editId">
            <div class="form-group"><label>Product Name</label><input name="name" id="editName" required></div>
            <div class="form-row">
                <div class="form-group"><label>Category</label><input name="category" id="editCat" required list="catList2"></div>
                <div class="form-group"><label>Branch</label><input name="branch" id="editBranch" required list="branchList"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Price (₱)</label><input name="price" id="editPrice" type="number" step="0.01" min="0" required></div>
                <div class="form-group"><label>Stock</label><input name="stock" id="editStock" type="number" min="0" required></div>
            </div>
            <datalist id="catList2"><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?php endforeach;?></datalist>
            <datalist id="branchList"><?php foreach($branches as $b):?><option value="<?=htmlspecialchars($b)?>"><?php endforeach;?></datalist>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-orange">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete form -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
function filterTable(){
    var q=document.getElementById('searchInp').value.toLowerCase();
    var br=document.getElementById('branchSel').value.toUpperCase();
    var cat=document.getElementById('catSel').value;
    document.querySelectorAll('#invTable tbody tr').forEach(function(tr){
        var name=tr.dataset.name,sku=tr.dataset.sku,b=tr.dataset.branch,c=tr.dataset.cat;
        var show=((!q||(name.includes(q)||sku.includes(q)))&&(!br||b===br)&&(!cat||c===cat));
        tr.style.display=show?'':'none';
    });
}
function openAddModal(){document.getElementById('addModal').classList.add('open');}
function openEditModal(p){
    document.getElementById('editId').value=p.id;
    document.getElementById('editName').value=p.name;
    document.getElementById('editCat').value=p.category;
    document.getElementById('editBranch').value=p.branch.toUpperCase();
    document.getElementById('editPrice').value=p.price;
    document.getElementById('editStock').value=p.stock;
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function confirmDelete(id,name){
    if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
}
document.querySelectorAll('.modal-bg').forEach(function(m){
    m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open');});
});
</script>
</body>
</html>
