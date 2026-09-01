<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/php/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

$branch = trim($_GET['branch'] ?? '');

/* ── Velocity: last 7 days vs previous 7 days, for EVERY product in scope
   (not just ones with recent sales — a product LEFT JOINed against zero
   matching sale rows correctly comes back as cur=0/prev=0 instead of
   vanishing from the report). ── */
$velSql = "
    SELECT p.id, p.name AS product_name, p.sku, p.stock, p.branch,
           COALESCE(v.cur, 0)  AS cur,
           COALESCE(v.prev, 0) AS prev
    FROM pos_products p
    LEFT JOIN (
        SELECT si.product_id,
               SUM(CASE WHEN s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN si.quantity ELSE 0 END) AS cur,
               SUM(CASE WHEN s.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                         AND s.created_at <  DATE_SUB(NOW(), INTERVAL 7 DAY) THEN si.quantity ELSE 0 END) AS prev
        FROM pos_sale_items si
        JOIN pos_sales s ON s.id = si.sale_id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY si.product_id
    ) v ON v.product_id = p.id
";
if ($branch !== '') {
    $stmt = $conn->prepare($velSql . " WHERE UPPER(p.branch) = ?");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($velSql);
}

$all_products = [];
while ($row = $result->fetch_assoc()) {
    $cur   = (int)$row['cur'];
    $prev  = (int)$row['prev'];
    $delta = $cur - $prev;
    $row['cur']   = $cur;
    $row['prev']  = $prev;
    $row['delta'] = $delta;
    $row['pct']   = $prev > 0 ? round($delta / $prev * 100, 1) : ($cur > 0 ? 100 : 0);
    $all_products[] = $row;
}

/* ── Summary KPIs (computed across ALL products in scope, not just one page) ── */
$gaining     = 0;
$declining   = 0;
$stable      = 0;
$no_activity = 0;
$movers      = []; // products with recent activity — feeds the table below
foreach ($all_products as $row) {
    if ($row['cur'] === 0 && $row['prev'] === 0) {
        $no_activity++;
        continue;
    }
    if ($row['delta'] > 0)      $gaining++;
    elseif ($row['delta'] < 0)  $declining++;
    else                        $stable++;
    $movers[] = $row;
}
usort($movers, fn($a, $b) => $b['cur'] <=> $a['cur']);

/* ── Paginate the movers table (no more hard LIMIT dropping low-velocity/declining products) ── */
$vel_page     = max(1, (int)($_GET['vpg'] ?? 1));
$vel_per_page = 15;
$vel_total    = count($movers);
$vel_pages    = max(1, (int)ceil($vel_total / $vel_per_page));
$vel_page     = min($vel_page, $vel_pages);
$movers_page  = array_slice($movers, ($vel_page - 1) * $vel_per_page, $vel_per_page);
$vel_qbranch  = $branch !== '' ? 'branch=' . urlencode($branch) . '&' : '';

/* ── Recent audit movements ── */
$audit_items = [];
$bwhere2  = $branch ? "AND UPPER(branch)='".strtoupper(addslashes($branch))."'" : '';
$act_page = max(1, (int)($_GET['pg'] ?? 1));
$act_per_page = 15;

$rc = $conn->query("SELECT COUNT(*) FROM audit_trail WHERE 1 $bwhere2");
$act_total = (int)$rc->fetch_row()[0];
$act_pages = max(1, (int)ceil($act_total / $act_per_page));
$act_page  = min($act_page, $act_pages);
$act_offset = ($act_page - 1) * $act_per_page;

$r = $conn->query("
    SELECT user_name, branch, action, entity_name, details, created_at
    FROM audit_trail
    WHERE 1 $bwhere2
    ORDER BY created_at DESC LIMIT $act_per_page OFFSET $act_offset
");
while ($row = $r->fetch_assoc()) $audit_items[] = $row;

/* ── Branch list ── */
$branches=[];
// Every branch that appears anywhere (staff roster, product catalogue, or sales
// history) so branches with no staff still appear.
$r=$conn->query("
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
while($row=$r->fetch_row()) $branches[]=$row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Movement Intel</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260901b">
<link rel="stylesheet" href="../styles/movement.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Movement Intel</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <!-- KPIs -->
        <div class="kpi-grid kpi-grid-5" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Gaining Velocity</span><div class="kpi-icon green"><i class="fa-solid fa-arrow-trend-up"></i></div></div>
                <div class="kpi-value" style="color:#10b981;"><?=(int)$gaining?></div>
                <div class="kpi-meta">products moving faster</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Declining</span><div class="kpi-icon red"><i class="fa-solid fa-arrow-trend-down"></i></div></div>
                <div class="kpi-value" style="color:#ef4444;"><?=(int)$declining?></div>
                <div class="kpi-meta">products slowing down</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Stable</span><div class="kpi-icon" style="background:#f3f4f6;color:#6b7280;"><i class="fa-solid fa-minus"></i></div></div>
                <div class="kpi-value"><?=(int)$stable?></div>
                <div class="kpi-meta">no significant change</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">No Activity</span><div class="kpi-icon" style="background:#f3f4f6;color:#6b7280;"><i class="fa-solid fa-box-archive"></i></div></div>
                <div class="kpi-value" style="color:#6b7280;"><?=(int)$no_activity?></div>
                <div class="kpi-meta">no sales in 14 days</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Tracked Products</span><div class="kpi-icon orange"><i class="fa-solid fa-boxes-stacked"></i></div></div>
                <div class="kpi-value"><?=(int)$vel_total?></div>
                <div class="kpi-meta">with sales last 14 days</div>
            </div>
        </div>

        <!-- Velocity table -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Stock Velocity — Week-over-Week</div><div class="chart-subtitle">Units sold: last 7 days vs. prior 7 days &middot; <?=number_format($vel_total)?> active products &middot; page <?=$vel_page?>/<?=$vel_pages?></div></div>
                <form method="GET" class="filter-bar" style="margin:0;">
                    <div class="branch-filter" title="Filter by branch">
                        <i class="fa-solid fa-location-dot branch-filter-icon"></i>
                        <button class="branch-select-btn" type="button" aria-haspopup="listbox" aria-expanded="false">
                            <span class="branch-selected-label"><?=$branch?htmlspecialchars($branch):'All Branches'?></span>
                            <i class="fa-solid fa-chevron-down branch-chevron"></i>
                        </button>
                        <div class="branch-dropdown-panel" role="listbox" aria-label="Select branch">
                            <div class="branch-option<?=$branch===''?' branch-option--selected':''?>" data-value="" role="option" aria-selected="<?=$branch===''?'true':'false'?>">
                                <i class="fa-solid fa-globe"></i><span>All Branches</span><i class="fa-solid fa-check branch-option-check"></i>
                            </div>
                            <?php foreach($branches as $b):?>
                            <div class="branch-option<?=$b===$branch?' branch-option--selected':''?>" data-value="<?=htmlspecialchars($b)?>" role="option" aria-selected="<?=$b===$branch?'true':'false'?>">
                                <i class="fa-solid fa-store"></i><span><?=htmlspecialchars($b)?></span><i class="fa-solid fa-check branch-option-check"></i>
                            </div>
                            <?php endforeach;?>
                        </div>
                        <select name="branch" class="branch-filter-hidden-select" style="display:none" onchange="this.form.submit()">
                            <option value="">All Branches</option>
                            <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                        </select>
                    </div>
                </form>
            </div>
            <table class="intel-table">
                <thead><tr>
                    <th>#</th><th>Product</th><th>SKU</th><th>Branch</th>
                    <th class="col-r">Prev 7d</th><th class="col-r">Last 7d</th>
                    <th class="col-r">Change</th><th class="col-r">Stock</th>
                </tr></thead>
                <tbody>
                <?php foreach($movers_page as $i=>$m):
                    $dc = $m['delta']>0?'delta-up':($m['delta']<0?'delta-dn':'delta-flat');
                    $arrow = $m['delta']>0?'↑':($m['delta']<0?'↓':'→');
                ?>
                <tr>
                    <td class="col-rank"><?=$i+1+($vel_page-1)*$vel_per_page?></td>
                    <td><div class="prod-name"><?=htmlspecialchars($m['product_name'])?></div></td>
                    <td class="col-mono"><?=htmlspecialchars($m['sku'])?></td>
                    <td><?=htmlspecialchars(strtoupper($m['branch']??''))?></td>
                    <td class="col-r col-num"><?=(int)$m['prev']?></td>
                    <td class="col-r col-num"><?=(int)$m['cur']?></td>
                    <td class="col-r <?=$dc?>"><?=$arrow?> <?=$m['delta']>0?'+':''?><?=(int)$m['delta']?> (<?=$m['pct']>0?'+':''?><?=$m['pct']?>%)</td>
                    <td class="col-r col-num"><?=(int)($m['stock']??0)?></td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($movers_page)):?>
                <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">No sales data in the last 14 days.</td></tr>
                <?php endif;?>
                </tbody>
            </table>
            <?php if($vel_pages>1):?>
            <div class="pagination">
                <a href="?<?=$vel_qbranch?>pg=<?=$act_page?>&vpg=<?=max(1,$vel_page-1)?>" class="pg-btn<?=$vel_page<=1?' disabled':''?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php for($vp=max(1,$vel_page-2);$vp<=min($vel_pages,$vel_page+2);$vp++):?>
                <a href="?<?=$vel_qbranch?>pg=<?=$act_page?>&vpg=<?=$vp?>" class="pg-btn<?=$vp===$vel_page?' active':''?>"><?=$vp?></a>
                <?php endfor;?>
                <a href="?<?=$vel_qbranch?>pg=<?=$act_page?>&vpg=<?=min($vel_pages,$vel_page+1)?>" class="pg-btn<?=$vel_page>=$vel_pages?' disabled':''?>"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php endif;?>
        </div>

        <!-- Recent audit activity -->
        <div class="chart-card">
            <div class="chart-card-header">
                <div><div class="chart-title">Recent Stock Activity</div><div class="chart-subtitle"><?=number_format($act_total)?> audit trail entries · page <?=$act_page?>/<?=$act_pages?></div></div>
            </div>
            <table class="intel-table audit-table">
                <thead><tr>
                    <th>Time</th><th>User</th><th>Branch</th><th>Action</th><th>Item</th><th>Details</th>
                </tr></thead>
                <tbody>
                <?php foreach($audit_items as $a):
                    $ac = strpos($a['action'],'DELETE')!==false?'action-delete':(strpos($a['action'],'ADD')!==false?'action-add':(strpos($a['action'],'EDIT')!==false?'action-edit':'action-sale'));
                ?>
                <tr>
                    <td class="audit-time"><?=htmlspecialchars($a['created_at'])?></td>
                    <td><?=htmlspecialchars($a['user_name'])?></td>
                    <td><?=htmlspecialchars($a['branch'])?></td>
                    <td><span class="action-badge <?=$ac?>"><?=htmlspecialchars($a['action'])?></span></td>
                    <td class="audit-item"><?=htmlspecialchars($a['entity_name']??'—')?></td>
                    <td class="audit-detail"><?=htmlspecialchars($a['details']??'')?></td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($audit_items)):?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:#9ca3af;">No audit records found.</td></tr>
                <?php endif;?>
                </tbody>
            </table>

            <?php if($act_pages>1):
                $qp=http_build_query(array_filter(['branch'=>$branch,'vpg'=>$vel_page>1?$vel_page:null]));
            ?>
            <div class="pagination">
                <a href="?<?=$qp?>&pg=<?=max(1,$act_page-1)?>" class="pg-btn<?=$act_page<=1?' disabled':''?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php for($pg=max(1,$act_page-2);$pg<=min($act_pages,$act_page+2);$pg++):?>
                <a href="?<?=$qp?>&pg=<?=$pg?>" class="pg-btn<?=$pg===$act_page?' active':''?>"><?=$pg?></a>
                <?php endfor;?>
                <a href="?<?=$qp?>&pg=<?=min($act_pages,$act_page+1)?>" class="pg-btn<?=$act_page>=$act_pages?' disabled':''?>"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php endif;?>
        </div>
    </div>
</div>
<script src="../src/branch-filter-widget.js?v=20260829"></script>
</body>
</html>
