<?php
require_once '../../Landing Page/php/auth.php';
require_once '../../Landing Page/php/db.php';
require_once '../../Landing Page/php/delivery_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../../Landing Page/php/login.php'); exit;
}

ensure_delivery_schema($conn);

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));

/* ── Report export ──
   Produces a self-contained, print-ready HTML sheet (A4, letterhead, totals)
   that opens in the browser and auto-fires the print dialog, where the user can
   pick "Save as PDF". No external PDF library required. */
$export = trim($_GET['export'] ?? '');
$branch = trim($_GET['branch'] ?? '');
$from   = trim($_GET['from']   ?? date('Y-m-01'));
$to     = trim($_GET['to']     ?? date('Y-m-d'));
$bwhere = $branch ? "AND UPPER(branch)='".strtoupper(addslashes($branch))."'" : '';

if (in_array($export, ['sales', 'inventory', 'audit', 'deliveries'], true)) {

    $scope  = $branch !== '' ? strtoupper($branch) : 'All Branches';
    $gen_at = date('M j, Y · g:i A');
    $foot   = null;

    if ($export === 'sales') {
        $doc_title = 'Sales Report';
        $meta      = "Period: {$from} to {$to}  •  Branch: {$scope}";
        $cols      = ['Transaction ID', 'Cashier', 'Branch', 'Payment', 'Subtotal', 'VAT', 'Total', 'Date'];
        $rightcol  = [4, 5, 6];
        $rows      = [];
        $sub = $vat = $tot = 0.0;
        $r = $conn->query("SELECT transaction_id,cashier,branch,payment_method,subtotal,vat,total,created_at
                           FROM pos_sales
                           WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere
                           ORDER BY created_at DESC");
        while ($x = $r->fetch_assoc()) {
            $sub += (float)$x['subtotal']; $vat += (float)$x['vat']; $tot += (float)$x['total'];
            $rows[] = [
                $x['transaction_id'], $x['cashier'], strtoupper($x['branch']), strtoupper($x['payment_method'] ?? 'CASH'),
                '₱'.number_format((float)$x['subtotal'], 2), '₱'.number_format((float)$x['vat'], 2),
                '₱'.number_format((float)$x['total'], 2), $x['created_at'],
            ];
        }
        $foot = ['TOTAL — '.count($rows).' transactions', '', '', '',
                 '₱'.number_format($sub, 2), '₱'.number_format($vat, 2), '₱'.number_format($tot, 2), ''];

    } elseif ($export === 'inventory') {
        $doc_title = 'Inventory Report';
        $meta      = "Branch: {$scope}  •  As of ".date('M j, Y');
        $cols      = ['SKU', 'Name', 'Category', 'Branch', 'Price', 'Stock', 'Value'];
        $rightcol  = [4, 5, 6];
        $rows      = [];
        $stk = 0; $val = 0.0;
        $iw = $branch ? "WHERE UPPER(branch)='".strtoupper(addslashes($branch))."'" : '';
        $r = $conn->query("SELECT sku,name,category,branch,price,stock,ROUND(price*stock,2) v
                           FROM pos_products $iw ORDER BY branch,name");
        while ($x = $r->fetch_assoc()) {
            $stk += (int)$x['stock']; $val += (float)$x['v'];
            $rows[] = [
                $x['sku'], $x['name'], $x['category'], strtoupper($x['branch']),
                '₱'.number_format((float)$x['price'], 2), (string)(int)$x['stock'], '₱'.number_format((float)$x['v'], 2),
            ];
        }
        $foot = ['TOTAL — '.count($rows).' items', '', '', '', '', (string)$stk, '₱'.number_format($val, 2)];

    } elseif ($export === 'deliveries') {
        $doc_title = 'Delivery Report';
        $meta      = "Period: {$from} to {$to}  •  Branch: {$scope}";
        $cols      = ['Reference', 'Branch', 'Status', 'Lines', 'Units', 'Sent', 'Received'];
        $rightcol  = [3, 4];
        $rows      = [];
        $ln = 0; $un = 0;
        $r = $conn->query("SELECT d.reference, d.branch, d.status,
                                  (SELECT COUNT(*) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) lc,
                                  (SELECT COALESCE(SUM(qty_sent),0) FROM inventory_delivery_items i WHERE i.delivery_id = d.id) uc,
                                  d.created_at, d.received_at
                           FROM inventory_deliveries d
                           WHERE DATE(d.created_at) BETWEEN '$from' AND '$to' $bwhere
                           ORDER BY d.created_at DESC");
        while ($x = $r->fetch_assoc()) {
            $ln += (int)$x['lc']; $un += (int)$x['uc'];
            $rows[] = [
                $x['reference'], strtoupper($x['branch']), strtoupper($x['status']),
                (string)(int)$x['lc'], (string)(int)$x['uc'],
                $x['created_at'], $x['received_at'] ?? '—',
            ];
        }
        $foot = ['TOTAL — '.count($rows).' deliveries', '', '', (string)$ln, (string)$un, '', ''];

    } else { // audit
        $doc_title = 'Audit Trail Report';
        $meta      = "Period: {$from} to {$to}  •  Branch: {$scope}";
        $cols      = ['Time', 'User', 'Branch', 'Action', 'Item', 'Details'];
        $rightcol  = [];
        $rows      = [];
        $r = $conn->query("SELECT created_at,user_name,branch,action,entity_name,details
                           FROM audit_trail
                           WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere
                           ORDER BY created_at DESC");
        while ($x = $r->fetch_assoc()) {
            $rows[] = [
                $x['created_at'], $x['user_name'], strtoupper($x['branch']),
                $x['action'], $x['entity_name'] ?? '—', $x['details'] ?? '',
            ];
        }
    }
    $conn->close();

    $R = array_flip($rightcol);   // quick "is this column right-aligned?" lookup

    // Paginate into fixed-size pages so rows never overlap a page boundary and
    // every sheet carries its own header, totals (last page) and "Page X of Y".
    $rows_per_page = ($export === 'audit') ? 16 : 30;
    $pages_out     = $rows ? array_chunk($rows, $rows_per_page) : [[]];
    $total_pages   = count($pages_out);
    $row_total     = count($rows);

    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($doc_title) ?> — Lucky 8</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f3f4f6; color: #1f2937;
           font-family: 'Segoe UI', 'Inter', Arial, sans-serif; }
    .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 10px; align-items: center;
               background: #111827; color: #fff; padding: 10px 16px; }
    .toolbar button { font: inherit; padding: 7px 14px; border: 0; border-radius: 6px; cursor: pointer;
                      background: #e8611a; color: #fff; font-weight: 600; }
    .toolbar button.ghost { background: #374151; }
    .toolbar .hint { margin-left: auto; font-size: 12px; color: #9ca3af; }

    .page { background: #fff; width: 210mm; min-height: 297mm; margin: 18px auto; padding: 16mm;
            box-shadow: 0 2px 14px rgba(0,0,0,.12); display: flex; flex-direction: column; }
    .letterhead { display: flex; justify-content: space-between; align-items: flex-start;
                  border-bottom: 2px solid #e8611a; padding-bottom: 12px; margin-bottom: 16px; }
    .brand { display: flex; gap: 10px; align-items: center; }
    .brand .badge { background: #e8611a; color: #fff; font-weight: 800; font-size: 14px;
                    padding: 8px 10px; border-radius: 8px; }
    .brand b { display: block; font-size: 15px; letter-spacing: 1px; }
    .brand small { color: #6b7280; font-size: 10px; letter-spacing: 2px; }
    .docmeta { text-align: right; }
    .docmeta h1 { margin: 0 0 5px; font-size: 20px; }
    .docmeta p { margin: 2px 0; font-size: 11px; color: #4b5563; }
    .docmeta .gen { color: #9ca3af; }

    table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top;
             overflow-wrap: anywhere; word-break: break-word; }
    th { background: #f9fafb; text-transform: uppercase; font-size: 9px; letter-spacing: .5px;
         color: #6b7280; border-bottom: 1.5px solid #d1d5db; }
    td.r, th.r { text-align: right; white-space: nowrap; }
    tfoot td { font-weight: 700; background: #f9fafb; border-top: 2px solid #d1d5db; }
    .empty { text-align: center; color: #9ca3af; padding: 26px; }

    .pagefoot { margin-top: auto; padding-top: 10px; border-top: 1px solid #e5e7eb;
                display: flex; justify-content: space-between; font-size: 9px; color: #9ca3af; }

    @media print {
        body { background: #fff; }
        .no-print { display: none !important; }
        .page { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none;
                page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        tr { page-break-inside: avoid; }
        @page { size: A4; margin: 14mm; }
    }
</style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <button class="ghost" onclick="window.close()">Close</button>
    <span class="hint">In the print dialog, choose “Save as PDF” as the destination.</span>
</div>

<?php foreach ($pages_out as $pi => $page_rows): $pageno = $pi + 1; ?>
<div class="page">
    <div class="letterhead">
        <div class="brand">
            <span class="badge">L8</span>
            <div><b>LUCKY 8</b><small>ADMIN CONSOLE</small></div>
        </div>
        <div class="docmeta">
            <h1><?= htmlspecialchars($doc_title) ?></h1>
            <p><?= htmlspecialchars($meta) ?></p>
            <p class="gen">Generated <?= htmlspecialchars($gen_at) ?> by <?= htmlspecialchars($user_name) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr><?php foreach ($cols as $i => $c): ?><th class="<?= isset($R[$i]) ? 'r' : '' ?>"><?= htmlspecialchars($c) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($page_rows as $row): ?>
            <tr><?php foreach ($row as $i => $cell): ?><td class="<?= isset($R[$i]) ? 'r' : '' ?>"><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$row_total): ?>
            <tr><td class="empty" colspan="<?= count($cols) ?>">No records for the selected filters.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($foot && $row_total && $pageno === $total_pages): ?>
        <tfoot>
            <tr><?php foreach ($foot as $i => $cell): ?><td class="<?= isset($R[$i]) ? 'r' : '' ?>"><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <div class="pagefoot">
        <span>Lucky 8 Sales &amp; Inventory System · <?= $row_total ?> record(s) · Confidential</span>
        <span>Page <?= $pageno ?> of <?= $total_pages ?></span>
    </div>
</div>
<?php endforeach; ?>

<script>
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
</script>
</body>
</html>
    <?php
    exit;
}

/* ── Preview data for selected report ── */
$report_type = trim($_GET['type'] ?? 'sales');
$preview = [];
$preview_cols = [];
$preview_total_rev = 0;
$preview_total = 0;
$page_num = max(1, (int)($_GET['pg'] ?? 1));
$per_page = 15;
$offset   = ($page_num - 1) * $per_page;

if ($report_type === 'sales') {
    $preview_cols = ['Transaction ID','Cashier','Branch','Payment','Total','Date'];
    $r2 = $conn->query("SELECT COALESCE(SUM(total),0),COUNT(*) FROM pos_sales WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere");
    [$preview_total_rev,$preview_count] = $r2->fetch_row();
    $preview_total = (int)$preview_count;
    $pages    = max(1, (int)ceil($preview_total / $per_page));
    $page_num = min($page_num, $pages);
    $offset   = ($page_num - 1) * $per_page;
    $r = $conn->query("SELECT transaction_id,cashier,branch,payment_method,CONCAT('₱',FORMAT(total,2)),created_at FROM pos_sales WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
    while ($row = $r->fetch_row()) $preview[] = $row;
} elseif ($report_type === 'inventory') {
    $preview_cols = ['SKU','Name','Category','Branch','Price','Stock','Value'];
    $inv_where = $branch ? "WHERE UPPER(branch)='".strtoupper(addslashes($branch))."'" : '';
    $rc = $conn->query("SELECT COUNT(*) FROM pos_products $inv_where");
    $preview_total = (int)$rc->fetch_row()[0];
    $pages    = max(1, (int)ceil($preview_total / $per_page));
    $page_num = min($page_num, $pages);
    $offset   = ($page_num - 1) * $per_page;
    $r = $conn->query("SELECT sku,name,category,branch,CONCAT('₱',FORMAT(price,2)),stock,CONCAT('₱',FORMAT(price*stock,2)) FROM pos_products $inv_where ORDER BY branch,name LIMIT $per_page OFFSET $offset");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $preview_count = $preview_total;
} elseif ($report_type === 'audit') {
    $preview_cols = ['Time','User','Branch','Action','Item','Details'];
    $rc = $conn->query("SELECT COUNT(*) FROM audit_trail WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere");
    $preview_total = (int)$rc->fetch_row()[0];
    $pages    = max(1, (int)ceil($preview_total / $per_page));
    $page_num = min($page_num, $pages);
    $offset   = ($page_num - 1) * $per_page;
    $r = $conn->query("SELECT created_at,user_name,branch,action,COALESCE(entity_name,'—'),COALESCE(details,'') FROM audit_trail WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $preview_count = $preview_total;
} elseif ($report_type === 'deliveries') {
    $preview_cols = ['Reference','Branch','Status','Lines','Units','Sent','Received'];
    $rc = $conn->query("SELECT COUNT(*) FROM inventory_deliveries WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere");
    $preview_total = (int)$rc->fetch_row()[0];
    $dlv_received  = (int)$conn->query("SELECT COUNT(*) FROM inventory_deliveries WHERE DATE(created_at) BETWEEN '$from' AND '$to' $bwhere AND status='received'")->fetch_row()[0];
    $pages    = max(1, (int)ceil($preview_total / $per_page));
    $page_num = min($page_num, $pages);
    $offset   = ($page_num - 1) * $per_page;
    $r = $conn->query("SELECT d.reference, UPPER(d.branch), UPPER(d.status),
                              (SELECT COUNT(*) FROM inventory_delivery_items i WHERE i.delivery_id = d.id),
                              (SELECT COALESCE(SUM(qty_sent),0) FROM inventory_delivery_items i WHERE i.delivery_id = d.id),
                              d.created_at, COALESCE(d.received_at,'—')
                       FROM inventory_deliveries d
                       WHERE DATE(d.created_at) BETWEEN '$from' AND '$to' $bwhere
                       ORDER BY d.created_at DESC LIMIT $per_page OFFSET $offset");
    while ($row = $r->fetch_row()) $preview[] = $row;
    $preview_count = $preview_total;
} else {
    $pages = 1;
}

/* Column presentation for the preview table: which columns are numeric
   (right-aligned) and which leading column renders in a mono font. */
$col_align = [
    'sales'      => ['r' => [4],       'mono' => 0],
    'inventory'  => ['r' => [4, 5, 6], 'mono' => 0],
    'audit'      => ['r' => [],        'mono' => 0],
    'deliveries' => ['r' => [3, 4],    'mono' => 0],
];
$ca    = $col_align[$report_type] ?? ['r' => [], 'mono' => -1];
$rcols = array_flip($ca['r']);
$mono  = $ca['mono'];

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
<title>Lucky 8 — Reports</title>
<link rel="icon" type="image/jpeg" href="../../Images/background.jpg">
<link rel="stylesheet" href="../styles/admin.css?v=20260829">
<link rel="stylesheet" href="../styles/reports.css?v=20260830b">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
            <a href="?type=sales&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>">
                <div class="report-type-card<?=$report_type==='sales'?' selected':''?>">
                    <div class="rt-icon rt-sales"><i class="fa-solid fa-chart-line"></i></div>
                    <div class="rt-body">
                        <div class="rt-title">Sales Report</div>
                        <div class="rt-desc">Transactions by date range and branch</div>
                    </div>
                    <i class="fa-solid fa-check rt-check"></i>
                </div>
            </a>
            <a href="?type=inventory&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>">
                <div class="report-type-card<?=$report_type==='inventory'?' selected':''?>">
                    <div class="rt-icon rt-inv"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div class="rt-body">
                        <div class="rt-title">Inventory Report</div>
                        <div class="rt-desc">Current stock levels and product values</div>
                    </div>
                    <i class="fa-solid fa-check rt-check"></i>
                </div>
            </a>
            <a href="?type=audit&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>">
                <div class="report-type-card<?=$report_type==='audit'?' selected':''?>">
                    <div class="rt-icon rt-audit"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="rt-body">
                        <div class="rt-title">Audit Report</div>
                        <div class="rt-desc">Full system activity and change log</div>
                    </div>
                    <i class="fa-solid fa-check rt-check"></i>
                </div>
            </a>
            <a href="?type=deliveries&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>">
                <div class="report-type-card<?=$report_type==='deliveries'?' selected':''?>">
                    <div class="rt-icon rt-dlv"><i class="fa-solid fa-truck-fast"></i></div>
                    <div class="rt-body">
                        <div class="rt-title">Delivery Report</div>
                        <div class="rt-desc">Stock deliveries sent to branches and their status</div>
                    </div>
                    <i class="fa-solid fa-check rt-check"></i>
                </div>
            </a>
        </div>

        <!-- Filters + actions -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <div class="chart-title"><?=ucfirst($report_type)?> Report</div>
                    <div class="chart-subtitle">Configure filters, then open a print-ready PDF</div>
                </div>
                <a href="?export=<?=$report_type?>&from=<?=$from?>&to=<?=$to?>&branch=<?=urlencode($branch)?>" class="btn-green" target="_blank" rel="noopener">
                    <i class="fa-solid fa-file-pdf"></i> Print / Save as PDF
                </a>
            </div>

            <form method="GET" class="filter-bar">
                <input type="hidden" name="type" value="<?=$report_type?>">
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
                    <select name="branch" class="branch-filter-hidden-select" style="display:none">
                        <option value="">All Branches</option>
                        <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
                    </select>
                </div>
                <?php if($report_type!=='inventory'):?>
                <label>From</label><input type="date" name="from" value="<?=htmlspecialchars($from)?>">
                <label>To</label><input type="date" name="to" value="<?=htmlspecialchars($to)?>">
                <?php endif;?>
                <button type="submit" class="btn-orange"><i class="fa-solid fa-eye"></i> Preview</button>
            </form>

            <?php if($report_type==='sales' && !empty($preview_total_rev)):?>
            <div class="report-summary">
                <div class="rs-item"><div class="rs-label">Total Revenue</div><div class="rs-value">₱<?=number_format((float)$preview_total_rev,0)?></div></div>
                <div class="rs-item"><div class="rs-label">Transactions</div><div class="rs-value"><?=number_format((int)($preview_count??0))?></div></div>
                <div class="rs-item"><div class="rs-label">Period</div><div class="rs-value small"><?=htmlspecialchars($from)?> &rarr; <?=htmlspecialchars($to)?></div></div>
            </div>
            <?php endif;?>

            <?php if($report_type==='deliveries' && $preview_total):?>
            <div class="report-summary">
                <div class="rs-item"><div class="rs-label">Deliveries</div><div class="rs-value"><?=number_format((int)$preview_total)?></div></div>
                <div class="rs-item"><div class="rs-label">Received</div><div class="rs-value"><?=number_format((int)($dlv_received??0))?></div></div>
                <div class="rs-item"><div class="rs-label">Period</div><div class="rs-value small"><?=htmlspecialchars($from)?> &rarr; <?=htmlspecialchars($to)?></div></div>
            </div>
            <?php endif;?>

            <p class="preview-note">Showing <?=number_format(count($preview))?> of <?=number_format($preview_total)?> rows &middot; page <?=$page_num?>/<?=$pages?> &middot; the PDF includes all records.</p>
            <div class="report-table-wrap">
            <table class="intel-table">
                <thead><tr><?php foreach($preview_cols as $ci=>$col):?><th class="<?=isset($rcols[$ci])?'r':''?>"><?=htmlspecialchars($col)?></th><?php endforeach;?></tr></thead>
                <tbody>
                <?php foreach($preview as $row):?>
                <tr><?php foreach(array_values($row) as $ci=>$cell):?><td class="<?=isset($rcols[$ci])?'r':''?><?=$ci===$mono?' mono':''?>"><?=htmlspecialchars($cell??'')?></td><?php endforeach;?></tr>
                <?php endforeach;?>
                <?php if(empty($preview)):?>
                <tr><td colspan="<?=count($preview_cols)?>" style="text-align:center;padding:36px;color:#9ca3af;">No data found for the selected filters.</td></tr>
                <?php endif;?>
                </tbody>
            </table>
            </div>

            <?php if($pages>1):
                $qp=http_build_query(array_filter(['type'=>$report_type,'branch'=>$branch,'from'=>$from,'to'=>$to]));
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
<script src="../src/branch-filter-widget.js?v=20260829"></script>
</body>
</html>
