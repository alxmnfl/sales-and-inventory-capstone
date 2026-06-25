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
$limit  = min(20, max(1, (int)($_GET['limit'] ?? 10)));

if ($branch !== '') {
    $stmt = $conn->prepare("
        SELECT p.name, p.sku, p.category, p.branch,
               COALESCE(SUM(si.quantity), 0)    AS total_units,
               COALESCE(SUM(si.total_price), 0) AS total_revenue
        FROM pos_products p
        LEFT JOIN pos_sale_items si ON si.product_id = p.id
        LEFT JOIN pos_sales s       ON si.sale_id = s.id
                                   AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        WHERE p.branch = ?
        GROUP BY p.id
        ORDER BY total_units DESC
        LIMIT $limit
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("
        SELECT p.name, p.sku, p.category, p.branch,
               COALESCE(SUM(si.quantity), 0)    AS total_units,
               COALESCE(SUM(si.total_price), 0) AS total_revenue
        FROM pos_products p
        LEFT JOIN pos_sale_items si ON si.product_id = p.id
        LEFT JOIN pos_sales s       ON si.sale_id = s.id
                                   AND s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY p.id
        ORDER BY total_units DESC
        LIMIT $limit
    ");
}

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'name'          => $row['name'],
        'sku'           => $row['sku'],
        'category'      => $row['category'],
        'branch'        => $row['branch'],
        'total_units'   => (int)$row['total_units'],
        'total_revenue' => (float)$row['total_revenue'],
    ];
}

echo json_encode(['success' => true, 'items' => $items]);
