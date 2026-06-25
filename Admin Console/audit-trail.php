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
$branch     = trim($_GET['branch']     ?? '');
$action_f   = trim($_GET['action']     ?? '');
$user_f     = trim($_GET['user']       ?? '');
$date_from  = trim($_GET['from']       ?? date('Y-m-01'));
$date_to    = trim($_GET['to']         ?? date('Y-m-d'));
$page_num   = max(1,(int)($_GET['pg']  ?? 1));
$per_page   = 25;
$offset     = ($page_num - 1) * $per_page;

$where_parts = ["DATE(created_at) BETWEEN '$date_from' AND '$date_to'"];
if ($branch)   $where_parts[] = "UPPER(branch)='".strtoupper(addslashes($branch))."'";
if ($action_f) $where_parts[] = "action LIKE '%".addslashes($action_f)."%'";
if ($user_f)   $where_parts[] = "user_name LIKE '%".addslashes($user_f)."%'";
$where = 'WHERE '.implode(' AND ', $where_parts);

/* ── Count ── */
$r     = $conn->query("SELECT COUNT(*) FROM audit_trail $where");
$total = (int)$r->fetch_row()[0];
$pages = max(1, (int)ceil($total / $per_page));

/* ── Records ── */
$records = [];
$r = $conn->query("SELECT * FROM audit_trail $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
while ($row = $r->fetch_assoc()) $records[] = $row;

/* ── KPIs ── */
$r2 = $conn->query("SELECT COUNT(*) FROM audit_trail WHERE DATE(created_at)=CURDATE()");
$today_count = (int)$r2->fetch_row()[0];

$r2 = $conn->query("SELECT COUNT(DISTINCT user_name) FROM audit_trail WHERE DATE(created_at)=CURDATE()");
$today_users = (int)$r2->fetch_row()[0];

$r2 = $conn->query("SELECT COUNT(*) FROM audit_trail WHERE action LIKE '%DELETE%'");
$delete_count = (int)$r2->fetch_row()[0];

/* ── Dropdown lists ── */
$branches=[];
$r=$conn->query("SELECT DISTINCT UPPER(branch) b FROM audit_trail WHERE branch!='' ORDER BY b");
while($row=$r->fetch_row()) $branches[]=$row[0];

$actions=[];
$r=$conn->query("SELECT DISTINCT action FROM audit_trail ORDER BY action");
while($row=$r->fetch_row()) $actions[]=$row[0];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Audit Trail</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
.filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar input,.filter-bar select{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.filter-bar input:focus,.filter-bar select:focus{border-color:#e8611a;}
.filter-bar label{font-size:12px;color:#6b7280;font-weight:500;white-space:nowrap;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-orange:hover{background:#c94e0f;}
.btn-ghost{background:#fff;color:#6b7280;border:1px solid #e5e7eb;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-ghost:hover{border-color:#e8611a;color:#e8611a;}
.pagination{display:flex;align-items:center;gap:6px;margin-top:16px;justify-content:center;flex-wrap:wrap;}
.pg-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;border:1px solid #e5e7eb;background:#fff;color:#374151;font-size:13px;cursor:pointer;text-decoration:none;font-weight:500;}
.pg-btn:hover{border-color:#e8611a;color:#e8611a;}
.pg-btn.active{background:#e8611a;color:#fff;border-color:#e8611a;}
.pg-btn.disabled{opacity:0.4;pointer-events:none;}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="font-size:15px;font-weight:700;color:#111827;">Audit Trail</div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Records</span><div class="kpi-icon orange"><i class="fa-solid fa-shield-halved"></i></div></div>
                <div class="kpi-value"><?=number_format($total)?></div>
                <div class="kpi-meta">matching current filter</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Activity Today</span><div class="kpi-icon green"><i class="fa-solid fa-clock"></i></div></div>
                <div class="kpi-value"><?=(int)$today_count?></div>
                <div class="kpi-meta"><?=(int)$today_users?> active users today</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Total Deletions</span><div class="kpi-icon red"><i class="fa-solid fa-trash"></i></div></div>
                <div class="kpi-value"><?=(int)$delete_count?></div>
                <div class="kpi-meta">all-time deleted records</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Page</span><div class="kpi-icon" style="background:#f3f4f6;color:#6b7280;"><i class="fa-solid fa-file-lines"></i></div></div>
                <div class="kpi-value"><?=$page_num?> / <?=$pages?></div>
                <div class="kpi-meta"><?=$per_page?> records per page</div>
            </div>
        </div>

        <!-- Filter -->
        <div class="chart-card">
            <form method="GET" class="filter-bar">
                <label>Branch</label>
                <select name="branch">
                    <option value="">All Branches</option>
                    <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                </select>
                <label>Action</label>
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach($actions as $a):?><option<?=$a===$action_f?' selected':''?>><?=htmlspecialchars($a)?></option><?php endforeach;?>
                </select>
                <label>User</label>
                <input name="user" placeholder="Search user…" value="<?=htmlspecialchars($user_f)?>">
                <label>From</label><input type="date" name="from" value="<?=htmlspecialchars($date_from)?>">
                <label>To</label><input type="date" name="to" value="<?=htmlspecialchars($date_to)?>">
                <button type="submit" class="btn-orange"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="audit-trail.php" class="btn-ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </form>

            <table class="intel-table audit-table">
                <thead><tr>
                    <th>Time</th><th>User</th><th>Branch</th>
                    <th>Action</th><th>Item</th><th>Details</th>
                </tr></thead>
                <tbody>
                <?php foreach($records as $r):
                    $ac = strpos($r['action'],'DELETE')!==false?'action-delete':(strpos($r['action'],'ADD')!==false?'action-add':(strpos($r['action'],'EDIT')!==false?'action-edit':'action-sale'));
                ?>
                <tr>
                    <td class="audit-time"><?=htmlspecialchars($r['created_at'])?></td>
                    <td><?=htmlspecialchars($r['user_name'])?></td>
                    <td><?=htmlspecialchars($r['branch'])?></td>
                    <td><span class="action-badge <?=$ac?>"><?=htmlspecialchars($r['action'])?></span></td>
                    <td class="audit-item"><?=htmlspecialchars($r['entity_name']??'—')?></td>
                    <td class="audit-detail"><?=htmlspecialchars($r['details']??'')?></td>
                </tr>
                <?php endforeach;?>
                <?php if(empty($records)):?>
                <tr><td colspan="6" style="text-align:center;padding:32px;color:#9ca3af;">No records found for the selected filters.</td></tr>
                <?php endif;?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if($pages>1):
                $qp=http_build_query(array_filter(['branch'=>$branch,'action'=>$action_f,'user'=>$user_f,'from'=>$date_from,'to'=>$date_to]));
            ?>
            <div class="pagination">
                <a href="?<?=$qp?>&pg=<?=max(1,$page_num-1)?>" class="pg-btn<?=$page_num<=1?' disabled':''?>"><i class="fa-solid fa-chevron-left"></i></a>
                <?php for($pg=max(1,$page_num-2);$pg<=min($pages,$page_num+2);$pg++):?>
                <a href="?<?=$qp?>&pg=<?=$pg?>" class="pg-btn<?=$pg===$page_num?' active':''?>"><?=$pg?></a>
                <?php endfor;?>
                <a href="?<?=$qp?>&pg=<?=min($pages,$page_num+1)?>" class="pg-btn<?=$page_num>=$pages?' disabled':''?>"><i class="fa-solid fa-chevron-right"></i></a>
            </div>
            <?php endif;?>
        </div>
    </div>
</div>
</body>
</html>
