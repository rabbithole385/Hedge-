<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/* ---------------- USER AUTH ---------------- */

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = get_db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: /auth/login.php');
        exit;
    }
    if ($user['status'] !== 'active') {
        session_destroy();
        header('Location: /auth/login.php?error=suspended');
        exit;
    }
    return $user;
}

function login_user(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}

/* ---------------- ADMIN AUTH ---------------- */

function current_admin(): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    $stmt = get_db()->prepare('SELECT * FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    return $admin ?: null;
}

function require_admin(): array {
    $admin = current_admin();
    if (!$admin) {
        header('Location: /admin/login.php');
        exit;
    }
    return $admin;
}

function login_admin(int $adminId): void {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
}

function logout_admin(): void {
    unset($_SESSION['admin_id']);
}
