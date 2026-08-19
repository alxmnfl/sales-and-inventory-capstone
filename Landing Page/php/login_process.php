<?php
session_start();
require_once 'db.php';
define('SKIP_REMEMBER_RESTORE', true);
require_once 'auth.php';

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
    "SELECT id, full_name, password, role, branch FROM users WHERE email = ?"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->bind_result($id, $full_name, $hashed_password, $role, $branch);
$stmt->fetch();
$stmt->close();

if (!$id || !password_verify($password, $hashed_password ?? '')) {
    $conn->close();
    $_SESSION['login_error'] = 'Invalid email or password.';
    header('Location: login.php?tab=signin');
    exit;
}

$stmt = $conn->prepare("UPDATE users SET status = 'online' WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

// --- Success: start session ---
session_regenerate_id(true);
$_SESSION['user_id']     = $id;
$_SESSION['user_name']   = $full_name;
$_SESSION['user_role']   = $role;
$_SESSION['user_branch'] = $branch;

if (isset($_POST['remember']) && $_POST['remember'] === '1') {
    create_remember_token($conn, (int) $id);
} else {
    forget_remembered_login($conn);
}
$conn->close();

if ($role === 'administrator') {
    header('Location: ../../Admin%20Console/php/index.php');
} else {
    // Staff — set POS cashier session and send to POS
    $_SESSION['pos_cashier']        = strtoupper($full_name ?? '');
    $_SESSION['pos_cashier_branch'] = strtoupper($branch ?? '');
    header('Location: ../../POS/php/index.php');
}
exit;
