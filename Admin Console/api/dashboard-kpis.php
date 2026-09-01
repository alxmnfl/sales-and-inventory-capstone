<?php
require_once '../../Landing Page/php/auth.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/php/db.php';

$branch = strtoupper(trim($_GET['branch'] ?? ''));

if ($branch !== '') {
    $b = $branch;

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM pos_sales
        WHERE MONTH(created_at) = MONTH(NOW())
          AND YEAR(created_at)  = YEAR(NOW())
          AND UPPER(branch) = ?
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $mtd_revenue = (float)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM pos_sales
        WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH)
          AND YEAR(created_at)  = YEAR(NOW()  - INTERVAL 1 MONTH)
          AND UPPER(branch) = ?
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $prev_revenue = (float)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(si.quantity), 0)
        FROM pos_sale_items si
        JOIN pos_sales s ON si.sale_id = s.id
        WHERE MONTH(s.created_at) = MONTH(NOW())
          AND YEAR(s.created_at)  = YEAR(NOW())
          AND UPPER(s.branch) = ?
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $mtd_units = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT transaction_id)
        FROM pos_sales
        WHERE MONTH(created_at) = MONTH(NOW())
          AND YEAR(created_at)  = YEAR(NOW())
          AND UPPER(branch) = ?
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $mtd_txn_count = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("
        SELECT DATE(s.created_at) AS d,
               SUM(s.total)                AS rev,
               COALESCE(SUM(si.quantity),0) AS units
        FROM pos_sales s
        LEFT JOIN pos_sale_items si ON si.sale_id = s.id
        WHERE MONTH(s.created_at) = MONTH(NOW())
          AND YEAR(s.created_at)  = YEAR(NOW())
          AND UPPER(s.branch) = ?
        GROUP BY DATE(s.created_at)
        ORDER BY d
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $res = $stmt->get_result();
    $daily_labels  = [];
    $daily_revenue = [];
    $daily_units   = [];
    while ($row = $res->fetch_assoc()) {
        $daily_labels[]  = date('M d', strtotime($row['d']));
        $daily_revenue[] = (float)$row['rev'];
        $daily_units[]   = (int)$row['units'];
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM pos_products WHERE stock < 10 AND stock >= 0 AND branch = ?");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $low_stock_count = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM pos_products WHERE stock < 5 AND stock >= 0 AND branch = ?");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $critical_count = (int)$stmt->get_result()->fetch_row()[0];

    $low_stock_branches = $low_stock_count > 0 ? 1 : 0;

    $stmt = $conn->prepare("
        SELECT p.id, COALESCE(SUM(si.total_price), 0) AS revenue
        FROM pos_products p
        LEFT JOIN pos_sale_items si ON si.product_id = p.id
        WHERE UPPER(p.branch) = ?
        GROUP BY p.id
        ORDER BY revenue DESC
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $res = $stmt->get_result();
    $revs = [];
    while ($row = $res->fetch_assoc()) {
        $revs[] = (float)$row['revenue'];
    }
    $total_sku = count($revs);
    $a_cut = $total_sku > 0 ? max(1, (int)ceil($total_sku * 0.20)) : 0;
    $b_cut = $total_sku > 0 ? max(1, (int)ceil($total_sku * 0.30)) : 0;
    $c_cut = max(0, $total_sku - $a_cut - $b_cut);

    $stmt = $conn->prepare("
        SELECT COALESCE(DATEDIFF(NOW(), MIN(created_at)) + 1, 1)
        FROM pos_sales
        WHERE MONTH(created_at) = MONTH(NOW())
          AND YEAR(created_at)  = YEAR(NOW())
          AND UPPER(branch) = ?
    ");
    $stmt->bind_param('s', $b);
    $stmt->execute();
    $days_elapsed = (int)$stmt->get_result()->fetch_row()[0];

    $active_branches = 1;

} else {
    $r = $conn->query("SELECT COALESCE(SUM(total), 0) FROM pos_sales WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $mtd_revenue = (float)$r->fetch_row()[0];

    $r = $conn->query("SELECT COALESCE(SUM(total), 0) FROM pos_sales WHERE MONTH(created_at) = MONTH(NOW() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(NOW() - INTERVAL 1 MONTH)");
    $prev_revenue = (float)$r->fetch_row()[0];

    $r = $conn->query("SELECT COALESCE(SUM(si.quantity), 0) FROM pos_sale_items si JOIN pos_sales s ON si.sale_id = s.id WHERE MONTH(s.created_at) = MONTH(NOW()) AND YEAR(s.created_at) = YEAR(NOW())");
    $mtd_units = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(DISTINCT transaction_id) FROM pos_sales WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $mtd_txn_count = (int)$r->fetch_row()[0];

    $r = $conn->query("
        SELECT DATE(s.created_at) AS d,
               SUM(s.total)                AS rev,
               COALESCE(SUM(si.quantity),0) AS units
        FROM pos_sales s
        LEFT JOIN pos_sale_items si ON si.sale_id = s.id
        WHERE MONTH(s.created_at) = MONTH(NOW())
          AND YEAR(s.created_at)  = YEAR(NOW())
        GROUP BY DATE(s.created_at)
        ORDER BY d
    ");
    $daily_labels  = [];
    $daily_revenue = [];
    $daily_units   = [];
    while ($row = $r->fetch_assoc()) {
        $daily_labels[]  = date('M d', strtotime($row['d']));
        $daily_revenue[] = (float)$row['rev'];
        $daily_units[]   = (int)$row['units'];
    }

    $r = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock < 10 AND stock >= 0");
    $low_stock_count = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(*) FROM pos_products WHERE stock < 5 AND stock >= 0");
    $critical_count = (int)$r->fetch_row()[0];

    $r = $conn->query("SELECT COUNT(DISTINCT branch) FROM pos_products WHERE stock < 10 AND stock >= 0 AND branch IS NOT NULL AND branch <> ''");
    $low_stock_branches = (int)$r->fetch_row()[0];

    $r = $conn->query("
        SELECT p.id, COALESCE(SUM(si.total_price), 0) AS revenue
        FROM pos_products p
        LEFT JOIN pos_sale_items si ON si.product_id = p.id
        GROUP BY p.id
        ORDER BY revenue DESC
    ");
    $revs = [];
    while ($row = $r->fetch_assoc()) {
        $revs[] = (float)$row['revenue'];
    }
    $total_sku = count($revs);
    $a_cut = $total_sku > 0 ? max(1, (int)ceil($total_sku * 0.20)) : 0;
    $b_cut = $total_sku > 0 ? max(1, (int)ceil($total_sku * 0.30)) : 0;
    $c_cut = max(0, $total_sku - $a_cut - $b_cut);

    $r = $conn->query("SELECT DATEDIFF(NOW(), MIN(created_at)) + 1 FROM pos_sales WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $days_elapsed = max(1, (int)$r->fetch_row()[0]);

    // Operational branches = every branch the system knows about (staff roster,
    // product catalogue, or sales history) — NOT just branches that happened to
    // record a sale in the current calendar month, which collapses to 0 at the
    // start of a month before any sales land.
    $r = $conn->query("
        SELECT COUNT(*) FROM (
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci AS b FROM users
                WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
            UNION
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_products
                WHERE branch IS NOT NULL AND branch <> ''
            UNION
            SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_sales
                WHERE branch IS NOT NULL AND branch <> ''
        ) t
    ");
    $active_branches = (int)$r->fetch_row()[0];
}

$rev_pct = $prev_revenue > 0 ? round(($mtd_revenue - $prev_revenue) / $prev_revenue * 100, 1) : null;
$avg_units_per_txn = $mtd_txn_count > 0 ? round($mtd_units / $mtd_txn_count, 1) : 0;
$avg_daily_rev = $days_elapsed > 0 ? $mtd_revenue / $days_elapsed : 0;

echo json_encode([
    'success'          => true,
    'mtd_revenue'      => $mtd_revenue,
    'prev_revenue'     => $prev_revenue,
    'rev_pct'          => $rev_pct,
    'mtd_units'        => $mtd_units,
    'avg_units_per_txn'=> $avg_units_per_txn,
    'mtd_txn_count'    => $mtd_txn_count,
    'daily_labels'     => $daily_labels,
    'daily_revenue'    => $daily_revenue,
    'daily_units'      => $daily_units,
    'low_stock_count'  => $low_stock_count,
    'critical_count'   => $critical_count,
    'low_stock_branches' => $low_stock_branches,
    'abc'              => ['a' => $a_cut, 'b' => $b_cut, 'c' => $c_cut, 'total_sku' => $total_sku],
    'days_elapsed'     => $days_elapsed,
    'avg_daily_rev'    => $avg_daily_rev,
    'active_branches'  => $active_branches
]);
