<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'administrator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../Landing Page/db.php';

// Gracefully handle missing table (migration not yet run)
$tableCheck = $conn->query("SHOW TABLES LIKE 'audit_trail'");
if ($tableCheck->num_rows === 0) {
    echo json_encode(['success' => true, 'items' => [], 'has_more' => false, 'notice' => 'Run migration.sql to enable the audit trail.']);
    exit;
}

$branch  = trim($_GET['branch'] ?? '');
$page    = max(1, (int)($_GET['page']  ?? 1));
$perPage = min(50, max(5, (int)($_GET['limit'] ?? 15)));
$offset  = ($page - 1) * $perPage;
$fetch   = $perPage + 1; // one extra to detect next page

if ($branch !== '') {
    $stmt = $conn->prepare(
        "SELECT id, user_name, branch, action, entity_name, details, created_at
         FROM audit_trail
         WHERE branch = ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('sii', $branch, $fetch, $offset);
} else {
    $stmt = $conn->prepare(
        "SELECT id, user_name, branch, action, entity_name, details, created_at
         FROM audit_trail
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $fetch, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$items    = [];
$hasMore  = false;
$count    = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    if ($count > $perPage) { $hasMore = true; break; }
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

echo json_encode(['success' => true, 'items' => $items, 'has_more' => $hasMore]);
