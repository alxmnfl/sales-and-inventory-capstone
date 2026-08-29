<?php
require_once '../../Landing Page/php/auth.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/php/db.php';

// Gracefully handle missing table (migration not yet run)
$tableCheck = $conn->query("SHOW TABLES LIKE 'audit_trail'");
if ($tableCheck->num_rows === 0) {
    echo json_encode(['success' => true, 'items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'notice' => 'Run migration.sql to enable the audit trail.']);
    exit;
}

$branch  = trim($_GET['branch'] ?? '');
$page    = max(1, (int)($_GET['page']  ?? 1));
$perPage = min(50, max(5, (int)($_GET['limit'] ?? 15)));

// Plain `=` on the branch column — its utf8mb4_general_ci collation already
// matches case- and accent-insensitively, so ñ/Ñ casing can't break the filter.
if ($branch !== '') {
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM audit_trail WHERE branch = ?");
    $countStmt->bind_param('s', $branch);
} else {
    $countStmt = $conn->prepare("SELECT COUNT(*) FROM audit_trail");
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_row()[0];
$countStmt->close();

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

if ($branch !== '') {
    $stmt = $conn->prepare(
        "SELECT id, user_name, branch, action, entity_name, details, created_at
         FROM audit_trail
         WHERE branch = ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('sii', $branch, $perPage, $offset);
} else {
    $stmt = $conn->prepare(
        "SELECT id, user_name, branch, action, entity_name, details, created_at
         FROM audit_trail
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id'          => (int)$row['id'],
        'user_name'   => $row['user_name'],
        'branch'      => $row['branch'],
        'action'      => $row['action'],
        'entity_name' => $row['entity_name'],
        'details'     => $row['details'],
        'created_at'  => $row['created_at'],
    ];
}

echo json_encode(['success' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages]);
