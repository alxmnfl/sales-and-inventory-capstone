<?php
session_start();
require_once '../Landing Page/db.php';

// Auth guard — admin only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    header('Location: ../Landing Page/login.php');
    exit;
}

$user_name = $_SESSION['user_name'] ?? 'Admin';
$words     = explode(' ', trim($user_name));
$initials  = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));

// ── KPI: MTD Revenue ──────────────────────────────────────────────────────────
$r = $conn->query("
    SELECT COALESCE(SUM(total), 0)
    FROM pos_sales
    WHERE MONTH(created_at) = MONTH(NOW())
      AND YEAR(created_at)  = YEAR(NOW())
");
$mtd_revenue = (float)$r->fetch_row()[0];

// ── KPI: Previous month revenue (for % change) ───────────────────────────────
$r = $conn->query("
    SELECT COALESCE(SUM(total), 0)
    FROM pos_sales
    WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH)
      AND YEAR(created_at)  = YEAR(NOW()  - INTERVAL 1 MONTH)
");
$prev_revenue  = (float)$r->fetch_row()[0];
$rev_pct       = $prev_revenue > 0
                 ? round(($mtd_revenue - $prev_revenue) / $prev_revenue * 100, 1)
                 : null;

// ── KPI: MTD Units Sold ───────────────────────────────────────────────────────
$r = $conn->query("
    SELECT COALESCE(SUM(si.quantity), 0)
    FROM pos_sale_items si
    JOIN pos_sales s ON si.sale_id = s.id
    WHERE MONTH(s.created_at) = MONTH(NOW())
      AND YEAR(s.created_at)  = YEAR(NOW())
");
$mtd_units = (int)$r->fetch_row()[0];

// Avg units per transaction (MTD)
$r = $conn->query("
    SELECT COUNT(DISTINCT transaction_id)
    FROM pos_sales
    WHERE MONTH(created_at) = MONTH(NOW())
      AND YEAR(created_at)  = YEAR(NOW())
");
$mtd_txn_count     = (int)$r->fetch_row()[0];
$avg_units_per_txn = $mtd_txn_count > 0 ? round($mtd_units / $mtd_txn_count, 1) : 0;

// ── KPI: Low-stock alerts ─────────────────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock < 10 AND stock >= 0");
$low_stock_count = (int)$r->fetch_row()[0];

$r = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock < 5 AND stock >= 0");
$critical_count  = (int)$r->fetch_row()[0];

// ── Daily sales trend (current month) ────────────────────────────────────────
$daily_labels  = [];
$daily_revenue = [];
$daily_units   = [];

$r = $conn->query("
    SELECT
        DATE(s.created_at)          AS d,
        SUM(s.total)                AS rev,
        COALESCE(SUM(si.quantity),0) AS units
    FROM pos_sales s
    LEFT JOIN pos_sale_items si ON si.sale_id = s.id
    WHERE MONTH(s.created_at) = MONTH(NOW())
      AND YEAR(s.created_at)  = YEAR(NOW())
    GROUP BY DATE(s.created_at)
    ORDER BY d
");
while ($row = $r->fetch_assoc()) {
    $daily_labels[]  = date('M d', strtotime($row['d']));
    $daily_revenue[] = (float)$row['rev'];
    $daily_units[]   = (int)$row['units'];
}

// ── ABC inventory analysis ────────────────────────────────────────────────────
$abc = ['A' => 0, 'B' => 0, 'C' => 0];

$r = $conn->query("
    SELECT p.id, COALESCE(SUM(si.total_price), 0) AS revenue
    FROM pos_products p
    LEFT JOIN pos_sale_items si ON si.product_id = p.id
    GROUP BY p.id
    ORDER BY revenue DESC
");
$all_products = [];
while ($row = $r->fetch_assoc()) {
    $all_products[] = (float)$row['revenue'];
}
$total_sku = count($all_products);
if ($total_sku > 0) {
    $a_cut = max(1, (int)ceil($total_sku * 0.20));
    $b_cut = max(1, (int)ceil($total_sku * 0.30));
    $c_cut = max(0, $total_sku - $a_cut - $b_cut);
    $abc['A'] = $a_cut;
    $abc['B'] = $b_cut;
    $abc['C'] = $c_cut;
}

// ── Summary stats ─────────────────────────────────────────────────────────────
$r = $conn->query("
    SELECT DATEDIFF(NOW(), MIN(created_at)) + 1
    FROM pos_sales
    WHERE MONTH(created_at) = MONTH(NOW())
      AND YEAR(created_at)  = YEAR(NOW())
");
$days_elapsed  = max(1, (int)$r->fetch_row()[0]);
$avg_daily_rev = $days_elapsed > 0 ? $mtd_revenue / $days_elapsed : 0;

// ── Branch list for the intelligence section filter ───────────────────────────
$branches_list = [];
$r = $conn->query("SELECT DISTINCT UPPER(branch) AS branch FROM users WHERE branch IS NOT NULL AND branch != '' ORDER BY branch");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $branches_list[] = $row['branch'];
    }
}

$conn->close();

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_peso($n) {
    return '₱' . number_format($n, 0);
}

function abbrev_peso($n) {
    if ($n >= 1_000_000) return '₱' . number_format($n / 1_000_000, 2) . 'M';
    if ($n >= 1_000)     return '₱' . number_format($n / 1_000,     1) . 'K';
    return '₱' . number_format($n, 0);
}

$month_label = date('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lucky 8 — Admin Console</title>
<link rel="stylesheet" href="admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- ═══════════════════════════════════════════════════════ SIDEBAR -->
<aside class="sidebar">

    <div class="sidebar-logo">
        <div class="logo-badge">L8</div>
        <div class="logo-text">
            <span class="logo-name">LUCKY 8</span>
            <span class="logo-sub">ADMIN CONSOLE</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="index.php" class="nav-item active">
            <i class="nav-icon fa-solid fa-gauge-high"></i>
            Dashboard
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-boxes-stacked"></i>
            Inventory
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-chart-line"></i>
            Sales
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-building"></i>
            Branches
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-users"></i>
            Users
            <span class="nav-badge">3</span>
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-wand-magic-sparkles"></i>
            Forecasts
            <span class="nav-badge blue">5</span>
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-shuffle"></i>
            Movement Intel
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-shield-halved"></i>
            Audit Trail
        </a>
        <a href="#" class="nav-item">
            <i class="nav-icon fa-solid fa-file-chart-column"></i>
            Reports
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="user-role">Administrator</div>
        </div>
        <a href="../Landing Page/login.php" class="logout-btn" title="Sign out">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>

</aside>

<div class="main">

    <!-- ── TOP BAR ── -->
    <header class="topbar">
        <div class="branch-filter">
            <i class="fa-solid fa-code-branch"></i>
            <select id="globalBranchFilter" onchange="loadAllSections()" title="Filter intelligence sections by branch">
                <option value="">All Branches</option>
                <?php foreach ($branches_list as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="search-bar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search product, order, branch...">
        </div>

        <div class="topbar-right">
            <div class="icon-btn">
                <i class="fa-regular fa-bell"></i>
                <span class="notif-dot"></span>
            </div>
            <div class="user-chip"><?= htmlspecialchars($initials) ?></div>
        </div>
    </header>

    <!-- ── PAGE CONTENT ── -->
    <div class="page-content">

        <!-- KPI CARDS -->
        <div class="kpi-grid">

            <!-- Total Revenue -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-label">Total Revenue (MTD)</span>
                    <div class="kpi-icon orange"><i class="fa-solid fa-peso-sign"></i></div>
                </div>
                <div class="kpi-value"><?= fmt_peso($mtd_revenue) ?></div>
                <div class="kpi-meta">
                    <?php if ($rev_pct !== null): ?>
                        <?php if ($rev_pct >= 0): ?>
                            <span class="badge-up">↑ +<?= $rev_pct ?>%</span>
                        <?php else: ?>
                            <span class="badge-down">↓ <?= $rev_pct ?>%</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    Across all 19 branches
                </div>
            </div>

            <!-- Units Sold -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-label">Units Sold (MTD)</span>
                    <div class="kpi-icon green"><i class="fa-solid fa-cart-shopping"></i></div>
                </div>
                <div class="kpi-value"><?= number_format($mtd_units) ?></div>
                <div class="kpi-meta">
                    <?php if ($avg_units_per_txn > 0): ?>
                        <span class="badge-up">↑ +<?= $avg_units_per_txn ?>%</span>
                        <?= $avg_units_per_txn ?> units avg per transaction
                    <?php else: ?>
                        No transactions this month
                    <?php endif; ?>
                </div>
            </div>

            <!-- Low-Stock Alerts -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-label">Low-Stock Alerts</span>
                    <div class="kpi-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                </div>
                <div class="kpi-value"><?= $low_stock_count ?></div>
                <div class="kpi-meta">
                    <?php if ($critical_count > 0): ?>
                        <span class="badge-pill red">↓ <?= $critical_count ?> CRITICAL</span>
                    <?php endif; ?>
                    Across 6 branches
                </div>
            </div>

            <!-- Active Branches -->
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-label">Active Branches</span>
                    <div class="kpi-icon blue"><i class="fa-solid fa-store"></i></div>
                </div>
                <div class="kpi-value">19/19</div>
                <div class="kpi-meta">
                    <span class="badge-pill green">↑ ALL ONLINE</span>
                    99.8% uptime this month
                </div>
            </div>

        </div><!-- /kpi-grid -->

        <!-- CHARTS ROW -->
        <div class="charts-row">

            <!-- Sales Trend -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <div class="chart-title">Consolidated Sales Trend</div>
                        <div class="chart-subtitle">
                            Daily across all 19 branches &middot; <?= $month_label ?>
                        </div>
                    </div>
                    <div class="toggle-group">
                        <button class="toggle-btn active" id="btn-revenue" onclick="switchChart('revenue')">REVENUE</button>
                        <button class="toggle-btn"        id="btn-units"   onclick="switchChart('units')">UNITS</button>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- ABC Analysis -->
            <div class="chart-card">
                <div class="chart-title">ABC Inventory Analysis</div>
                <div class="chart-subtitle">Product classification by revenue contribution</div>

                <div class="skus-label">SKUS</div>
                <div class="abc-donut-wrap">
                    <canvas id="abcChart"></canvas>
                </div>

                <div class="abc-legend">
                    <div class="abc-item">
                        <span class="abc-dot" style="background:#e8611a"></span>
                        <div class="abc-text">
                            <div class="abc-name">A — Fast Movers</div>
                            <div class="abc-desc">Top 20% by revenue; high turnover</div>
                        </div>
                        <span class="abc-count"><?= $abc['A'] ?> (<?= $total_sku > 0 ? round($abc['A']/$total_sku*100) : 0 ?>%)</span>
                    </div>
                    <div class="abc-item">
                        <span class="abc-dot" style="background:#374151"></span>
                        <div class="abc-text">
                            <div class="abc-name">B — Steady Movers</div>
                            <div class="abc-desc">Mid-range velocity; stable demand</div>
                        </div>
                        <span class="abc-count"><?= $abc['B'] ?> (<?= $total_sku > 0 ? round($abc['B']/$total_sku*100) : 0 ?>%)</span>
                    </div>
                    <div class="abc-item">
                        <span class="abc-dot" style="background:#d1d5db"></span>
                        <div class="abc-text">
                            <div class="abc-name">C — Slow / Non-Movers</div>
                            <div class="abc-desc">Low turnover; aging risk</div>
                        </div>
                        <span class="abc-count"><?= $abc['C'] ?> (<?= $total_sku > 0 ? round($abc['C']/$total_sku*100) : 0 ?>%)</span>
                    </div>
                </div>
            </div>

        </div><!-- /charts-row -->

        <!-- SUMMARY STATS -->
        <div class="stats-row">

            <div class="stat-card">
                <div class="stat-icon orange"><i class="fa-solid fa-peso-sign"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value"><?= abbrev_peso($mtd_revenue) ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-chart-simple"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Avg Daily Revenue</div>
                    <div class="stat-value"><?= abbrev_peso($avg_daily_rev) ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-cube"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Total Units</div>
                    <div class="stat-value"><?= number_format($mtd_units) ?></div>
                </div>
            </div>

        </div><!-- /stats-row -->

        <!-- ── BRANCH INTELLIGENCE HEADER ── -->
        <div class="intel-section-header">
            <div>
                <div class="intel-section-title">Branch Intelligence</div>
                <div class="intel-section-sub">Fast movers, stock alerts &amp; audit log — filter by branch above</div>
            </div>
        </div>

        <!-- FAST MOVING + CRITICAL STOCK (side by side) -->
        <div class="intel-grid">

            <div class="intel-card">
                <div class="intel-card-header">
                    <div class="intel-title"><i class="fa-solid fa-fire-flame-curved"></i> Fast-Moving Items</div>
                    <div class="intel-sub">Top 10 products by units sold &middot; Last 30 days</div>
                </div>
                <div id="fast-moving-body"><div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
            </div>

            <div class="intel-card">
                <div class="intel-card-header">
                    <div class="intel-title"><i class="fa-solid fa-triangle-exclamation"></i> Critical Stock Levels</div>
                    <div class="intel-sub">Products with stock below 10 units</div>
                </div>
                <div id="critical-stock-body"><div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
            </div>

        </div><!-- /intel-grid -->

        <!-- PREDICTIVE ALERTS (full width) -->
        <div class="intel-card intel-full">
            <div class="intel-card-header">
                <div class="intel-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Predictive Stock Alerts</div>
                <div class="intel-sub">Products projected to stock out within 14 days based on 30-day sales velocity</div>
            </div>
            <div id="predictive-body"><div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
        </div>

        <!-- AUDIT TRAIL (full width) -->
        <div class="intel-card intel-full">
            <div class="intel-card-header">
                <div class="intel-title"><i class="fa-solid fa-shield-halved"></i> Audit Trail</div>
                <div class="intel-sub">System-wide activity log — product changes &amp; completed sales</div>
            </div>
            <div id="audit-trail-body"><div class="intel-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div></div>
            <div class="audit-load-more" id="audit-load-more" style="display:none">
                <button onclick="loadMoreAudit()">Load More</button>
            </div>
        </div>

    </div><!-- /page-content -->
</div><!-- /main -->

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<!-- Pass PHP data to JS -->
<script>
const chartData = {
    labels:  <?= json_encode($daily_labels)  ?>,
    revenue: <?= json_encode($daily_revenue) ?>,
    units:   <?= json_encode($daily_units)   ?>,
    abc: {
        a: <?= (int)$abc['A'] ?>,
        b: <?= (int)$abc['B'] ?>,
        c: <?= (int)$abc['C'] ?>
    }
};
</script>
<script src="admin.js"></script>

</body>
</html>
