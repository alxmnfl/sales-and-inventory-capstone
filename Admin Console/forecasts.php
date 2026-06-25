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
$days   = max(7, min(60, (int)($_GET['days'] ?? 14)));

/* ── Branch list ── */
$branches = [];
$r = $conn->query("SELECT DISTINCT UPPER(branch) b FROM pos_products WHERE branch!='' ORDER BY b");
while ($row = $r->fetch_row()) $branches[] = $row[0];

$conn->close();

/* ── Try to reach Python API ── */
$api_url  = 'http://127.0.0.1:5001';
$api_ok   = false;
$forecast = null;
$products = null;

$ctx = stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
$status_raw = @file_get_contents("$api_url/api/status", false, $ctx);
if ($status_raw !== false) {
    $s = json_decode($status_raw, true);
    if ($s && ($s['ok'] ?? false)) $api_ok = true;
}

if ($api_ok) {
    $q = http_build_query(['branch'=>$branch,'days'=>$days]);

    $raw = @file_get_contents("$api_url/api/forecast?$q", false, $ctx);
    if ($raw !== false) $forecast = json_decode($raw, true);

    $raw2 = @file_get_contents("$api_url/api/product_forecast?$q", false, $ctx);
    if ($raw2 !== false) $products = json_decode($raw2, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Forecasts</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.api-offline{background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:24px 28px;margin-bottom:20px;display:flex;gap:16px;align-items:flex-start;}
.api-offline-icon{font-size:24px;color:#d97706;flex-shrink:0;margin-top:2px;}
.api-offline h4{font-size:14px;font-weight:700;color:#92400e;margin-bottom:6px;}
.api-offline p{font-size:13px;color:#78350f;line-height:1.6;margin:0;}
.code-block{background:#111827;color:#a3e635;border-radius:8px;padding:12px 16px;font-family:monospace;font-size:12px;margin-top:10px;line-height:1.8;}
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.filter-bar select,.filter-bar input{padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;outline:none;}
.filter-bar select:focus,.filter-bar input:focus{border-color:#e8611a;}
.btn-orange{background:#e8611a;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.btn-orange:hover{background:#c94e0f;}
.risk-critical{color:#ef4444;font-weight:700;}
.risk-high{color:#f59e0b;font-weight:700;}
.risk-medium{color:#6366f1;font-weight:600;}
.risk-low{color:#10b981;font-weight:600;}
.trend-up{color:#10b981;}
.trend-dn{color:#ef4444;}
.py-badge{display:inline-flex;align-items:center;gap:6px;background:#1e3a5f;color:#60a5fa;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.04em;}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main" id="mainContent">
    <header class="topbar">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="font-size:15px;font-weight:700;color:#111827;">Demand Forecasts</div>
            <span class="py-badge"><i class="fa-brands fa-python"></i>Python Powered</span>
        </div>
        <div class="topbar-right">
            <div class="icon-btn"><i class="fa-regular fa-bell"></i><span class="notif-dot"></span></div>
            <div class="user-chip"><?=htmlspecialchars($initials)?></div>
        </div>
    </header>

    <div class="page-content">

        <?php if (!$api_ok): ?>
        <div class="api-offline">
            <div class="api-offline-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <h4>Python Forecast Server Not Running</h4>
                <p>The forecasting engine runs on a separate Python Flask server. Start it to enable AI-powered demand predictions.</p>
                <div class="code-block">
                    # 1. Install dependencies (one time)<br>
                    pip install flask flask-cors mysql-connector-python pandas numpy scikit-learn<br><br>
                    # 2. Start the server<br>
                    python "Admin Console/forecast_api.py"
                </div>
                <p style="margin-top:10px;">Once running, refresh this page. The server listens on <strong>http://127.0.0.1:5001</strong>.</p>
            </div>
        </div>
        <?php else: ?>

        <!-- Filter -->
        <form method="GET" class="filter-bar" style="margin-bottom:20px;">
            <select name="branch">
                <option value="">All Branches</option>
                <?php foreach($branches as $b):?><option<?=$b===$branch?' selected':''?>><?=htmlspecialchars($b)?></option><?php endforeach;?>
            </select>
            <select name="days">
                <?php foreach([7,14,30,60] as $d):?><option value="<?=$d?>"<?=$d===$days?' selected':''?>><?=$d?> days ahead</option><?php endforeach;?>
            </select>
            <button type="submit" class="btn-orange"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Forecast</button>
        </form>

        <?php if ($forecast && $forecast['success']): ?>

        <!-- KPIs -->
        <div class="kpi-grid" style="margin-bottom:20px;">
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Trend</span><div class="kpi-icon <?=$forecast['trend']==='up'?'green':'red'?>"><i class="fa-solid fa-arrow-trend-<?=$forecast['trend']==='up'?'up':'down'?>"></i></div></div>
                <div class="kpi-value trend-<?=$forecast['trend']==='up'?'up':'dn'?>"><?=$forecast['trend']==='up'?'Growing':'Declining'?></div>
                <div class="kpi-meta"><?=$forecast['trend_pct']?>% per day trend</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Forecast Period</span><div class="kpi-icon orange"><i class="fa-solid fa-calendar-days"></i></div></div>
                <div class="kpi-value"><?=$days?> days</div>
                <div class="kpi-meta">ahead projection</div>
            </div>
            <?php
            $f_rev   = array_sum(array_column($forecast['forecast'],'revenue'));
            $f_units = array_sum(array_column($forecast['forecast'],'units'));
            ?>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Projected Revenue</span><div class="kpi-icon" style="background:rgba(99,102,241,0.1);color:#6366f1;"><i class="fa-solid fa-peso-sign"></i></div></div>
                <div class="kpi-value">₱<?=number_format($f_rev,0)?></div>
                <div class="kpi-meta">next <?=$days?> days</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-label">Projected Units</span><div class="kpi-icon green"><i class="fa-solid fa-cubes"></i></div></div>
                <div class="kpi-value"><?=number_format($f_units)?></div>
                <div class="kpi-meta">next <?=$days?> days</div>
            </div>
        </div>

        <!-- Forecast chart -->
        <div class="chart-card" style="margin-bottom:14px;">
            <div class="chart-card-header">
                <div>
                    <div class="chart-title">Revenue Forecast</div>
                    <div class="chart-subtitle">Historical (last 30 days) + <?=$days?>-day projection — <?=$branch?htmlspecialchars($branch):'All Branches'?></div>
                </div>
            </div>
            <div class="chart-wrap"><canvas id="forecastChart"></canvas></div>
        </div>

        <?php if ($products && $products['success'] && !empty($products['products'])): ?>
        <!-- Product risk table -->
        <div class="chart-card">
            <div class="chart-card-header">
                <div><div class="chart-title">Stock-Out Risk Prediction</div><div class="chart-subtitle">Products ranked by days until stock-out</div></div>
            </div>
            <table class="intel-table">
                <thead><tr>
                    <th>Product</th><th>SKU</th><th>Branch</th>
                    <th class="col-r">Units/30d</th><th class="col-r">Avg/Day</th>
                    <th class="col-r">Stock</th><th class="col-r">Days Left</th><th>Risk</th>
                </tr></thead>
                <tbody>
                <?php foreach($products['products'] as $p):
                    $rc='risk-'.$p['risk'];
                    $days_left=$p['days_to_out']>=999?'∞':$p['days_to_out'];
                    $rbadge=$p['risk']==='critical'?'badge-pill red':($p['risk']==='high'?'badge-pill orange':($p['risk']==='medium'?'badge-pill amber':'badge-pill'));
                    $rtxt=strtoupper($p['risk']);
                ?>
                <tr>
                    <td class="prod-name"><?=htmlspecialchars($p['name'])?></td>
                    <td class="col-mono"><?=htmlspecialchars($p['sku'])?></td>
                    <td><?=htmlspecialchars($p['branch'])?></td>
                    <td class="col-r col-num"><?=number_format($p['units_30d'])?></td>
                    <td class="col-r col-num"><?=$p['avg_daily']?>/day</td>
                    <td class="col-r col-num"><?=(int)$p['stock']?></td>
                    <td class="col-r col-num <?=$rc?>"><?=$days_left?></td>
                    <td><span class="<?=$rbadge?>"><?=$rtxt?></span></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php endif;?>

        <?php elseif ($forecast && !$forecast['success']): ?>
        <div class="intel-empty" style="padding:48px;text-align:center;">
            <i class="fa-solid fa-chart-line" style="font-size:32px;color:#d1d5db;margin-bottom:12px;display:block;"></i>
            <?=htmlspecialchars($forecast['error']??'Forecast unavailable.')?>
        </div>
        <?php endif;?>

        <?php endif;?>
    </div>
</div>

<?php if ($api_ok && $forecast && $forecast['success']): ?>
<script>
Chart.defaults.font.family="'Inter',-apple-system,sans-serif";
Chart.defaults.font.size=11;
Chart.defaults.color='#9ca3af';

var hist = <?=json_encode($forecast['historical'])?>;
var fore = <?=json_encode($forecast['forecast'])?>;

var histLabels = hist.map(function(d){return d.date;});
var foreLabels = fore.map(function(d){return d.date;});
var allLabels  = histLabels.concat(foreLabels);

var histRev = hist.map(function(d){return d.revenue;});
var foreRev = fore.map(function(d){return d.revenue;});

// Pad historical with nulls for forecast section, and forecast with nulls for historical
var histData = histRev.concat(foreRev.map(function(){return null;}));
var foreData = histRev.map(function(){return null;}).concat(foreRev);
// Connect at boundary
foreData[histRev.length-1] = histRev[histRev.length-1];

var ctx = document.getElementById('forecastChart').getContext('2d');
var gradHist = ctx.createLinearGradient(0,0,0,200);
gradHist.addColorStop(0,'rgba(232,97,26,0.2)');
gradHist.addColorStop(1,'rgba(232,97,26,0)');
var gradFore = ctx.createLinearGradient(0,0,0,200);
gradFore.addColorStop(0,'rgba(99,102,241,0.2)');
gradFore.addColorStop(1,'rgba(99,102,241,0)');

new Chart(ctx,{
    type:'line',
    data:{
        labels: allLabels,
        datasets:[
            {label:'Historical',data:histData,borderColor:'#e8611a',borderWidth:2,backgroundColor:gradHist,fill:true,tension:0.4,pointRadius:0,pointHoverRadius:4,pointHoverBackgroundColor:'#e8611a'},
            {label:'Forecast', data:foreData,borderColor:'#6366f1',borderWidth:2,backgroundColor:gradFore,fill:true,tension:0.4,pointRadius:0,pointHoverRadius:4,pointHoverBackgroundColor:'#6366f1',borderDash:[5,4]}
        ]
    },
    options:{
        responsive:true,maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{
            legend:{display:true,position:'top',labels:{boxWidth:12,padding:16}},
            tooltip:{backgroundColor:'#111827',titleColor:'#fff',bodyColor:'#9ca3af',padding:10,cornerRadius:8,
                callbacks:{label:function(c){return' '+c.dataset.label+': ₱'+Number(c.parsed.y||0).toLocaleString();}}}
        },
        scales:{
            x:{grid:{display:false},border:{display:false},ticks:{maxTicksLimit:10,color:'#9ca3af'}},
            y:{grid:{color:'rgba(0,0,0,0.05)'},border:{display:false},ticks:{color:'#9ca3af',callback:function(v){return'₱'+(v>=1000?(v/1000).toFixed(0)+'k':v);}}}
        }
    }
});
</script>
<?php endif;?>
</body>
</html>
