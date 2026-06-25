<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email    = trim($_POST['email']    ?? '');
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
    $_SESSION['login_error'] = 'Email and password are required.';
    header('Location: login.php?tab=signin');
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, full_name, password, role, status FROM users WHERE email = ?"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($id, $full_name, $hashed_password, $role, $status);
$stmt->fetch();
$stmt->close();
$conn->close();

if (!$id || !password_verify($password, $hashed_password)) {
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: login.php?tab=signin');
    exit;
}

if ($status === 'rejected') {
    $_SESSION['login_error'] = 'Your account access has been rejected. Contact IT Support.';
    header('Location: login.php?tab=signin');
    exit;
}

// --- Success: start session ---
session_regenerate_id(true);
$_SESSION['user_id']   = $id;
$_SESSION['user_name'] = $full_name;
$_SESSION['user_role'] = $role;

if ($role === 'administrator') {
    header('Location: ../dashboard/admin.php');
} else {
    header('Location: ../dashboard/index.php');
}
exit;
