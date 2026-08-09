<?php
session_start();
require_once 'db.php';
require_once 'employee_id_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php?tab=register');
    exit;
}

$full_name   = trim($_POST['full_name']   ?? '');
$email       = trim($_POST['email']       ?? '');
$branch      = trim($_POST['branch']      ?? '');
$role        = 'branch_staff';
$password    = $_POST['password']         ?? '';
$confirm     = $_POST['confirm_password'] ?? '';

// --- Validation ---
if (!$full_name || !$email || !$branch || !$password || !$confirm) {
    $_SESSION['reg_error'] = 'All fields are required.';
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

// --- Check for duplicate email ---
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION['reg_error'] = 'An account with that email already exists.';
    $stmt->close();
    header('Location: login.php?tab=register');
    exit;
}
$stmt->close();

// --- Insert new user (approved immediately, no pending) ---
$hashed = password_hash($password, PASSWORD_BCRYPT);

$insert = $conn->prepare(
    "INSERT INTO users (full_name, employee_id, email, password, branch, role, status) VALUES (?, ?, ?, ?, ?, ?, 'offline')"
);

$attempts = 0;
do {
    $employee_id = next_employee_id($conn);
    $insert->bind_param('ssssss', $full_name, $employee_id, $email, $hashed, $branch, $role);
    try {
        $insert->execute();
        $_SESSION['reg_success'] = "Account created! Your Employee ID is {$employee_id}. You can now sign in.";
        header('Location: login.php?tab=signin');
        break;
    } catch (mysqli_sql_exception $e) {
        $attempts++;
        if ($e->getCode() !== 1062 || $attempts >= 3) {
            $_SESSION['reg_error'] = 'Registration failed. Please try again.';
            header('Location: login.php?tab=register');
            break;
        }
        // Duplicate employee_id from a concurrent signup — regenerate and retry.
    }
} while ($attempts < 3);

$insert->close();
$conn->close();
