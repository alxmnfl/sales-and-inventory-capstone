<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/db.php';

$branch = trim($_GET['branch'] ?? '');

// Calculate average daily consumption over last 30 days.
// Flag products whose projected days of stock remaining < 14.
$baseQuery = "
    SELECT
        p.id, p.name, p.sku, p.category, p.branch, p.stock,
        COALESCE(SUM(si.quantity), 0) / 30.0                       AS avg_daily_units,
        CASE
            WHEN COALESCE(SUM(si.quantity), 0) > 0
            THEN p.stock / (COALESCE(SUM(si.quantity), 0) / 30.0)
            ELSE 9999
        END                                                          AS days_remaining
    FROM pos_products p
    LEFT JOIN pos_sale_items si ON si.product_id = p.id
    LEFT JOIN pos_sales s       ON si.sale_id = s.id
                               AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";

if ($branch !== '') {
    $stmt = $conn->prepare($baseQuery . "
        WHERE p.branch = ?
        GROUP BY p.id
        HAVING days_remaining < 14 AND COALESCE(SUM(si.quantity), 0) > 0
        ORDER BY days_remaining ASC
        LIMIT 20
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($baseQuery . "
        GROUP BY p.id
        HAVING days_remaining < 14 AND COALESCE(SUM(si.quantity), 0) > 0
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
