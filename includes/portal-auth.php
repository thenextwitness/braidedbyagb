<?php
// ============================================================
// BraidedbyAGB — Portal authentication (clients now, stylists in Phase C)
// FILE: /includes/portal-auth.php
//
// ONE shared, security-critical auth core for the customer/stylist portal,
// kept entirely separate from the admin session (includes/auth.php). Admins
// authenticate on $_SESSION['admin_id']; the portal uses its own keys, so a
// portal user can never become an admin.
//
// Identity lives in two tables — customers (clients) and, from Phase C,
// stylists — but the sensitive logic (code hashing, rate limiting, lockout,
// remember-me, session hardening) exists ONCE here, parameterised by an
// "identity provider". Only the client provider is registered today; Phase C
// registers a stylist provider and everything else is reused unchanged.
//
// Default login is a 6-digit code emailed to the address on file (no password
// to forget). A client may additionally set a password for faster repeat login.
//
// Assumes config/database.php + includes/helpers.php are loaded.
// ============================================================

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

// Pepper for the login-code HMAC. The authoritative fallback lives HERE (in
// deployed code), not only in config/database.php — config is excluded from
// every deploy, so relying on it there would leave AUTH_CODE_PEPPER undefined on
// the server and fatal every sign-in. It self-derives from server-only secrets,
// so there is no manual step; setting AUTH_CODE_PEPPER in config still overrides.
if (!defined('AUTH_CODE_PEPPER')) {
    define('AUTH_CODE_PEPPER', hash('sha256', 'agb-portal-pepper|' . DB_PASS . '|' . MIGRATE_KEY));
}

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before it is created.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Tunables ──────────────────────────────────────────────
const PORTAL_CODE_TTL_MINUTES   = 10;   // how long an emailed code is valid
const PORTAL_CODE_MAX_ATTEMPTS  = 5;    // wrong tries before a code is burned
const PORTAL_CODE_RATE_MAX      = 4;    // codes issuable per email per window
const PORTAL_CODE_RATE_WINDOW   = 900;  // that window, seconds (15 min)
const PORTAL_PW_MAX_ATTEMPTS    = 5;    // wrong passwords before lockout
const PORTAL_PW_LOCK_MINUTES    = 15;
const PORTAL_REMEMBER_DAYS       = 30;
const PORTAL_REMEMBER_COOKIE     = 'agb_remember';

// ── Identity providers ────────────────────────────────────
// Each provider knows how to read/update one identity table. Phase C adds a
// 'stylist' provider; nothing else in this file changes.
function portalProviders(): array {
    static $providers = null;
    if ($providers !== null) return $providers;

    $providers = [
        'client' => [
            'table'      => 'customers',
            'name_field' => 'name',
        ],
        // A stylist can authenticate only while active AND portal-enabled. The
        // owner seed is portal_enabled=0 (she uses the admin panel), so this
        // gate keeps her — and any deactivated stylist — out of the portal even
        // if a password or remember-token still exists.
        'stylist' => [
            'table'      => 'stylists',
            'name_field' => 'name',
            'filter_sql' => 'is_active = 1 AND portal_enabled = 1',
        ],
    ];
    return $providers;
}

function portalProvider(string $userType): ?array {
    return portalProviders()[$userType] ?? null;
}

/**
 * Find every identity (across providers) whose email matches. Returns rows of
 * ['user_type','id','name','email','password_hash','is_blocked'].
 * Blocked clients are excluded — they can never authenticate.
 */
function portalFindIdentities(PDO $db, string $email): array {
    $email = strtolower(trim($email));
    if ($email === '') return [];
    $out = [];
    foreach (portalProviders() as $type => $p) {
        $blockedSel = $type === 'client' ? 'is_blocked' : '0 AS is_blocked';
        $pwSel      = 'password_hash';
        $where      = 'email = ?';
        if (!empty($p['filter_sql'])) $where .= ' AND ' . $p['filter_sql'];
        try {
            $s = $db->prepare("SELECT id, {$p['name_field']} AS name, email, {$pwSel}, {$blockedSel}
                               FROM {$p['table']} WHERE {$where} LIMIT 1");
            $s->execute([$email]);
            $row = $s->fetch();
        } catch (Throwable $e) {
            // Provider table not present yet (e.g. stylists before Phase C).
            continue;
        }
        if (!$row) continue;
        if ($type === 'client' && (int)($row['is_blocked'] ?? 0) === 1) continue;
        $out[] = [
            'user_type'     => $type,
            'id'            => (int)$row['id'],
            'name'          => (string)$row['name'],
            'email'         => (string)$row['email'],
            'password_hash' => $row['password_hash'] ?? null,
        ];
    }
    return $out;
}

// ── HMAC of a login code ──────────────────────────────────
function portalHashCode(string $code): string {
    return hash_hmac('sha256', $code, AUTH_CODE_PEPPER);
}

// ── Session state ─────────────────────────────────────────
function isPortalUser(): bool {
    return !empty($_SESSION['portal_user_id']) && !empty($_SESSION['portal_user_type']);
}
function currentPortalType(): ?string {
    return $_SESSION['portal_active_role'] ?? $_SESSION['portal_user_type'] ?? null;
}
function currentPortalId(): int {
    return (int)($_SESSION['portal_user_id'] ?? 0);
}
function isClient(): bool  { return isPortalUser() && currentPortalType() === 'client'; }
function isStylist(): bool { return isPortalUser() && currentPortalType() === 'stylist'; }
function currentClientId(): int  { return isClient()  ? currentPortalId() : 0; }
function currentStylistId(): int { return isStylist() ? currentPortalId() : 0; }

/** Guard a client page. Redirects to /login (remembering where they were). */
function requireClient(): void {
    portalResumeFromRemember();
    if (!isClient()) {
        $_SESSION['portal_after_login'] = $_SERVER['REQUEST_URI'] ?? '/account';
        header('Location: /login');
        exit;
    }
}
function requireStylist(): void {
    portalResumeFromRemember();
    if (!isStylist()) {
        $_SESSION['portal_after_login'] = $_SERVER['REQUEST_URI'] ?? '/portal';
        header('Location: /login');
        exit;
    }
}

/** Establish the logged-in session for an identity. Regenerates the id. */
function portalStartSession(array $identity): void {
    session_regenerate_id(true);
    $_SESSION['portal_user_id']     = (int)$identity['id'];
    $_SESSION['portal_user_type']   = (string)$identity['user_type'];
    $_SESSION['portal_active_role'] = (string)$identity['user_type'];
    $_SESSION['portal_user_name']   = (string)($identity['name'] ?? '');
    $_SESSION['portal_user_email']  = (string)($identity['email'] ?? '');
    unset($_SESSION['portal_pending']);

    $p = portalProvider((string)$identity['user_type']);
    if ($p) {
        try {
            getDB()->prepare("UPDATE {$p['table']} SET last_login_at = NOW(), login_attempts = 0, locked_until = NULL WHERE id = ?")
                   ->execute([(int)$identity['id']]);
        } catch (Throwable $e) { /* last_login_at is best-effort */ }
    }
}

// ── CSRF (separate key from admin's csrf_token) ───────────
function portalCsrf(): string {
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['portal_csrf'];
}
function verifyPortalCsrf(?string $token): bool {
    return !empty($_SESSION['portal_csrf']) && is_string($token)
        && hash_equals($_SESSION['portal_csrf'], $token);
}

// ============================================================
//  EMAILED CODE FLOW
// ============================================================

/**
 * Issue a login code for an email. Enumeration-resistant: the caller gets the
 * SAME result whether or not an account exists — a real account is emailed a
 * code; an unknown address is emailed a gentle "no account yet" note. Rate
 * limited per email. Returns ['ok'=>bool] where ok simply means "we handled it".
 */
function portalRequestCode(string $email): array {
    $db    = getDB();
    $email = strtolower(trim($email));
    if (!validateEmail($email)) return ['ok' => false, 'error' => 'Please enter a valid email address.'];

    // Rate limit: cap codes per email per window regardless of existence.
    // Interval quantities are trusted app constants, inlined (as ints) rather
    // than bound — some MySQL builds reject a placeholder inside INTERVAL.
    $win = (int)PORTAL_CODE_RATE_WINDOW;
    $rl = $db->prepare("SELECT COUNT(*) FROM portal_auth_codes WHERE email = ? AND created_at > (NOW() - INTERVAL $win SECOND)");
    $rl->execute([$email]);
    if ((int)$rl->fetchColumn() >= PORTAL_CODE_RATE_MAX) {
        // Silent success to the UI — never reveal the address is being probed.
        return ['ok' => true];
    }

    $identities = portalFindIdentities($db, $email);
    require_once __DIR__ . '/mailer.php';

    if (!$identities) {
        // Unknown / blocked — send the neutral email, create no code.
        try { emailPortalNoAccount($email); } catch (Throwable $e) { error_log('portal no-account email: ' . $e->getMessage()); }
        return ['ok' => true];
    }

    // If an email is both a client and (later) a stylist, a code is valid for
    // whichever role the verify step resolves — issue one code per identity.
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = portalHashCode($code);
    $ip   = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ttl  = (int)PORTAL_CODE_TTL_MINUTES;
    $ins  = $db->prepare("INSERT INTO portal_auth_codes (user_type, user_id, email, code_hash, expires_at, ip)
                          VALUES (?, ?, ?, ?, (NOW() + INTERVAL $ttl MINUTE), ?)");
    foreach ($identities as $idn) {
        // Invalidate any earlier live codes for this identity first (single active code).
        $db->prepare("UPDATE portal_auth_codes SET consumed_at = NOW()
                      WHERE email = ? AND user_type = ? AND consumed_at IS NULL")
           ->execute([$email, $idn['user_type']]);
        $ins->execute([$idn['user_type'], $idn['id'], $email, $hash, $ip]);
    }

    $name = $identities[0]['name'] ?? '';
    try { emailPortalLoginCode($email, $name, $code); }
    catch (Throwable $e) { error_log('portal login-code email: ' . $e->getMessage()); }

    return ['ok' => true];
}

/**
 * Verify an emailed code and, on success, log the user in. If the email maps to
 * more than one identity (client + stylist), returns the list so the caller can
 * present a role chooser; a single identity logs straight in.
 */
function portalVerifyCode(string $email, string $code): array {
    $db    = getDB();
    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];

    // Newest live code row for this email.
    $sel = $db->prepare("SELECT * FROM portal_auth_codes
                         WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW()
                         ORDER BY id DESC");
    $sel->execute([$email]);
    $rows = $sel->fetchAll();
    if (!$rows) return ['ok' => false, 'error' => 'That code has expired or is invalid. Request a new one.'];

    $hash    = portalHashCode($code);
    $matched = null;
    foreach ($rows as $row) {
        if (hash_equals($row['code_hash'], $hash)) { $matched = $row; break; }
    }

    if (!$matched) {
        // Wrong code — count the attempt against every live row for this email,
        // burning them once the cap is hit so a code can't be brute-forced.
        $db->prepare("UPDATE portal_auth_codes SET attempts = attempts + 1 WHERE email = ? AND consumed_at IS NULL")
           ->execute([$email]);
        $db->prepare("UPDATE portal_auth_codes SET consumed_at = NOW() WHERE email = ? AND consumed_at IS NULL AND attempts >= ?")
           ->execute([$email, PORTAL_CODE_MAX_ATTEMPTS]);
        return ['ok' => false, 'error' => 'That code is not correct. Check it and try again.'];
    }

    // Correct — consume every live code for this email, then resolve identities.
    $db->prepare("UPDATE portal_auth_codes SET consumed_at = NOW() WHERE email = ? AND consumed_at IS NULL")
       ->execute([$email]);

    $identities = portalFindIdentities($db, $email);
    if (!$identities) return ['ok' => false, 'error' => 'Account not found.'];

    if (count($identities) === 1) {
        portalStartSession($identities[0]);
        return ['ok' => true, 'multi' => false, 'identity' => $identities[0]];
    }
    // Multiple roles — stash them and let the caller show a chooser.
    $_SESSION['portal_pending'] = $identities;
    return ['ok' => true, 'multi' => true, 'identities' => $identities];
}

/** Complete login after a role chooser (email in more than one identity table). */
function portalChooseRole(string $userType): bool {
    $pending = $_SESSION['portal_pending'] ?? [];
    foreach ($pending as $idn) {
        if ($idn['user_type'] === $userType) {
            portalStartSession($idn);
            return true;
        }
    }
    return false;
}

// ============================================================
//  PASSWORD FLOW (optional, faster repeat login)
// ============================================================

/** Set/replace the current portal user's password. Requires an active session. */
function portalSetPassword(string $newPassword): array {
    if (!isPortalUser()) return ['ok' => false, 'error' => 'Please sign in first.'];
    if (strlen($newPassword) < 8) return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    $p = portalProvider((string)currentPortalType());
    if (!$p) return ['ok' => false, 'error' => 'Unavailable.'];
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    getDB()->prepare("UPDATE {$p['table']} SET password_hash = ? WHERE id = ?")
           ->execute([$hash, currentPortalId()]);
    return ['ok' => true];
}

/**
 * Log in with email + password. Enumeration-resistant and rate-limited per
 * identity via login_attempts/locked_until. On more than one identity, returns
 * a chooser exactly like the code flow.
 */
function portalPasswordLogin(string $email, string $password): array {
    $db    = getDB();
    $email = strtolower(trim($email));
    $identities = portalFindIdentities($db, $email);

    // Filter to identities that actually have a password set.
    $withPw = array_values(array_filter($identities, fn($i) => !empty($i['password_hash'])));
    if (!$withPw) {
        return ['ok' => false, 'error' => 'That email and password do not match.'];
    }

    $authed = [];
    foreach ($withPw as $idn) {
        $p = portalProvider($idn['user_type']);
        // Lockout check.
        $lk = $db->prepare("SELECT locked_until, login_attempts FROM {$p['table']} WHERE id = ?");
        $lk->execute([$idn['id']]);
        $st = $lk->fetch() ?: [];
        if (!empty($st['locked_until']) && strtotime($st['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again later, or sign in with an emailed code.'];
        }
        if (password_verify($password, $idn['password_hash'])) {
            $authed[] = $idn;
        } else {
            $attempts = (int)($st['login_attempts'] ?? 0) + 1;
            $locked   = null;
            if ($attempts >= PORTAL_PW_MAX_ATTEMPTS) {
                $locked = date('Y-m-d H:i:s', strtotime('+' . PORTAL_PW_LOCK_MINUTES . ' minutes'));
                $attempts = 0;
            }
            $db->prepare("UPDATE {$p['table']} SET login_attempts = ?, locked_until = ? WHERE id = ?")
               ->execute([$attempts, $locked, $idn['id']]);
        }
    }

    if (!$authed) return ['ok' => false, 'error' => 'That email and password do not match.'];
    if (count($authed) === 1) {
        portalStartSession($authed[0]);
        return ['ok' => true, 'multi' => false, 'identity' => $authed[0]];
    }
    $_SESSION['portal_pending'] = $authed;
    return ['ok' => true, 'multi' => true, 'identities' => $authed];
}

// ============================================================
//  REMEMBER ME (split-token)
// ============================================================

function portalIssueRemember(string $userType, int $userId): void {
    $db        = getDB();
    $selector  = bin2hex(random_bytes(16));   // 32 hex
    $validator = bin2hex(random_bytes(32));   // 64 hex
    $days = (int)PORTAL_REMEMBER_DAYS;
    $db->prepare("INSERT INTO portal_remember_tokens (user_type, user_id, selector, validator_hash, expires_at)
                  VALUES (?, ?, ?, ?, (NOW() + INTERVAL $days DAY))")
       ->execute([$userType, $userId, $selector, hash('sha256', $validator)]);

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(PORTAL_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + PORTAL_REMEMBER_DAYS * 86400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** If there is no session but a valid remember cookie, resume the session and rotate the token. */
function portalResumeFromRemember(): void {
    if (isPortalUser()) return;
    $raw = $_COOKIE[PORTAL_REMEMBER_COOKIE] ?? '';
    if (!str_contains($raw, ':')) return;
    [$selector, $validator] = explode(':', $raw, 2);
    if ($selector === '' || $validator === '') return;

    $db = getDB();
    $s  = $db->prepare("SELECT * FROM portal_remember_tokens WHERE selector = ? LIMIT 1");
    $s->execute([$selector]);
    $tok = $s->fetch();
    if (!$tok) return;

    if (strtotime($tok['expires_at']) < time() || !hash_equals($tok['validator_hash'], hash('sha256', $validator))) {
        // Expired or tampered — revoke every token for this user (possible theft).
        $db->prepare("DELETE FROM portal_remember_tokens WHERE user_type = ? AND user_id = ?")
           ->execute([$tok['user_type'], $tok['user_id']]);
        portalClearRememberCookie();
        return;
    }

    // Load the identity fresh and resume.
    $p = portalProvider($tok['user_type']);
    if (!$p) return;
    // Re-apply the provider gate: a stylist deactivated since the token was
    // issued must not resume from a stale remember cookie.
    $rwhere = 'id = ?';
    if (!empty($p['filter_sql'])) $rwhere .= ' AND ' . $p['filter_sql'];
    $u = $db->prepare("SELECT id, {$p['name_field']} AS name, email FROM {$p['table']} WHERE {$rwhere} LIMIT 1");
    $u->execute([(int)$tok['user_id']]);
    $row = $u->fetch();
    if (!$row) { portalClearRememberCookie(); return; }

    portalStartSession([
        'user_type' => $tok['user_type'],
        'id'        => (int)$row['id'],
        'name'      => $row['name'],
        'email'     => $row['email'],
    ]);

    // Rotate: same selector, new validator (limits the window of a stolen cookie).
    $newValidator = bin2hex(random_bytes(32));
    $rdays = (int)PORTAL_REMEMBER_DAYS;
    $db->prepare("UPDATE portal_remember_tokens SET validator_hash = ?, expires_at = (NOW() + INTERVAL $rdays DAY) WHERE id = ?")
       ->execute([hash('sha256', $newValidator), (int)$tok['id']]);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(PORTAL_REMEMBER_COOKIE, $selector . ':' . $newValidator, [
        'expires'  => time() + PORTAL_REMEMBER_DAYS * 86400,
        'path'     => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function portalClearRememberCookie(): void {
    setcookie(PORTAL_REMEMBER_COOKIE, '', [
        'expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// ── Logout ────────────────────────────────────────────────
function portalLogout(): void {
    // Drop this browser's remember token (if any) and clear the cookie.
    $raw = $_COOKIE[PORTAL_REMEMBER_COOKIE] ?? '';
    if (str_contains($raw, ':')) {
        [$selector] = explode(':', $raw, 2);
        try { getDB()->prepare("DELETE FROM portal_remember_tokens WHERE selector = ?")->execute([$selector]); }
        catch (Throwable $e) { /* ignore */ }
    }
    portalClearRememberCookie();

    // Clear only the portal keys — an admin sharing this browser stays logged in.
    foreach (['portal_user_id','portal_user_type','portal_active_role','portal_user_name','portal_user_email','portal_pending','portal_after_login','portal_csrf'] as $k) {
        unset($_SESSION[$k]);
    }
}

// ── Cleanup (called from cron) ────────────────────────────
function portalAuthCleanup(PDO $db): void {
    $db->exec("DELETE FROM portal_auth_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)");
    $db->exec("DELETE FROM portal_remember_tokens WHERE expires_at < NOW()");
}
