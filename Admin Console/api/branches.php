<?php
require_once '../../Landing Page/php/auth.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/php/db.php';

// Every branch that appears anywhere (staff roster, product catalogue, or sales
// history) so branches with no staff still appear. (branch columns differ in
// collation between tables, hence the explicit COLLATE.)
$result = $conn->query("
    SELECT DISTINCT b AS branch FROM (
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci AS b FROM users
            WHERE branch IS NOT NULL AND branch <> '' AND UPPER(branch) <> 'ALL BRANCHES'
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_products
            WHERE branch IS NOT NULL AND branch <> ''
        UNION
        SELECT UPPER(branch) COLLATE utf8mb4_unicode_ci FROM pos_sales
            WHERE branch IS NOT NULL AND branch <> ''
    ) t
    ORDER BY branch
");
$branches = [];
while ($row = $result->fetch_assoc()) {
    $branches[] = $row['branch'];
}

echo json_encode(['success' => true, 'branches' => $branches]);
