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

// Average daily consumption over the last 30 days; flag products whose
// projected days of stock remaining < 14. The 30-day filter must live in a
// subquery so it restricts the summed sale-item rows — on the pos_sales JOIN's
// ON clause, SUM(si.quantity) would keep counting all-time sales and the
// "/ 30.0" would understate consumption badly.
$baseQuery = "
    SELECT
        p.id, p.name, p.sku, p.category, p.branch, p.stock,
        COALESCE(SUM(u.qty), 0) / 30.0                        AS avg_daily_units,
        CASE
            WHEN COALESCE(SUM(u.qty), 0) > 0
            THEN p.stock / (COALESCE(SUM(u.qty), 0) / 30.0)
            ELSE 9999
        END                                                  AS days_remaining
    FROM pos_products p
    LEFT JOIN (
        SELECT si.product_id, si.quantity AS qty
        FROM pos_sale_items si
        JOIN pos_sales s ON s.id = si.sale_id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ) u ON u.product_id = p.id
";

if ($branch !== '') {
    $stmt = $conn->prepare($baseQuery . "
        WHERE UPPER(p.branch) = ?
        GROUP BY p.id
        HAVING days_remaining < 14 AND COALESCE(SUM(u.qty), 0) > 0
        ORDER BY days_remaining ASC
        LIMIT 20
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($baseQuery . "
        GROUP BY p.id
        HAVING days_remaining < 14 AND COALESCE(SUM(u.qty), 0) > 0
        ORDER BY days_remaining ASC
        LIMIT 20
    ");
}

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id'              => (int)$row['id'],
        'name'            => $row['name'],
        'sku'             => $row['sku'],
        'category'        => $row['category'],
        'branch'          => $row['branch'],
        'stock'           => (int)$row['stock'],
        'avg_daily_units' => round((float)$row['avg_daily_units'], 2),
        'days_remaining'  => round((float)$row['days_remaining'], 1),
    ];
}

echo json_encode(['success' => true, 'items' => $items]);
