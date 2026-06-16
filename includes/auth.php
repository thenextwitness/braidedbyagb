<?php
// ============================================================
// BraidedbyAGB — Admin Authentication
// FILE: /includes/auth.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
// helpers.php is required by callers before auth.php - no need to re-include

// ── Check if admin is logged in ───────────────────────────
function isAdmin(): bool {
    return !empty($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

// ── Require admin — redirect to login if not ─────────────
function requireAdmin(): void {
    if (!isAdmin()) {
        header('Location: /admin/login');
        exit;
    }
}

// ── Login ─────────────────────────────────────────────────
function adminLogin(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE email = ?");
    $stmt->execute([filter_var(trim($email), FILTER_SANITIZE_EMAIL)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid credentials.'];
    }

    // Check lockout
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "Account locked. Try again in {$mins} minute(s)."];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int)$user['login_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= 5) {
            $lockedUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $attempts = 0;
        }
        $db->prepare("UPDATE admin_users SET login_attempts = ?, locked_until = ? WHERE id = ?")
           ->execute([$attempts, $lockedUntil, $user['id']]);
        $remaining = max(0, 5 - $attempts);
        return ['success' => false, 'message' => "Invalid credentials. {$remaining} attempt(s) remaining."];
    }

    // Success — set session and redirect
    $db->prepare("UPDATE admin_users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
       ->execute([$user['id']]);

    $_SESSION['admin_id']    = (int)$user['id'];
    $_SESSION['admin_name']  = $user['name'] ?? 'Admin';
    $_SESSION['admin_email'] = $user['email'];
    $_SESSION['csrf_token']  = bin2hex(random_bytes(16));

    return ['success' => true];
}

// ── Logout ────────────────────────────────────────────────
function adminLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /admin/login');
    exit;
}

// ── Change password ───────────────────────────────────────
function changeAdminPassword(int $adminId, string $currentPass, string $newPass): array {
    if (strlen($newPass) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
    }
    $db   = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
    $stmt->execute([$adminId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPass, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")
       ->execute([$hash, $adminId]);

    return ['success' => true, 'message' => 'Password updated successfully.'];
}
