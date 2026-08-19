<?php
session_start();
require_once '../../Landing Page/php/auth.php';
if (isset($_SESSION['user_id'])) {
    require_once '../../Landing Page/php/db.php';
    forget_remembered_login($conn);
    $stmt = $conn->prepare("UPDATE users SET status = 'offline' WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}
session_destroy();
header('Location: ../../Landing Page/php/login.php');
exit;
