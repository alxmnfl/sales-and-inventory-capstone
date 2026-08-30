<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/php/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Branch list — every branch that appears anywhere (staff roster, product
   catalogue, or sales history) so branches with no products still show. ──
   (branch columns differ in collation between tables, hence the explicit COLLATE.) */
$branches = [];
$r = $conn->query("
    SELECT DISTINCT b FROM (
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci AS b FROM users
            WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_products
            WHERE branch IS NOT NULL AND branch <> ''
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_sales
            WHERE branch IS NOT NULL AND branch <> ''
    ) t
    ORDER BY b
");
while ($row = $r->fetch_row()) $branches[] = $row[0];

/* ── CRUD handling (catalogue-wide: every branch carries one row per SKU) ── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $auditUserId   = (int)($_SESSION['user_id']   ?? 0);
    $auditUserName = $_SESSION['user_name']   ?? 'Admin';
    $auditBranch   = $_SESSION['user_branch'] ?? '';

    if ($action === 'cat_add') {
        $sku   = trim($_POST['sku']      ?? '');
        $name  = trim($_POST['name']     ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock = max(0, (int)($_POST['stock'] ?? 0));   // starting stock for every branch

        $chk = $conn->prepare("SELECT COUNT(*) FROM pos_products WHERE sku=?");
        $chk->bind_param('s', $sku);
        $chk->execute();
        $skuExists = (int)$chk->get_result()->fetch_row()[0];
        $chk->close();

        if ($sku === '' || $name === '' || $cat === '') {
            $flash = 'err:SKU, name and category are required.';
        } elseif ($skuExists) {
            $flash = 'err:A product with SKU "'.$sku.'" already exists.';
        } elseif (empty($branches)) {
            $flash = 'err:No branches exist yet — add a branch first.';
        } else {
            $ins = $conn->prepare("INSERT INTO pos_products (sku,name,category,price,stock,branch,added_by) VALUES (?,?,?,?,?,?,?)");
            $added = 0;
            foreach ($branches as $b) {
                $ins->bind_param('sssdisi', $sku, $name, $cat, $price, $stock, $b, $auditUserId);
                if ($ins->execute()) $added++;
            }
            $ins->close();
            $flash = $added ? 'added' : 'err:'.$conn->error;

            if ($added) {
                $detail = "SKU: $sku | Category: $cat | Price: ₱".number_format($price,2)." | Stock: $stock | Branches: $added";
                $auditStmt = $conn->prepare(
                    "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
                     VALUES (?, ?, ?, 'ADD_PRODUCT', 'product', 0, ?, ?)"
                );
                $auditStmt->bind_param('issss', $auditUserId, $auditUserName, $auditBranch, $name, $detail);
                $auditStmt->execute();
                $auditStmt->close();
            }
        }
    }

    if ($action === 'cat_edit') {
        $sku   = trim($_POST['sku']      ?? '');
        $name  = trim($_POST['name']     ?? '');
        $cat   = trim($_POST['category'] ?? '');
        $price = (float)($_POST['price'] ?? 0);

        if ($sku === '' || $name === '' || $cat === '') {
            $flash = 'err:SKU, name and category are required.';
        } else {
            $stmt = $conn->prepare("UPDATE pos_products SET name=?, category=?, price=? WHERE sku=?");
            $stmt->bind_param('ssds', $name, $cat, $price, $sku);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            $flash = 'edited';

            $detail = "SKU: $sku | Category: $cat | Price: ₱".number_format($price,2)." | Rows updated: $n";
            $auditStmt = $conn->prepare(
                "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
                 VALUES (?, ?, ?, 'EDIT_PRODUCT', 'product', 0, ?, ?)"
            );
            $auditStmt->bind_param('issss', $auditUserId, $auditUserName, $auditBranch, $name, $detail);
            $auditStmt->execute();
            $auditStmt->close();
        }
    }

    if ($action === 'cat_delete') {
        $sku = trim($_POST['sku'] ?? '');

        $nameStmt = $conn->prepare("SELECT name FROM pos_products WHERE sku=? LIMIT 1");
        $nameStmt->bind_param('s', $sku);
        $nameStmt->execute();
        $nameStmt->bind_result($delName);
        $nameStmt->fetch();
        $nameStmt->close();

        $stmt = $conn->prepare("DELETE FROM pos_products WHERE sku=?");
        $stmt->bind_param('s', $sku);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        $flash = 'deleted';

        $detail = "SKU: ".($sku ?: '—')." | Rows removed: $n";
        $auditStmt = $conn->prepare(
            "INSERT INTO audit_trail (user_id, user_name, branch, action, entity_type, entity_id, entity_name, details)
             VALUES (?, ?, ?, 'DELETE_PRODUCT', 'product', 0, ?, ?)"
        );
        $entityName = $delName ?: ($sku ?: 'Product');
        $auditStmt->bind_param('issss', $auditUserId, $auditUserName, $auditBranch, $entityName, $detail);
        $auditStmt->execute();
        $auditStmt->close();
    }

    $backTo = 'inventory.php';
    if ($flash !== '') $backTo .= '?flash='.urlencode($flash);
    header('Location: '.$backTo); exit;
}

/* ══════════════════ INVENTORY PAGE ══════════════════ */

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

/* Catalogue-level totals. The catalogue is ~250 SKUs and every branch carries
   the full list, so COUNT(*) over pos_products (250 x 18 branches = 4,500) is
   not the "total products" figure a user expects — roll up per SKU instead.
   Stock / value stay as true system-wide sums (unchanged by the rollup). */
$r = $conn->query("
    SELECT
        COUNT(*)                                                    AS total_products,
        SUM(CASE WHEN total_stock = 0             THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN total_stock BETWEEN 1 AND 9 THEN 1 ELSE 0 END) AS low_stock,
        SUM(total_stock)                                            AS total_stock,
        SUM(stock_value)                                            AS total_value
    FROM (
        SELECT sku, SUM(stock) AS total_stock, SUM(price * stock) AS stock_value
        FROM pos_products
        GROUP BY sku
    ) c
");
[$total_products, $out_of_stock, $low_stock, $total_stock, $total_value] = $r->fetch_row();
$r  = $conn->query("SELECT COUNT(DISTINCT category) FROM pos_products");
$total_cats = (int)$r->fetch_row()[0];

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

/* ── Catalogue: one row per SKU, aggregated across every branch ── */
$catalogue = [];
$r = $conn->query("
    SELECT sku,
           MAX(name)                                AS name,
           MAX(category)                            AS category,
           MAX(price)                               AS price,
           MIN(price)                               AS min_price,
           MAX(price)                               AS max_price,
           SUM(stock)                               AS total_stock,
           COUNT(*)                                 AS branch_count,
           SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS oos_branches
    FROM pos_products
    GROUP BY sku
    ORDER BY name
");
while ($row = $r->fetch_assoc()) {
    $catalogue[] = [
        'sku'          => $row['sku'],
        'name'         => $row['name'],
        'category'     => $row['category'],
        'price'        => (float)$row['price'],
        'price_varies' => ((float)$row['min_price'] !== (float)$row['max_price']),
        'total_stock'  => (int)$row['total_stock'],
        'branch_count' => (int)$row['branch_count'],
        'oos_branches' => (int)$row['oos_branches'],
    ];
}

/* Category options for the catalogue filter — the four real categories that
   actually appear (legacy values like "Hoses" are left out). */
$CANON_CATS = ['Hydraulic Hose', 'Other Hose', 'Fittings', 'Ferrule'];
$catPresent = [];
foreach ($catalogue as $c) $catPresent[$c['category']] = true;
$catList = array_values(array_filter($CANON_CATS, fn($c) => isset($catPresent[$c])));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Inventory</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260829">
<link rel="stylesheet" href="../styles/inventory.css?v=20260830e">
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

        <?php if (isset($_GET['flash'])):
            $f = $_GET['flash'];
            $isErr = strncmp($f, 'err:', 4) === 0;
            $msg = $isErr ? substr($f, 4)
                 : ($f==='added'  ? 'Product added successfully.'
                 : ($f==='edited' ? 'Product updated.'
                 : ($f==='deleted'? 'Product deleted.' : 'Done.')));
        ?>
        <div class="flash <?= $isErr ? 'err' : 'ok' ?>"><?= htmlspecialchars($msg) ?></div>
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

        <!-- Catalogue: manage products across ALL branches at once -->
        <div class="chart-card" style="margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <div class="chart-title">Product Catalogue</div>
                    <div class="chart-subtitle"><?=number_format(count($catalogue))?> products — changes apply to every branch</div>
                </div>
                <button class="btn-orange" type="button" onclick="openCatAddModal()"><i class="fa-solid fa-plus"></i> Add Product</button>
            </div>

            <div class="inv-filters">
                <input type="text" id="catSearch" placeholder="Search name or SKU…" oninput="catGoto(1)">
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
                        <?php foreach($catList as $c):?>
                        <div class="branch-option" data-value="<?=htmlspecialchars($c)?>" role="option" aria-selected="false">
                            <i class="fa-solid fa-box"></i><span><?=htmlspecialchars($c)?></span><i class="fa-solid fa-check branch-option-check"></i>
                        </div>
                        <?php endforeach;?>
                    </div>
                    <select id="catFilterSel" class="branch-filter-hidden-select" style="display:none" onchange="catGoto(1)">
                        <option value="">All Categories</option>
                        <?php foreach($catList as $c):?><option><?=htmlspecialchars($c)?></option><?php endforeach;?>
                    </select>
                </div>
            </div>

            <div class="cat-table-wrap">
                <table class="intel-table" id="catTable">
                    <thead><tr>
                        <th>SKU</th><th>Product</th><th>Category</th>
                        <th class="col-r">Price</th><th class="col-r">Total Stock</th>
                        <th class="col-r">Branches</th><th class="col-r">Actions</th>
                    </tr></thead>
                    <tbody id="catTableBody"></tbody>
                </table>
            </div>
            <div class="cat-pager-row">
                <span class="cat-page-info" id="catPageInfo"></span>
                <div class="pagination" id="catPagination"></div>
            </div>
        </div>

        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Stock by Branch</div><div class="chart-subtitle">Click a branch to view or fine-tune its stock</div></div>
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

        <div class="branch-filter bpm-cat-filter" title="Filter by category">
            <i class="fa-solid fa-tag branch-filter-icon"></i>
            <button class="branch-select-btn" type="button" id="bpmCatBtn" aria-haspopup="listbox" aria-expanded="false">
                <span class="branch-selected-label" id="bpmCatLabel">All Categories</span>
                <i class="fa-solid fa-chevron-down branch-chevron"></i>
            </button>
            <div class="branch-dropdown-panel" id="bpmCatPanel" role="listbox" aria-label="Filter by category"></div>
        </div>

        <div id="bpmList" class="bpm-list"></div>

        <div class="modal-footer">
            <button type="button" class="btn-ghost" onclick="closeModal('branchProductsModal')">Close</button>
        </div>
    </div>
</div>

<!-- ══ Catalogue-wide Add / Edit / Delete (all branches) ══ -->
<div class="modal-bg" id="catAddModal">
    <div class="modal">
        <h3><i class="fa-solid fa-plus" style="color:#e8611a;margin-right:8px;"></i>Add Product</h3>
        <p class="modal-note">Adds this product to <strong>every branch</strong>, each starting at the stock you set below.</p>
        <form method="POST">
            <input type="hidden" name="action" value="cat_add">
            <div class="form-row">
                <div class="form-group"><label>SKU</label><input name="sku" required placeholder="e.g. P-0251"></div>
                <div class="form-group"><label>Category</label>
                    <select name="category" required>
                        <option value="" disabled selected>Select category…</option>
                        <option>Hydraulic Hose</option>
                        <option>Other Hose</option>
                        <option>Fittings</option>
                        <option>Ferrule</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Product Name</label><input name="name" required placeholder="Full product name"></div>
            <div class="form-row">
                <div class="form-group"><label>Price (₱)</label><input name="price" type="number" step="0.01" min="0" required placeholder="0.00"></div>
                <div class="form-group"><label>Starting Stock / branch</label><input name="stock" type="number" min="0" value="0" required></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('catAddModal')">Cancel</button>
                <button type="submit" class="btn-orange">Add to All Branches</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-bg" id="catEditModal">
    <div class="modal">
        <h3><i class="fa-solid fa-pen" style="color:#e8611a;margin-right:8px;"></i>Edit Product</h3>
        <p class="modal-note">Name, category and price update for <strong>all branches</strong>. Per-branch stock isn't changed here — use “Stock by Branch”.</p>
        <form method="POST">
            <input type="hidden" name="action" value="cat_edit">
            <input type="hidden" name="sku" id="catEditSku">
            <div class="form-group"><label>Product Name</label><input name="name" id="catEditName" required></div>
            <div class="form-row">
                <div class="form-group"><label>Category</label>
                    <select name="category" id="catEditCat" required>
                        <option>Hydraulic Hose</option>
                        <option>Other Hose</option>
                        <option>Fittings</option>
                        <option>Ferrule</option>
                    </select>
                </div>
                <div class="form-group"><label>SKU</label><input id="catEditSkuShow" readonly disabled style="background:#f9fafb;color:#6b7280;"></div>
            </div>
            <div class="form-group"><label>Price (₱)</label><input name="price" id="catEditPrice" type="number" step="0.01" min="0" required></div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost" onclick="closeModal('catEditModal')">Cancel</button>
                <button type="submit" class="btn-orange">Save for All Branches</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="catDeleteForm" style="display:none;">
    <input type="hidden" name="action" value="cat_delete">
    <input type="hidden" name="sku" id="catDeleteSku">
</form>

<script src="../src/branch-filter-widget.js?v=20260829"></script>
<script>
const CATALOGUE = <?=json_encode($catalogue)?>;

const PRODUCTS_BY_BRANCH = <?=json_encode($branch_products)?>;

function escHtml(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// The catalogue only uses these four categories; anything else (e.g. legacy
// "Hoses") is ignored by the filter.
const CANONICAL_CATS = ['Hydraulic Hose', 'Other Hose', 'Fittings', 'Ferrule'];

let bpmProducts = [];
let bpmCat = '';

function openBranchModal(branchName){
    bpmProducts = PRODUCTS_BY_BRANCH[branchName] || [];
    document.getElementById('bpmBranchName').textContent = branchName;

    const categories = CANONICAL_CATS.filter(function(c){
        return bpmProducts.some(function(p){ return p.category === c; });
    });

    bpmCat = '';
    document.getElementById('bpmCatLabel').textContent = 'All Categories';

    const panel = document.getElementById('bpmCatPanel');
    const opts = [{ value: '', label: 'All Categories', icon: 'fa-layer-group' }]
        .concat(categories.map(function(c){ return { value: c, label: c, icon: 'fa-box' }; }));
    panel.innerHTML = opts.map(function(o, i){
        return '<div class="branch-option' + (i === 0 ? ' branch-option--selected' : '') + '" '
             + 'data-value="' + escHtml(o.value) + '" role="option" aria-selected="' + (i === 0) + '">'
             + '<i class="fa-solid ' + o.icon + '"></i><span>' + escHtml(o.label) + '</span>'
             + '<i class="fa-solid fa-check branch-option-check"></i></div>';
    }).join('');

    panel.querySelectorAll('.branch-option').forEach(function(opt){
        opt.addEventListener('click', function(){
            bpmCat = opt.dataset.value;
            document.getElementById('bpmCatLabel').textContent = opt.querySelector('span').textContent;
            panel.querySelectorAll('.branch-option').forEach(function(o){
                o.classList.remove('branch-option--selected');
                o.setAttribute('aria-selected', 'false');
            });
            opt.classList.add('branch-option--selected');
            opt.setAttribute('aria-selected', 'true');
            closeBpmCatPanel();
            renderBpmList();
        });
    });

    renderBpmList();
    document.getElementById('branchProductsModal').classList.add('open');
}

function openBpmCatPanel(){
    document.getElementById('bpmCatPanel').classList.add('open');
    document.querySelector('.bpm-cat-filter .branch-chevron').classList.add('rotated');
    document.getElementById('bpmCatBtn').setAttribute('aria-expanded', 'true');
}
function closeBpmCatPanel(){
    document.getElementById('bpmCatPanel').classList.remove('open');
    document.querySelector('.bpm-cat-filter .branch-chevron').classList.remove('rotated');
    document.getElementById('bpmCatBtn').setAttribute('aria-expanded', 'false');
}

document.getElementById('bpmCatBtn').addEventListener('click', function(e){
    e.stopPropagation();
    document.getElementById('bpmCatPanel').classList.contains('open') ? closeBpmCatPanel() : openBpmCatPanel();
});
document.addEventListener('click', function(e){
    if (!document.querySelector('.bpm-cat-filter').contains(e.target)) closeBpmCatPanel();
});

function renderBpmList(){
    const cat  = bpmCat;
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

/* ══ Catalogue table (all branches) ══ */
const CAT_PER_PAGE = 15;
let catPage = 1;

function catGoto(page){
    catPage = page;
    renderCatTable();
}

function renderCatPager(totalRows, totalPages){
    const pager = document.getElementById('catPagination');
    const info  = document.getElementById('catPageInfo');
    const row   = document.querySelector('.cat-pager-row');

    row.style.display = totalRows ? 'flex' : 'none';
    if (!totalRows){
        pager.innerHTML = '';
        info.textContent = '';
        return;
    }

    const from = (catPage - 1) * CAT_PER_PAGE + 1;
    const to   = Math.min(catPage * CAT_PER_PAGE, totalRows);
    info.textContent = from + '–' + to + ' of ' + totalRows;

    if (totalPages <= 1){ pager.innerHTML = ''; return; }

    let html = '<button type="button" class="pg-btn'+(catPage<=1?' disabled':'')+'" onclick="catGoto('+(catPage-1)+')"><i class="fa-solid fa-chevron-left"></i></button>';
    const start = Math.max(1, catPage - 2);
    const end   = Math.min(totalPages, catPage + 2);
    if (start > 1){
        html += '<button type="button" class="pg-btn" onclick="catGoto(1)">1</button>';
        if (start > 2) html += '<span class="pg-gap">…</span>';
    }
    for (let i = start; i <= end; i++){
        html += '<button type="button" class="pg-btn'+(i===catPage?' active':'')+'" onclick="catGoto('+i+')">'+i+'</button>';
    }
    if (end < totalPages){
        if (end < totalPages - 1) html += '<span class="pg-gap">…</span>';
        html += '<button type="button" class="pg-btn" onclick="catGoto('+totalPages+')">'+totalPages+'</button>';
    }
    html += '<button type="button" class="pg-btn'+(catPage>=totalPages?' disabled':'')+'" onclick="catGoto('+(catPage+1)+')"><i class="fa-solid fa-chevron-right"></i></button>';
    pager.innerHTML = html;
}

function renderCatTable(){
    const q   = (document.getElementById('catSearch').value || '').trim().toLowerCase();
    const cat = document.getElementById('catFilterSel').value;
    const body = document.getElementById('catTableBody');

    const rows = CATALOGUE.filter(function(p){
        const matchQ = !q || p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q);
        const matchC = !cat || p.category === cat;
        return matchQ && matchC;
    });

    if (!rows.length){
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:32px;color:#9ca3af;">No products found.</td></tr>';
        renderCatPager(0, 0);
        return;
    }

    const totalPages = Math.ceil(rows.length / CAT_PER_PAGE);
    if (catPage > totalPages) catPage = totalPages;
    if (catPage < 1) catPage = 1;
    const pageRows = rows.slice((catPage - 1) * CAT_PER_PAGE, catPage * CAT_PER_PAGE);

    body.innerHTML = pageRows.map(function(p){
        const sc = p.total_stock === 0 ? 'stock-out' : (p.total_stock < 10 ? 'stock-low' : 'stock-ok');
        const price = '₱' + Number(p.price).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})
                    + (p.price_varies ? ' <span class="cat-varies" title="Price differs between branches">*</span>' : '');
        const branches = p.branch_count + (p.oos_branches ? ' <span class="cat-oos" title="'+p.oos_branches+' branch(es) out of stock">('+p.oos_branches+' OOS)</span>' : '');
        return '<tr>'
             + '<td class="col-mono">'+escHtml(p.sku)+'</td>'
             + '<td><div class="prod-name">'+escHtml(p.name)+'</div></td>'
             + '<td>'+escHtml(p.category)+'</td>'
             + '<td class="col-r">'+price+'</td>'
             + '<td class="col-r '+sc+'">'+Number(p.total_stock).toLocaleString()+'</td>'
             + '<td class="col-r">'+branches+'</td>'
             + '<td class="col-r" style="white-space:nowrap;">'
             +   '<button type="button" class="btn-ghost" onclick="openCatEditModal(\''+encodeURIComponent(p.sku)+'\')">Edit</button> '
             +   '<button type="button" class="btn-danger" onclick="catDeleteProduct(\''+encodeURIComponent(p.sku)+'\')">Delete</button>'
             + '</td>'
             + '</tr>';
    }).join('');

    renderCatPager(rows.length, totalPages);
}

function catBySku(sku){
    return CATALOGUE.find(function(p){ return p.sku === sku; });
}

function openCatAddModal(){
    const form = document.querySelector('#catAddModal form');
    form.reset();
    document.getElementById('catAddModal').classList.add('open');
}

function openCatEditModal(skuEnc){
    const sku = decodeURIComponent(skuEnc);
    const p = catBySku(sku);
    if (!p) return;

    const catSel = document.getElementById('catEditCat');
    Array.from(catSel.querySelectorAll('option[data-legacy]')).forEach(function(o){ o.remove(); });
    if (p.category && !Array.from(catSel.options).some(function(o){ return o.value === p.category; })){
        catSel.insertAdjacentHTML('afterbegin',
            '<option data-legacy value="'+escHtml(p.category)+'">'+escHtml(p.category)+' (legacy)</option>');
    }
    catSel.value = p.category;

    document.getElementById('catEditSku').value      = p.sku;
    document.getElementById('catEditSkuShow').value  = p.sku;
    document.getElementById('catEditName').value     = p.name;
    document.getElementById('catEditPrice').value    = p.price;
    document.getElementById('catEditModal').classList.add('open');
}

function catDeleteProduct(skuEnc){
    const sku = decodeURIComponent(skuEnc);
    const p = catBySku(sku);
    if (!p) return;
    if (!confirm('Delete "'+p.name+'" (SKU '+sku+') from ALL '+p.branch_count+' branches?\nThis cannot be undone.')) return;
    document.getElementById('catDeleteSku').value = sku;
    document.getElementById('catDeleteForm').submit();
}

renderCatTable();

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
