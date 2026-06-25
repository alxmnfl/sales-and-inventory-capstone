<?php
session_start();
require_once '../Landing Page/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Filters ── */
$branch   = trim($_GET['branch']   ?? '');
$date_from= trim($_GET['from']     ?? date('Y-m-01'));
$date_to  = trim($_GET['to']       ?? date('Y-m-d'));

$where_parts = ["DATE(s.created_at) BETWEEN '$date_from' AND '$date_to'"];
$params = [];
if ($branch) { $where_parts[] = "s.branch = ?"; $params[] = $branch; }
$where = 'WHERE '.implode(' AND ', $where_parts);

/* ── KPIs ── */
$r = $conn->query("SELECT COALESCE(SUM(total),0), COUNT(DISTINCT transaction_id) FROM pos_sales WHERE DATE(created_at)=CURDATE()".($branch?" AND branch='".addslashes($branch)."'":''));
[$today_rev, $today_txn] = $r->fetch_row();

$r = $conn->query("SELECT COALESCE(SUM(total),0) FROM pos_sales WHERE WEEK(created_at)=WEEK(NOW()) AND YEAR(created_at)=YEAR(NOW())".($branch?" AND branch='".addslashes($branch)."'":''));
$week_rev = (float)$r->fetch_row()[0];

$r = $conn->query("SELECT COALESCE(SUM(total),0) FROM pos_sales WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())".($branch?" AND branch='".addslashes($branch)."'":''));
$month_rev = (float)$r->fetch_row()[0];

/* ── Daily chart data ── */
$chart_labels = [];
$chart_values = [];
$r = $conn->query("SELECT DATE(created_at) d, SUM(total) t FROM pos_sales WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)".($branch?" AND branch='".addslashes($branch)."'":'')." GROUP BY d ORDER BY d");
while($row=$r->fetch_row()){$chart_labels[]=$row[0];$chart_values[]=(float)$row[1];}

/* ── Transactions ── */
$sales = [];
$sql = "SELECT s.transaction_id, s.cashier, s.branch, s.payment_method, s.total, s.created_at, COUNT(si.id) items
        FROM pos_sales s LEFT JOIN pos_sale_items si ON si.sale_id=s.id
        $where GROUP BY s.id ORDER BY s.created_at DESC LIMIT 200";
$r = $conn->query($sql);
while($row=$r->fetch_assoc()) $sales[]=$row;

/* ── Branch list ── */
$branches=[];
$r=$conn->query("SELECT DISTINCT UPPER(branch) b FROM pos_sales WHERE branch!='' ORDER BY b");
while($row=$r->fetch_row()) $branches[]=$row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Sales</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar input,.filter-bar select{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.filter-bar input:focus,.filter-bar select:focus{border-color:#e8611a;}
.filter-bar label{font-size:12px;color:#6b7280;font-weight:500;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-orange:hover{background:#c94e0f;}
.pay-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.pay-cash{background:#ecfdf5;color:#10b981;}
.pay-card{background:#eff6ff;color:#3b82f6;}
.pay-gcash{background:#fdf4ff;color:#a855f7;}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Sales</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Today's Revenue</span><div class="kpi-icon orange"><i class="fa-solid fa-peso-sign"></i></div></div>
                <div class="kpi-value">₱<?=number_format((float)$today_rev,0)?></div>
                <div class="kpi-meta"><?=(int)$today_txn?> transactions today</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">This Week</span><div class="kpi-icon green"><i class="fa-solid fa-calendar-week"></i></div></div>
                <div class="kpi-value">₱<?=number_format((float)$week_rev,0)?></div>
                <div class="kpi-meta">current week</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">This Month</span><div class="kpi-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fa-solid fa-calendar"></i></div></div>
                <div class="kpi-value">₱<?=number_format((float)$month_rev,0)?></div>
                <div class="kpi-meta"><?=date('F Y')?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Showing</span><div class="kpi-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fa-solid fa-list"></i></div></div>
                <div class="kpi-value"><?=count($sales)?></div>
                <div class="kpi-meta">transactions in range</div>
            </div>
        </div>

        <!-- Chart -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div class="chart-card-header">
                <div><div class="chart-title">Daily Sales — Last 30 Days</div><div class="chart-subtitle"><?=$branch?htmlspecialchars($branch):'All Branches'?></div></div>
            </div>
            <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
        </div>

        <!-- Transactions table -->
        <div class="chart-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div><div class="chart-title">Transactions</div><div class="chart-subtitle">Filtered results</div></div>
            </div>
            <form method="GET" class="filter-bar">
                <label>Branch</label>
                <select name="branch">
                    <option value="">All Branches</option>
                    <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
                <label>From</label><input type="date" name="from" value="<?=htmlspecialchars($date_from)?>">
                <label>To</label><input type="date" name="to" value="<?=htmlspecialchars($date_to)?>">
                <button type="submit" class="btn-orange"><i class="fa-solid fa-filter"></i> Filter</button>
            </form>
            <table class="intel-table">
                <thead><tr>
                    <th>Transaction ID</th><th>Cashier</th><th>Branch</th>
                    <th class="col-r">Items</th><th>Payment</th>
                    <th class="col-r">Total</th><th>Date</th>
                </tr></thead>
                <tbody>
                <?php foreach($sales as $s):
                    $pm=strtoupper($s['payment_method']??'CASH');
                    $pc=$pm==='CASH'?'pay-cash':($pm==='GCASH'?'pay-gcash':'pay-card');
                ?>
                <tr>
                    <td class="col-mono"><?=htmlspecialchars($s['transaction_id'])?></td>
                    <td><?=htmlspecialchars($s['cashier'])?></td>
                    <td><?=htmlspecialchars(strtoupper($s['branch']))?></td>
                    <td class="col-r"><?=(int)$s['items']?></td>
                    <td><span class="pay-badge <?=$pc?>"><?=$pm?></span></td>
                    <td class="col-r col-num">₱<?=number_format((float)$s['total'],2)?></td>
                    <td><?=htmlspecialchars($s['created_at'])?></td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($sales)):?>
                <tr><td colspan="7" style="text-align:center;padding:24px;color:#9ca3af;">No transactions found for the selected period.</td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
Chart.defaults.font.family="'Inter',-apple-system,sans-serif";
Chart.defaults.font.size=11;
Chart.defaults.color='#9ca3af';
var ctx=document.getElementById('salesChart').getContext('2d');
var grad=ctx.createLinearGradient(0,0,0,200);
grad.addColorStop(0,'rgba(232,97,26,0.25)');
grad.addColorStop(1,'rgba(232,97,26,0)');
new Chart(ctx,{
    type:'line',
    data:{
        labels:<?=json_encode($chart_labels)?>,
        datasets:[{data:<?=json_encode($chart_values)?>,borderColor:'#e8611a',borderWidth:2,backgroundColor:grad,fill:true,tension:0.4,pointRadius:0,pointHoverRadius:5,pointHoverBackgroundColor:'#e8611a'}]
    },
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
        plugins:{legend:{display:false},tooltip:{backgroundColor:'#111827',titleColor:'#fff',bodyColor:'#9ca3af',padding:10,cornerRadius:8,
            callbacks:{label:function(c){return' ₱'+Number(c.parsed.y).toLocaleString();}}}},
        scales:{x:{grid:{display:false},border:{display:false},ticks:{maxTicksLimit:8,color:'#9ca3af'}},
                y:{grid:{color:'rgba(0,0,0,0.05)'},border:{display:false},ticks:{color:'#9ca3af',callback:function(v){return'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v);}}}}}
});
</script>
</body>
</html>
