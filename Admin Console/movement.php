<?php
session_start();
require_once '../Landing Page/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

$branch = trim($_GET['branch'] ?? '');
$bwhere = $branch ? "AND s.branch='".addslashes($branch)."'" : '';

/* ── Velocity: last 7 days vs previous 7 days per product ── */
$movers = [];
$r = $conn->query("
    SELECT si.product_id, si.product_name, si.sku,
           SUM(CASE WHEN s.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) THEN si.quantity ELSE 0 END) AS cur,
           SUM(CASE WHEN s.created_at >= DATE_SUB(NOW(),INTERVAL 14 DAY)
                     AND s.created_at <  DATE_SUB(NOW(),INTERVAL 7 DAY) THEN si.quantity ELSE 0 END) AS prev,
           p.stock, p.branch
    FROM pos_sale_items si
    JOIN pos_sales s ON si.sale_id = s.id
    LEFT JOIN pos_products p ON p.id = si.product_id
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) $bwhere
    GROUP BY si.product_id, si.product_name, si.sku, p.stock, p.branch
    HAVING (cur + prev) > 0
    ORDER BY cur DESC
    LIMIT 100
");
while ($row = $r->fetch_assoc()) {
    $cur  = (int)$row['cur'];
    $prev = (int)$row['prev'];
    $delta = $cur - $prev;
    $pct   = $prev > 0 ? round($delta / $prev * 100, 1) : ($cur > 0 ? 100 : 0);
    $row['cur']=$cur; $row['prev']=$prev; $row['delta']=$delta; $row['pct']=$pct;
    $movers[] = $row;
}

/* ── Summary KPIs ── */
$gaining  = count(array_filter($movers, fn($m)=>$m['delta']>0));
$declining = count(array_filter($movers, fn($m)=>$m['delta']<0));
$stable   = count($movers) - $gaining - $declining;

/* ── Recent audit movements ── */
$audit_items = [];
$bwhere2 = $branch ? "AND branch='".addslashes($branch)."'" : '';
$r = $conn->query("
    SELECT user_name, branch, action, entity_name, details, created_at
    FROM audit_trail
    WHERE 1 $bwhere2
    ORDER BY created_at DESC LIMIT 30
");
while ($row = $r->fetch_assoc()) $audit_items[] = $row;

/* ── Branch list ── */
$branches=[];
$r=$conn->query("SELECT DISTINCT UPPER(branch) b FROM pos_products WHERE branch!='' ORDER BY b");
while($row=$r->fetch_row()) $branches[]=$row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Movement Intel</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.delta-up{color:#10b981;font-weight:700;}
.delta-dn{color:#ef4444;font-weight:700;}
.delta-flat{color:#9ca3af;font-weight:500;}
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar select{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.filter-bar select:focus{border-color:#e8611a;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-orange:hover{background:#c94e0f;}
</style>
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
        <div class="kpi-grid" style="margin-bottom:20px;">
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
                <div class="kpi-top"><span class="kpi-label">Tracked Products</span><div class="kpi-icon orange"><i class="fa-solid fa-boxes-stacked"></i></div></div>
                <div class="kpi-value"><?=count($movers)?></div>
                <div class="kpi-meta">with sales last 14 days</div>
            </div>
        </div>

        <!-- Velocity table -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Stock Velocity — Week-over-Week</div><div class="chart-subtitle">Units sold: last 7 days vs. prior 7 days</div></div>
                <form method="GET" class="filter-bar" style="margin:0;">
                    <select name="branch" onchange="this.form.submit()">
                        <option value="">All Branches</option>
                        <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                    </select>
                </form>
            </div>
            <table class="intel-table">
                <thead><tr>
                    <th>#</th><th>Product</th><th>SKU</th><th>Branch</th>
                    <th class="col-r">Prev 7d</th><th class="col-r">Last 7d</th>
                    <th class="col-r">Change</th><th class="col-r">Stock</th>
                </tr></thead>
                <tbody>
                <?php foreach($movers as $i=>$m):
                    $dc = $m['delta']>0?'delta-up':($m['delta']<0?'delta-dn':'delta-flat');
                    $arrow = $m['delta']>0?'↑':($m['delta']<0?'↓':'→');
                ?>
                <tr>
                    <td class="col-rank"><?=$i+1?></td>
                    <td><div class="prod-name"><?=htmlspecialchars($m['product_name'])?></div></td>
                    <td class="col-mono"><?=htmlspecialchars($m['sku'])?></td>
                    <td><?=htmlspecialchars(strtoupper($m['branch']??''))?></td>
                    <td class="col-r col-num"><?=(int)$m['prev']?></td>
                    <td class="col-r col-num"><?=(int)$m['cur']?></td>
                    <td class="col-r <?=$dc?>"><?=$arrow?> <?=$m['delta']>0?'+':''?><?=(int)$m['delta']?> (<?=$m['pct']>0?'+':''?><?=$m['pct']?>%)</td>
                    <td class="col-r col-num"><?=(int)($m['stock']??0)?></td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($movers)):?>
                <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af;">No sales data in the last 14 days.</td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>

        <!-- Recent audit activity -->
        <div class="chart-card">
            <div class="chart-card-header">
                <div><div class="chart-title">Recent Stock Activity</div><div class="chart-subtitle">Latest 30 audit trail entries</div></div>
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
        </div>
    </div>
</div>
</body>
</html>
