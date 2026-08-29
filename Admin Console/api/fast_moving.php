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
$limit  = min(20, max(1, (int)($_GET['limit'] ?? 10)));

// Units/revenue for the last 30 days only. The date filter must live in a
// subquery so it restricts the summed sale-item rows — putting it on the
// pos_sales JOIN's ON clause leaves SUM(si.quantity) counting all-time sales.
$baseQuery = "
    SELECT p.name, p.sku, p.category, p.branch,
           COALESCE(SUM(u.qty), 0)   AS total_units,
           COALESCE(SUM(u.price), 0) AS total_revenue
    FROM pos_products p
    LEFT JOIN (
        SELECT si.product_id,
               si.quantity    AS qty,
               si.total_price AS price
        FROM pos_sale_items si
        JOIN pos_sales s ON s.id = si.sale_id
        WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ) u ON u.product_id = p.id
";

if ($branch !== '') {
    $stmt = $conn->prepare($baseQuery . "
        WHERE UPPER(p.branch) = ?
        GROUP BY p.id
        HAVING total_units > 0
        ORDER BY total_units DESC
        LIMIT $limit
    ");
    $stmt->bind_param('s', $branch);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($baseQuery . "
        GROUP BY p.id
        HAVING total_units > 0
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
