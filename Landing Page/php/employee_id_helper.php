<?php
// Auto-generates the next Employee ID for the current year: L8-YYYY-NNNN
function next_employee_id($conn) {
    $prefix = 'L8-' . date('Y') . '-';
    $like   = $prefix . '%';
    $max    = 0;

    $stmt = $conn->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $eid = '';
    $stmt->bind_result($eid);
    while ($stmt->fetch()) {
        $suffix = substr($eid, strlen($prefix));
        if (ctype_digit($suffix)) {
            $max = max($max, (int)$suffix);
        }
    }
    $stmt->close();

    return $prefix . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}
