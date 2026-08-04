<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?tab=register');
    exit;
}

$full_name   = trim($_POST['full_name']   ?? '');
$employee_id = trim($_POST['employee_id'] ?? '');
$email       = trim($_POST['email']       ?? '');
$branch      = trim($_POST['branch']      ?? '');
$role        = $_POST['role']             ?? 'branch_staff';
$password    = $_POST['password']         ?? '';
$confirm     = $_POST['confirm_password'] ?? '';
$agree       = $_POST['agree']            ?? '';

$role = in_array($role, ['branch_staff', 'administrator']) ? $role : 'branch_staff';

// --- Validation ---
if (!$full_name || !$employee_id || !$email || !$password || !$confirm) {
    $_SESSION['reg_error'] = 'All fields are required.';
    header('Location: login.php?tab=register');
    exit;
}

// Branch is required only for branch staff
if ($role === 'branch_staff' && !$branch) {
    $_SESSION['reg_error'] = 'Please select your branch.';
    header('Location: login.php?tab=register');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['reg_error'] = 'Please enter a valid email address.';
    header('Location: login.php?tab=register');
    exit;
}

if (strlen($password) < 8) {
    $_SESSION['reg_error'] = 'Password must be at least 8 characters.';
    header('Location: login.php?tab=register');
    exit;
}

if ($password !== $confirm) {
    $_SESSION['reg_error'] = 'Passwords do not match.';
    header('Location: login.php?tab=register');
    exit;
}

if (!$agree) {
    $_SESSION['reg_error'] = 'You must accept the terms before registering.';
    header('Location: login.php?tab=register');
    exit;
}

// --- Check for duplicate email or employee ID ---
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR employee_id = ?");
$stmt->bind_param('ss', $email, $employee_id);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION['reg_error'] = 'An account with that email or Employee ID already exists.';
    $stmt->close();
    header('Location: login.php?tab=register');
    exit;
}
$stmt->close();

// --- Insert new user (approved immediately, no pending) ---
$hashed      = password_hash($password, PASSWORD_BCRYPT);
$branch_val  = ($role === 'administrator') ? 'All Branches' : $branch;

$stmt = $conn->prepare(
    "INSERT INTO users (full_name, employee_id, email, password, branch, role, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')"
);
$stmt->bind_param('ssssss', $full_name, $employee_id, $email, $hashed, $branch_val, $role);

if ($stmt->execute()) {
    $_SESSION['reg_success'] = 'Account created! You can now sign in.';
    header('Location: login.php?tab=signin');
} else {
    $_SESSION['reg_error'] = 'Registration failed. Please try again.';
    header('Location: login.php?tab=register');
}

$stmt->close();
$conn->close();
