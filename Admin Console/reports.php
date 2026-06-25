<?php
session_start();
require_once '../Landing Page/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../Landing Page/login.php'); exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── CSV export ── */
$export = trim($_GET['export'] ?? '');
$branch = trim($_GET['branch'] ?? '');
$from   = trim($_GET['from']   ?? date('Y-m-01'));
$to     = trim($_GET['to']     ?? date('Y-m-d'));
$bwhere = $branch ? "AND UPPER(branch)='".strtoupper(addslashes($branch))."'" : '';

if ($export === 'sales') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_report_'.$from.'_'.$to.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Transaction ID','Cashier','Branch','Payment','Subtotal','VAT','Total','Date']);
    $r = $conn->query("SELECT transaction_id,cashier,branch,payment_method,subtotal,vat,total,created_at FROM pos_sales WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC");
    while ($row = $r->fetch_row()) fputcsv($out, $row);
    fclose($out); exit;
}

if ($export === 'inventory') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_report_'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['SKU','Name','Category','Branch','Price','Stock','Value']);
    $r = $conn->query("SELECT sku,name,category,branch,price,stock,ROUND(price*stock,2) FROM pos_products ".($branch?"WHERE UPPER(branch)='".strtoupper(addslashes($branch))."'":'')." ORDER BY branch,name");
    while ($row = $r->fetch_row()) fputcsv($out, $row);
    fclose($out); exit;
}

if ($export === 'audit') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_report_'.$from.'_'.$to.'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Time','User','Branch','Action','Item','Details']);
    $r = $conn->query("SELECT created_at,user_name,branch,action,entity_name,details FROM audit_trail WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC");
    while ($row = $r->fetch_row()) fputcsv($out, $row);
    fclose($out); exit;
}

/* ── Preview data for selected report ── */
$report_type = trim($_GET['type'] ?? 'sales');
$preview = [];
$preview_cols = [];
$preview_total_rev = 0;

if ($report_type === 'sales') {
    $preview_cols = ['Transaction ID','Cashier','Branch','Payment','Total','Date'];
    $r = $conn->query("SELECT transaction_id,cashier,branch,payment_method,CONCAT('₱',FORMAT(total,2)),created_at FROM pos_sales WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC LIMIT 50");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $r2 = $conn->query("SELECT COALESCE(SUM(total),0),COUNT(*) FROM pos_sales WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere");
    [$preview_total_rev,$preview_count] = $r2->fetch_row();
} elseif ($report_type === 'inventory') {
    $preview_cols = ['SKU','Name','Category','Branch','Price','Stock','Value'];
    $r = $conn->query("SELECT sku,name,category,branch,CONCAT('₱',FORMAT(price,2)),stock,CONCAT('₱',FORMAT(price*stock,2)) FROM pos_products ".($branch?"WHERE UPPER(branch)='".strtoupper(addslashes($branch))."'":'')." ORDER BY branch,name LIMIT 50");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $preview_count = count($preview);
} elseif ($report_type === 'audit') {
    $preview_cols = ['Time','User','Branch','Action','Item','Details'];
    $r = $conn->query("SELECT created_at,user_name,branch,action,COALESCE(entity_name,'—'),COALESCE(details,'') FROM audit_trail WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC LIMIT 50");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $preview_count = count($preview);
}

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
<title>Lucky 8 — Reports</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.report-types{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.report-type-card{background:#fff;border-radius:12px;padding:20px;border:2px solid #e5e7eb;cursor:pointer;transition:all 0.2s;text-align:left;}
.report-type-card:hover,.report-type-card.selected{border-color:#e8611a;background:rgba(232,97,26,0.03);}
.report-type-card .rt-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:12px;}
.rt-sales{background:rgba(232,97,26,0.1);color:#e8611a;}
.rt-inv{background:rgba(16,185,129,0.1);color:#10b981;}
.rt-audit{background:rgba(99,102,241,0.1);color:#6366f1;}
.rt-title{font-size:14px;font-weight:700;color:#111827;margin-bottom:4px;}
.rt-desc{font-size:12px;color:#6b7280;}
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar input,.filter-bar select{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.filter-bar input:focus,.filter-bar select:focus{border-color:#e8611a;}
.filter-bar label{font-size:12px;color:#6b7280;font-weight:500;white-space:nowrap;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-orange:hover{background:#c94e0f;}
.btn-green{background:#10b981;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
.btn-green:hover{background:#059669;}
.preview-note{font-size:12px;color:#9ca3af;margin-bottom:12px;}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Reports</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <!-- Report type selector -->
        <div class="report-types">
            <a href="?type=sales&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>" style="text-decoration:none;">
                <div class="report-type-card<?=$report_type==='sales'?' selected':''?>">
                    <div class="rt-icon rt-sales"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="rt-title">Sales Report</div>
                    <div class="rt-desc">Transactions by date range and branch</div>
                </div>
            </a>
            <a href="?type=inventory&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>" style="text-decoration:none;">
                <div class="report-type-card<?=$report_type==='inventory'?' selected':''?>">
                    <div class="rt-icon rt-inv"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div class="rt-title">Inventory Report</div>
                    <div class="rt-desc">Current stock levels and product values</div>
                </div>
            </a>
            <a href="?type=audit&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>" style="text-decoration:none;">
                <div class="report-type-card<?=$report_type==='audit'?' selected':''?>">
                    <div class="rt-icon rt-audit"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="rt-title">Audit Report</div>
                    <div class="rt-desc">Full system activity and change log</div>
                </div>
            </a>
        </div>

        <!-- Filters + actions -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <div class="chart-title"><?=ucfirst($report_type)?> Report</div>
                    <div class="chart-subtitle">Configure filters then export to CSV</div>
                </div>
                <a href="?export=<?=$report_type?>&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>" class="btn-green">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </a>
            </div>

            <form method="GET" class="filter-bar">
                <input type="hidden" name="type" value="<?=$report_type?>">
                <label>Branch</label>
                <select name="branch">
                    <option value="">All Branches</option>
                    <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
                <?php if($report_type!=='inventory'):?>
                <label>From</label><input type="date" name="from" value="<?=htmlspecialchars($from)?>">
                <label>To</label><input type="date" name="to" value="<?=htmlspecialchars($to)?>">
                <?php endif;?>
                <button type="submit" class="btn-orange"><i class="fa-solid fa-eye"></i> Preview</button>
            </form>

            <?php if($report_type==='sales' && !empty($preview_total_rev)):?>
            <div style="display:flex;gap:24px;margin-bottom:16px;padding:14px 16px;background:#f9fafb;border-radius:10px;">
                <div><span style="font-size:11px;color:#9ca3af;font-weight:600;">TOTAL REVENUE</span><div style="font-size:18px;font-weight:800;color:#111827;">₱<?=number_format((float)$preview_total_rev,0)?></div></div>
                <div><span style="font-size:11px;color:#9ca3af;font-weight:600;">TRANSACTIONS</span><div style="font-size:18px;font-weight:800;color:#111827;"><?=number_format((int)($preview_count??0))?></div></div>
                <div><span style="font-size:11px;color:#9ca3af;font-weight:600;">PERIOD</span><div style="font-size:18px;font-weight:800;color:#111827;"><?=$from?> → <?=$to?></div></div>
            </div>
            <?php endif;?>

            <p class="preview-note">Showing up to 50 rows preview. Export CSV to get all records.</p>
            <table class="intel-table">
                <thead><tr><?php foreach($preview_cols as $col):?><th><?=htmlspecialchars($col)?></th><?php endforeach;?></tr></thead>
                <tbody>
                <?php foreach($preview as $row):?>
                <tr><?php foreach($row as $cell):?><td><?=htmlspecialchars($cell??'')?></td><?php endforeach;?></tr>
                <?php endforeach;?>
                <?php if(empty($preview)):?>
                <tr><td colspan="<?=count($preview_cols)?>" style="text-align:center;padding:32px;color:#9ca3af;">No data found for the selected filters.</td></tr>
                <?php endif;?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
