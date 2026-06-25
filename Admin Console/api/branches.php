<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/db.php';

$result   = $conn->query("SELECT DISTINCT UPPER(branch) AS branch FROM users WHERE branch IS NOT NULL AND branch != '' ORDER BY branch");
$branches = [];
while ($row = $result->fetch_assoc()) {
    $branches[] = $row['branch'];
}

echo json_encode(['success' => true, 'branches' => $branches]);
