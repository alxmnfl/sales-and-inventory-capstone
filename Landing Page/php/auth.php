<?php
/** Shared session and persistent-login helpers. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const REMEMBER_COOKIE = 'lucky8_remember';

function remember_cookie_options(int $expires): array {
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clear_remember_cookie(): void {
    setcookie(REMEMBER_COOKIE, '', remember_cookie_options(time() - 3600));
}

function create_remember_token(mysqli $conn, int $userId): void {
    $conn->query("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        selector CHAR(24) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY remember_selector (selector),
        KEY remember_user (user_id),
        KEY remember_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = time() + (30 * 24 * 60 * 60);
    $expiresSql = date('Y-m-d H:i:s', $expiresAt);

    $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $selector, $tokenHash, $expiresSql);
    $stmt->execute();
    $stmt->close();

    setcookie(REMEMBER_COOKIE, $selector . ':' . $token, remember_cookie_options($expiresAt));
}

function restore_remembered_login(mysqli $conn): void {
    if (isset($_SESSION['user_id']) || empty($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }

    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        clear_remember_cookie();
        return;
    }

    $selector = $parts[0];
    $tokenHash = hash('sha256', $parts[1]);
    $stmt = $conn->prepare('SELECT u.id, u.full_name, u.role, u.branch, t.token_hash FROM remember_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW() LIMIT 1');
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || !hash_equals($user['token_hash'], $tokenHash)) {
        $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE selector = ?');
        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $stmt->close();
        clear_remember_cookie();
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_branch'] = $user['branch'];
    if ($user['role'] === 'branch_staff') {
        $_SESSION['pos_cashier'] = strtoupper($user['full_name']);
        $_SESSION['pos_cashier_branch'] = strtoupper($user['branch'] ?? '');
    }

    // Rotate the token after every automatic sign-in.
    create_remember_token($conn, (int) $user['id']);
}

function forget_remembered_login(mysqli $conn): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $selector = explode(':', $_COOKIE[REMEMBER_COOKIE], 2)[0];
        if (preg_match('/^[a-f0-9]{24}$/', $selector)) {
            $stmt = $conn->prepare('DELETE FROM remember_tokens WHERE selector = ?');
            $stmt->bind_param('s', $selector);
            $stmt->execute();
            $stmt->close();
        }
    }
    clear_remember_cookie();
}

require_once 'db.php';
if (!defined('SKIP_REMEMBER_RESTORE')) {
    restore_remembered_login($conn);
}
