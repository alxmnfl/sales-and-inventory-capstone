<?php
session_start();
require_once '../../Landing Page/php/db.php';
require_once '../../Landing Page/php/branch_list.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Branch list — every company branch, even ones with no products yet ── */
$branches = all_branches($conn);

/* ── Resolve which branch (if any) is being viewed ── */
$viewBranch = isset($_GET['branch']) ? l8_upper(trim($_GET['branch'])) : '';
if ($viewBranch !== '' && !in_array($viewBranch, $branches, true)) {
    header('Location: inventory.php?flash=err:Unknown branch.'); exit;
}

/* ── CRUD handling ── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $auditUserId   = (int)($_SESSION['user_id']   ?? 0);
    $auditUserName = $_SESSION['user_name']   ?? 'Admin';
    $auditBranch   = $_SESSION['user_branch'] ?? '';

    $redirectBranch = l8_upper(trim($_POST['redirect_branch'] ?? ''));

    if ($action === 'add') {
        $sku   = trim($_POST['sku']      ?? '');
        $name  = trim($_POST['name']     ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = (int)($_POST['stock']   ?? 0);
        $branch= strtoupper(trim($_POST['branch'] ?? ''));
        $stmt  = $conn->prepare("INSERT INTO pos_products (sku,name,category,price,stock,branch,added_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssdisi',$sku,$name,$cat,$price,$stock,$branch,$_SESSION['user_id']);
        if ($branch === '') {
            $flash = 'err:Select a branch for this product.';
        } elseif ($stmt->execute()) {
            $flash    = 'added';
            $newId    = $stmt->insert_id;
            $detail   = "SKU: $sku | Category: $cat | Price: ₱".number_format($price,2)." | Stock: $stock";
            $auditStmt = $conn->prepare(
                "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
                 VALUES (?, ?, ?, 'ADD_PRODUCT', 'product', ?, ?, ?)"
            );
            $auditStmt->bind_param('ississ', $auditUserId, $auditUserName, $auditBranch, $newId, $name, $detail);
            $auditStmt->execute();
            $auditStmt->close();
        } else {
            $flash = 'err:'.$conn->error;
        }
        $stmt->close();
    }

    if ($action === 'edit') {
        $id    = (int)($_POST['id']       ?? 0);
        $name  = trim($_POST['name']      ?? '');
        $cat   = trim($_POST['category']  ?? '');
        $price = (float)($_POST['price']  ?? 0);
        $stock = (int)($_POST['stock']    ?? 0);
        $branch= strtoupper(trim($_POST['branch'] ?? ''));
        $stmt  = $conn->prepare("UPDATE pos_products SET name=?,category=?,price=?,stock=?,branch=? WHERE id=?");
        $stmt->bind_param('ssdisi',$name,$cat,$price,$stock,$branch,$id);
        $stmt->execute();
        $stmt->close();
        $flash = 'edited';

        $detail    = "Category: $cat | Price: ₱".number_format($price,2)." | Stock: $stock | Branch: ".($branch?:'—');
        $auditStmt = $conn->prepare(
            "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
             VALUES (?, ?, ?, 'EDIT_PRODUCT', 'product', ?, ?, ?)"
        );
        $editAuditBranch = $branch ?: $auditBranch;
        $auditStmt->bind_param('ississ', $auditUserId, $auditUserName, $editAuditBranch, $id, $name, $detail);
        $auditStmt->execute();
        $auditStmt->close();
    }

    if ($action === 'delete') {
        $id   = (int)($_POST['id'] ?? 0);

        $nameStmt = $conn->prepare("SELECT name, sku, branch FROM pos_products WHERE id=?");
        $nameStmt->bind_param('i',$id);
        $nameStmt->execute();
        $nameStmt->bind_result($delName, $delSku, $delBranch);
        $nameStmt->fetch();
        $nameStmt->close();

        $stmt = $conn->prepare("DELETE FROM pos_products WHERE id=?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $stmt->close();
        $flash = 'deleted';

        $detail    = "SKU: ".($delSku ?? '—');
        $delAuditBranch = $delBranch ?: $auditBranch;
        $auditStmt = $conn->prepare(
            "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
             VALUES (?, ?, ?, 'DELETE_PRODUCT', 'product', ?, ?, ?)"
        );
        $entityName = $delName ?? "Product #$id";
        $auditStmt->bind_param('ississ', $auditUserId, $auditUserName, $delAuditBranch, $id, $entityName, $detail);
        $auditStmt->execute();
        $auditStmt->close();
    }

    $backTo = $redirectBranch !== '' ? 'inventory.php?branch='.urlencode($redirectBranch) : 'inventory.php';
    $sep    = strpos($backTo, '?') !== false ? '&' : '?';
    header('Location: '.$backTo.($flash?$sep.'flash='.$flash:'')); exit;
}

if ($viewBranch === '') {
    /* ══════════════════ BRANCH GRID VIEW ══════════════════ */

    $branch_data = [];
    foreach ($branches as $b) {
        $branch_data[$b] = ['name'=>$b,'products'=>0,'stock'=>0,'low_stock'=>0,'out_of_stock'=>0];
    }
    $r = $conn->query("SELECT UPPER(branch), COUNT(*), SUM(stock), SUM(CASE WHEN stock>0 AND stock<10 THEN 1 ELSE 0 END), SUM(CASE WHEN stock=0 THEN 1 ELSE 0 END) FROM pos_products GROUP BY UPPER(branch)");
    while ($row = $r->fetch_row()) {
        if (isset($branch_data[$row[0]])) {
            $branch_data[$row[0]]['products']     = (int)$row[1];
            $branch_data[$row[0]]['stock']        = (int)$row[2];
            $branch_data[$row[0]]['low_stock']    = (int)$row[3];
            $branch_data[$row[0]]['out_of_stock'] = (int)$row[4];
        }
    }

    // Catalogue size: distinct SKUs (the same product is one row per branch).
    $r  = $conn->query("SELECT COUNT(DISTINCT sku), COUNT(DISTINCT category), SUM(stock), SUM(price*stock) FROM pos_products");
    [$total_products, $total_cats, $total_stock, $total_value] = $r->fetch_row();
    $r  = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock=0");
    $out_of_stock = (int)$r->fetch_row()[0];
    $r  = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock>0 AND stock<10");
    $low_stock = (int)$r->fetch_row()[0];

    /* Products per branch, for the "View Products" modal */
    $branch_products = [];
    foreach ($branches as $b) $branch_products[$b] = [];
    $r = $conn->query("SELECT id, sku, name, category, stock, branch FROM pos_products ORDER BY UPPER(branch), name");
    while ($row = $r->fetch_assoc()) {
        $bKey = strtoupper($row['branch']);
        if (isset($branch_products[$bKey])) {
            $branch_products[$bKey][] = [
                'id'       => (int)$row['id'],
                'sku'      => $row['sku'],
                'name'     => $row['name'],
                'category' => $row['category'],
                'stock'    => (int)$row['stock'],
            ];
        }
    }

    $conn->close();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Inventory</title>
<link rel="stylesheet" href="../styles/admin.css">
<link rel="stylesheet" href="../styles/inventory.css">
<link rel="stylesheet" href="../styles/branches.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
                <div class="kpi-top"><span class="kpi-label">Products per Branch</span><div class="kpi-icon orange"><i class="fa-solid fa-boxes-stacked"></i></div></div>
                <div class="kpi-value"><?=number_format((int)$total_products)?></div>
                <div class="kpi-meta"><?=number_format((int)$total_cats)?> categories &middot; same catalog at every branch</div>
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

        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Product List</div><div class="chart-subtitle">Grouped by branch — click a branch to view its products</div></div>
            </div>

            <div class="branch-grid">
            <?php foreach($branch_data as $br): ?>
            <div class="branch-card branch-card-link" data-branch="<?=htmlspecialchars($br['name'])?>" role="button" tabindex="0">
                <div class="branch-card-header">
                    <div class="branch-icon"><i class="fa-solid fa-store"></i></div>
                    <div>
                        <div class="branch-name"><?=htmlspecialchars($br['name'])?></div>
                        <div class="branch-loc"><i class="fa-solid fa-location-dot" style="margin-right:4px;"></i>Lucky 8 Branch</div>
                    </div>
                </div>
                <div class="branch-stats">
                    <div class="bstat" style="grid-column:span 2;">
                        <div class="bstat-label">Products</div>
                        <div class="bstat-val"><?=number_format($br['products'])?> Products</div>
                        <div class="bstat-sub"><?=number_format($br['stock'])?> units total</div>
                        <?php if($br['low_stock']>0):?><span class="low-badge"><?=(int)$br['low_stock']?> low stock</span><?php endif;?>
                        <?php if($br['out_of_stock']>0):?><span class="low-badge" style="background:#fee2e2;color:#dc2626;"><?=(int)$br['out_of_stock']?> out of stock</span><?php endif;?>
                    </div>
                </div>
                <div class="branch-card-cta">View Products <i class="fa-solid fa-arrow-right"></i></div>
            </div>
            <?php endforeach;?>
            <?php if(empty($branch_data)):?>
            <div style="grid-column:1/-1;text-align:center;padding:48px;color:#9ca3af;">
                <i class="fa-solid fa-building" style="font-size:32px;margin-bottom:12px;display:block;"></i>
                No branches found. Add staff/products with a branch assignment to see them here.
            </div>
            <?php endif;?>
            </div>
        </div>
    </div>
</div>

<!-- View Products Modal -->
<div class="modal-bg" id="branchProductsModal">
    <div class="modal" style="width:520px;">
        <div class="bpm-header">
            <h3><i class="fa-solid fa-store" style="color:#e8611a;margin-right:8px;"></i><span id="bpmBranchName"></span></h3>
            <span class="bpm-count-badge" id="bpmCount"></span>
        </div>

        <div class="bpm-cat-filter">
            <i class="fa-solid fa-tag bpm-cat-icon"></i>
            <select id="bpmCatSelect" onchange="renderBpmList()">
                <option value="">All Categories</option>
            </select>
            <i class="fa-solid fa-chevron-down bpm-cat-chevron"></i>
        </div>

        <div id="bpmList" class="bpm-list"></div>

        <div class="modal-footer">
            <button type="button" class="btn-ghost" onclick="closeModal('branchProductsModal')">Close</button>
        </div>
    </div>
</div>

<script>
const PRODUCTS_BY_BRANCH = <?=json_encode($branch_products)?>;

function escHtml(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let bpmProducts = [];

function openBranchModal(branchName){
    bpmProducts = PRODUCTS_BY_BRANCH[branchName] || [];
    document.getElementById('bpmBranchName').textContent = branchName;

    const categories = Array.from(new Set(bpmProducts.map(function(p){ return p.category; }).filter(Boolean))).sort();
    const catSelect = document.getElementById('bpmCatSelect');
    catSelect.innerHTML = '<option value="">All Categories</option>'
        + categories.map(function(c){ return '<option value="'+escHtml(c)+'">'+escHtml(c)+'</option>'; }).join('');

    renderBpmList();
    document.getElementById('branchProductsModal').classList.add('open');
}

function renderBpmList(){
    const cat  = document.getElementById('bpmCatSelect').value;
    const list = document.getElementById('bpmList');
    const filtered = cat ? bpmProducts.filter(function(p){ return p.category === cat; }) : bpmProducts;

    document.getElementById('bpmCount').textContent = filtered.length + (filtered.length===1?' product':' products');

    if (!filtered.length) {
        list.innerHTML = '<div style="text-align:center;padding:24px;color:#9ca3af;">No products found.</div>';
        return;
    }

    list.innerHTML = filtered.map(function(p){
        const sc = p.stock===0 ? 'stock-out' : (p.stock<10 ? 'stock-low' : 'stock-ok');
        return '<div class="bpm-row"><div class="bpm-info"><div class="bpm-name">'+escHtml(p.name)+'</div>'
             + '<div class="bpm-sku">'+escHtml(p.sku)+' · '+escHtml(p.category)+'</div></div>'
             + '<div class="bpm-stock '+sc+'">'+p.stock+' in stock</div></div>';
    }).join('');
}

function closeModal(id){
    document.getElementById(id).classList.remove('open');
}

document.querySelectorAll('.branch-card-link[data-branch]').forEach(function(card){
    card.addEventListener('click', function(){ openBranchModal(card.dataset.branch); });
    card.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openBranchModal(card.dataset.branch); }
    });
});

document.querySelectorAll('.modal-bg').forEach(function(m){
    m.addEventListener('click', function(e){ if (e.target === m) m.classList.remove('open'); });
});
</script>
</body>
</html>
    <?php
    exit;
}

/* ══════════════════ BRANCH DETAIL VIEW ══════════════════ */

$r  = $conn->prepare("SELECT COUNT(*), COUNT(DISTINCT category), SUM(stock), SUM(price*stock) FROM pos_products WHERE branch=?");
$r->bind_param('s', $viewBranch);
$r->execute();
[$total_products, $total_cats, $total_stock, $total_value] = $r->get_result()->fetch_row();
$r->close();

$r  = $conn->prepare("SELECT COUNT(*) FROM pos_products WHERE branch=? AND stock=0");
$r->bind_param('s', $viewBranch);
$r->execute();
$out_of_stock = (int)$r->get_result()->fetch_row()[0];
$r->close();

$r  = $conn->prepare("SELECT COUNT(*) FROM pos_products WHERE branch=? AND stock>0 AND stock<10");
$r->bind_param('s', $viewBranch);
$r->execute();
$low_stock = (int)$r->get_result()->fetch_row()[0];
$r->close();

$products = [];
$stmt = $conn->prepare("SELECT * FROM pos_products WHERE branch=? ORDER BY name");
$stmt->bind_param('s', $viewBranch);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $products[] = $row;
$stmt->close();

$categories = [];
$r = $conn->prepare("SELECT DISTINCT category FROM pos_products WHERE branch=? AND category!='' ORDER BY category");
$r->bind_param('s', $viewBranch);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_row()) $categories[] = $row[0];
$r->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Inventory — <?=htmlspecialchars($viewBranch)?></title>
<link rel="stylesheet" href="../styles/admin.css">
<link rel="stylesheet" href="../styles/inventory.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
                <div class="kpi-meta">units at this branch</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Out of Stock</span><div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div></div>
                <div class="kpi-value"><?=(int)$out_of_stock?></div>
                <div class="kpi-meta"><?=(int)$low_stock?> more items low stock</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Inventory Value</span><div class="kpi-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fa-solid fa-peso-sign"></i></div></div>
                <div class="kpi-value">₱<?=number_format((float)$total_value,0)?></div>
                <div class="kpi-meta">estimated value at this branch</div>
            </div>
        </div>

        <!-- Table -->
        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div><div class="chart-title"><?=htmlspecialchars($viewBranch)?></div><div class="chart-subtitle"><?=number_format((int)$total_products)?> Products at this branch</div></div>
                <div style="display:flex;gap:8px;">
                    <button class="btn-orange" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Product</button>
                </div>
            </div>

            <div class="inv-filters">
                <input type="text" id="searchInp" placeholder="Search name or SKU…" oninput="filterTable()">

                <div class="branch-filter" title="Filter by category">
                    <i class="fa-solid fa-tag branch-filter-icon"></i>
                    <button class="branch-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <span class="branch-selected-label">All Categories</span>
                        <i class="fa-solid fa-chevron-down branch-chevron"></i>
                    </button>
                    <div class="branch-dropdown-panel" role="listbox" aria-label="Filter by category">
                        <div class="branch-option branch-option--selected" data-value="" role="option" aria-selected="true">
                            <i class="fa-solid fa-layer-group"></i><span>All Categories</span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php foreach($categories as $c):?>
                        <div class="branch-option" data-value="<?=htmlspecialchars($c)?>" role="option" aria-selected="false">
                            <i class="fa-solid fa-box"></i><span><?=htmlspecialchars($c)?></span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php endforeach;?>
                    </div>
                    <select id="catSel" class="branch-filter-hidden-select" style="display:none" onchange="filterTable()">
                        <option value="">All Categories</option>
                        <?php foreach($categories as $c):?><option><?=htmlspecialchars($c)?></option><?php endforeach;?>
                    </select>
                </div>
            </div>

            <table class="intel-table" id="invTable">
                <thead><tr>
                    <th>SKU</th><th>Product</th><th>Category</th>
                    <th class="col-r">Price</th><th class="col-r">Stock</th><th class="col-r">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($products as $p):
                    $sc = $p['stock']==0?'stock-out':($p['stock']<10?'stock-low':'stock-ok');
                ?>
                <tr data-name="<?=strtolower(htmlspecialchars($p['name']))?>"
                    data-sku="<?=strtolower(htmlspecialchars($p['sku']))?>"
                    data-cat="<?=htmlspecialchars($p['category'])?>">
                    <td class="col-mono"><?=htmlspecialchars($p['sku'])?></td>
                    <td><div class="prod-name"><?=htmlspecialchars($p['name'])?></div></td>
                    <td><?=htmlspecialchars($p['category'])?></td>
                    <td class="col-r">₱<?=number_format((float)$p['price'],2)?></td>
                    <td class="col-r <?=$sc?>"><?=(int)$p['stock']?></td>
                    <td class="col-r" style="white-space:nowrap;">
                        <button class="btn-ghost" onclick='openEditModal(<?=json_encode($p)?>)'>Edit</button>
                        <button class="btn-danger" onclick="confirmDelete(<?=(int)$p['id']?>, '<?=addslashes(htmlspecialchars($p['name']))?>')">Delete</button>
                    </td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($products)):?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:#9ca3af;">No products at this branch yet.</td></tr>
                <?php endif;?>
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
            <input type="hidden" name="branch" value="<?=htmlspecialchars($viewBranch)?>">
            <input type="hidden" name="redirect_branch" value="<?=htmlspecialchars($viewBranch)?>">
            <div class="form-row">
                <div class="form-group"><label>SKU</label><input name="sku" required placeholder="e.g. PVC-001"></div>
                <div class="form-group"><label>Category</label><input name="category" required list="catList" placeholder="e.g. Hoses"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Product Name</label><input name="name" required placeholder="Full product name"></div>
                <div class="form-group"><label>Branch</label><input value="<?=htmlspecialchars($viewBranch)?>" readonly disabled style="background:#f9fafb;color:#6b7280;"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Price (₱)</label><input name="price" type="number" step="0.01" min="0" required placeholder="0.00"></div>
                <div class="form-group"><label>Stock</label><input name="stock" type="number" min="0" required placeholder="0"></div>
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
            <input type="hidden" name="branch" value="<?=htmlspecialchars($viewBranch)?>">
            <input type="hidden" name="redirect_branch" value="<?=htmlspecialchars($viewBranch)?>">
            <div class="form-group"><label>Product Name</label><input name="name" id="editName" required></div>
            <div class="form-row">
                <div class="form-group"><label>Category</label><input name="category" id="editCat" required list="catList2"></div>
                <div class="form-group"><label>Branch</label><input value="<?=htmlspecialchars($viewBranch)?>" readonly disabled style="background:#f9fafb;color:#6b7280;"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Price (₱)</label><input name="price" id="editPrice" type="number" step="0.01" min="0" required></div>
                <div class="form-group"><label>Stock</label><input name="stock" id="editStock" type="number" min="0" required></div>
            </div>
            <datalist id="catList2"><?php foreach($categories as $c):?><option value="<?=htmlspecialchars($c)?>"><?php endforeach;?></datalist>
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
    <input type="hidden" name="redirect_branch" value="<?=htmlspecialchars($viewBranch)?>">
</form>

<script src="../src/branch-filter-widget.js"></script>
<script src="../src/inventory.js"></script>
</body>
</html>
