<?php
// ============================================================
// BraidedbyAGB — Portal sign-in (clients now, stylists in Phase C)
// FILE: /public/login.php  →  /login
//
// Default path: enter email, receive a 6-digit code, verify on /verify.
// Optional: sign in with a password if one has been set. Enumeration-resistant
// — the response is identical whether or not an account exists.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/portal-auth.php';

// Already signed in (or resumable via remember-me)? Go straight to the app.
portalResumeFromRemember();
if (isPortalUser()) { header('Location: /app'); exit; }

$error = '';
$mode  = ($_GET['mode'] ?? '') === 'password' ? 'password' : 'code';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyPortalCsrf($_POST['csrf'] ?? '')) {
        $error = 'Your session expired — please try again.';
    } else {
        $email  = strtolower(trim($_POST['email'] ?? ''));
        $action = $_POST['action'] ?? 'code';

        if ($action === 'password') {
            $mode = 'password';
            $res  = portalPasswordLogin($email, (string)($_POST['password'] ?? ''));
            if (!empty($res['ok'])) {
                if (!empty($_POST['remember']) && !empty($res['identity'])) {
                    portalIssueRemember($res['identity']['user_type'], (int)$res['identity']['id']);
                }
                if (!empty($res['multi'])) { header('Location: /verify?choose=1'); exit; }
                header('Location: ' . portalAfterLoginTarget()); exit;
            }
            $error = $res['error'] ?? 'That email and password do not match.';
        } else {
            // Emailed-code path — always proceeds to /verify, even for an unknown
            // email, so the screen never reveals whether an account exists.
            if (!validateEmail($email)) {
                $error = 'Please enter a valid email address.';
            } else {
                portalRequestCode($email);
                $_SESSION['portal_login_email'] = $email;
                if (!empty($_POST['remember'])) $_SESSION['portal_remember_opt'] = 1;
                header('Location: /verify'); exit;
            }
        }
    }
}

/** Where to send a freshly-signed-in user. */
function portalAfterLoginTarget(): string {
    $t = $_SESSION['portal_after_login'] ?? '/app';
    unset($_SESSION['portal_after_login']);
    // Only allow same-site relative paths.
    return (is_string($t) && str_starts_with($t, '/')) ? $t : '/app';
}

$pageTitle = 'Sign in';
$csrf = portalCsrf();
require_once __DIR__ . '/../includes/account-head.php';
?>
<div class="auth-shell">
  <div class="auth-card">
    <h1>Sign in</h1>
    <p class="auth-sub">Access your bookings, order history and saved details.</p>

    <?php if ($error): ?><div class="auth-msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($mode === 'password'): ?>
      <!-- Password sign-in -->
      <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="password">
        <div class="auth-field">
          <label for="email">Email</label>
          <input class="auth-input" type="email" id="email" name="email" autocomplete="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        </div>
        <div class="auth-field">
          <label for="password">Password</label>
          <input class="auth-input" type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;color:#6B5575;margin-bottom:18px;">
          <input type="checkbox" name="remember" value="1" checked> Keep me signed in
        </label>
        <button class="auth-btn" type="submit">Sign in</button>
      </form>
      <div class="auth-alt">Prefer a one-time code? <a href="/login">Email me a code instead</a></div>
    <?php else: ?>
      <!-- Emailed-code sign-in (default) -->
      <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="code">
        <div class="auth-field">
          <label for="email">Email</label>
          <input class="auth-input" type="email" id="email" name="email" autocomplete="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus
                 placeholder="you@email.com">
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;color:#6B5575;margin-bottom:18px;">
          <input type="checkbox" name="remember" value="1" checked> Keep me signed in
        </label>
        <button class="auth-btn" type="submit">Email me a sign-in code</button>
      </form>
      <div class="auth-alt">Set up a password? <a href="/login?mode=password">Sign in with a password</a></div>
    <?php endif; ?>

    <p class="auth-sub" style="margin-top:26px;font-size:0.82rem;text-align:center;">
      Your account is created automatically when you book — there's nothing to register.
    </p>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/account-foot.php'; ?>
