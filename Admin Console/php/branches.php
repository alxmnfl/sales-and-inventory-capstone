<?php
session_start();
require_once '../../Landing Page/php/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Branch data ── */
$branch_data = [];

// Distinct branches from users (authoritative source)
$r = $conn->query("SELECT DISTINCT UPPER(branch) b FROM users WHERE branch IS NOT NULL AND branch != '' AND UPPER(branch) != 'ALL BRANCHES' ORDER BY b");
while ($row = $r->fetch_row()) {
    $branch_data[$row[0]] = ['name'=>$row[0],'products'=>0,'stock'=>0,'low_stock'=>0,'staff'=>0,'revenue'=>0,'revenue_prev'=>0];
}

// Product count + stock per branch
$r = $conn->query("SELECT UPPER(branch), COUNT(*), SUM(stock), SUM(CASE WHEN stock>0 AND stock<10 THEN 1 ELSE 0 END) FROM pos_products GROUP BY UPPER(branch)");
while ($row = $r->fetch_row()) {
    if (isset($branch_data[$row[0]])) {
        $branch_data[$row[0]]['products'] = (int)$row[1];
        $branch_data[$row[0]]['stock']    = (int)$row[2];
        $branch_data[$row[0]]['low_stock']= (int)$row[3];
    }
}

// Staff count per branch (case-insensitive match)
$r = $conn->query("SELECT UPPER(branch), COUNT(*) FROM users WHERE role='branch_staff' GROUP BY UPPER(branch)");
while ($row = $r->fetch_row()) {
    if (isset($branch_data[$row[0]])) $branch_data[$row[0]]['staff'] = (int)$row[1];
}

// MTD Revenue per branch
$r = $conn->query("SELECT UPPER(branch), COALESCE(SUM(total),0) FROM pos_sales WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) GROUP BY UPPER(branch)");
while ($row = $r->fetch_row()) {
    if (isset($branch_data[$row[0]])) $branch_data[$row[0]]['revenue'] = (float)$row[1];
}

// Prev month revenue
$r = $conn->query("SELECT UPPER(branch), COALESCE(SUM(total),0) FROM pos_sales WHERE MONTH(created_at)=MONTH(NOW()-INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(NOW()-INTERVAL 1 MONTH) GROUP BY UPPER(branch)");
while ($row = $r->fetch_row()) {
    if (isset($branch_data[$row[0]])) $branch_data[$row[0]]['revenue_prev'] = (float)$row[1];
}

$total_branches = count($branch_data);
$total_staff    = array_sum(array_column($branch_data,'staff'));
$total_rev      = array_sum(array_column($branch_data,'revenue'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Branches</title>
<link rel="stylesheet" href="../styles/admin.css">
<link rel="stylesheet" href="../styles/branches.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Branches</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Active Branches</span><div class="kpi-icon orange"><i class="fa-solid fa-building"></i></div></div>
                <div class="kpi-value"><?=(int)$total_branches?></div>
                <div class="kpi-meta"><span class="badge-up">ALL ONLINE</span> <?= (int)$total_branches ?> branches</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Staff</span><div class="kpi-icon green"><i class="fa-solid fa-user-tie"></i></div></div>
                <div class="kpi-value"><?=(int)$total_staff?></div>
                <div class="kpi-meta">across all branches</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">MTD Revenue</span><div class="kpi-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fa-solid fa-peso-sign"></i></div></div>
                <div class="kpi-value">₱<?=number_format($total_rev,0)?></div>
                <div class="kpi-meta"><?=date('F Y')?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Avg Revenue/Branch</span><div class="kpi-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fa-solid fa-chart-line"></i></div></div>
                <div class="kpi-value">₱<?=number_format($total_branches>0?$total_rev/$total_branches:0,0)?></div>
                <div class="kpi-meta">per branch this month</div>
            </div>
        </div>

        <!-- Branch cards -->
        <div class="branch-grid">
        <?php foreach($branch_data as $br):
            $pct = $br['revenue_prev']>0 ? round(($br['revenue']-$br['revenue_prev'])/$br['revenue_prev']*100,1) : null;
        ?>
        <div class="branch-card">
            <div class="branch-card-header">
                <div class="branch-icon"><i class="fa-solid fa-store"></i></div>
                <div>
                    <div class="branch-name"><?=htmlspecialchars($br['name'])?></div>
                    <div class="branch-loc"><i class="fa-solid fa-location-dot" style="margin-right:4px;"></i>Lucky 8 Branch</div>
                </div>
            </div>
            <div class="branch-stats">
                <div class="bstat">
                    <div class="bstat-label">Products</div>
                    <div class="bstat-val"><?=number_format($br['products'])?></div>
                    <div class="bstat-sub"><?=number_format($br['stock'])?> units total</div>
                    <?php if($br['low_stock']>0):?><span class="low-badge"><?=(int)$br['low_stock']?> low stock</span><?php endif;?>
                </div>
                <div class="bstat">
                    <div class="bstat-label">Staff</div>
                    <div class="bstat-val"><?=(int)$br['staff']?></div>
                    <div class="bstat-sub">branch staff</div>
                </div>
                <div class="bstat" style="grid-column:span 2;">
                    <div class="bstat-label">MTD Revenue</div>
                    <div class="bstat-val">₱<?=number_format($br['revenue'],0)?></div>
                    <?php if($pct!==null):?>
                    <div class="bstat-sub <?=$pct>=0?'rev-up':'rev-dn'?>">
                        <?=$pct>=0?'↑ +'.$pct.'%':'↓ '.$pct.'%'?> vs last month
                    </div>
                    <?php else:?><div class="bstat-sub">No prior month data</div><?php endif;?>
                </div>
            </div>
        </div>
        <?php endforeach;?>
        <?php if(empty($branch_data)):?>
        <div style="grid-column:1/-1;text-align:center;padding:48px;color:#9ca3af;">
            <i class="fa-solid fa-building" style="font-size:32px;margin-bottom:12px;display:block;"></i>
            No branch data available. Add products with branch assignments to see branches here.
        </div>
        <?php endif;?>
        </div>
    </div>
</div>
</body>
</html>
