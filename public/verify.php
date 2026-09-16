<?php
// ============================================================
// BraidedbyAGB — Verify emailed code / choose role
// FILE: /public/verify.php  →  /verify
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/portal-auth.php';

if (isPortalUser()) { header('Location: /app'); exit; }

function verifyTarget(): string {
    $t = $_SESSION['portal_after_login'] ?? '/app';
    unset($_SESSION['portal_after_login']);
    return (is_string($t) && str_starts_with($t, '/')) ? $t : '/app';
}
function maybeRemember(array $identity): void {
    if (!empty($_SESSION['portal_remember_opt'])) {
        portalIssueRemember($identity['user_type'], (int)$identity['id']);
        unset($_SESSION['portal_remember_opt']);
    }
}

$email    = $_SESSION['portal_login_email'] ?? '';
$error    = '';
$info     = '';
$showChooser = !empty($_SESSION['portal_pending']) || (($_GET['choose'] ?? '') === '1' && !empty($_SESSION['portal_pending']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyPortalCsrf($_POST['csrf'] ?? '')) {
        $error = 'Your session expired — please try again.';
    } else {
        $action = $_POST['action'] ?? 'verify';

        if ($action === 'choose') {
            $type = $_POST['role'] ?? '';
            if (portalChooseRole($type)) {
                $idn = ['user_type' => $type, 'id' => currentPortalId()];
                maybeRemember($idn);
                unset($_SESSION['portal_pending']);
                header('Location: ' . verifyTarget()); exit;
            }
            $error = 'Please choose which account to use.';
            $showChooser = true;
        } elseif ($action === 'resend') {
            if (validateEmail($email)) { portalRequestCode($email); $info = 'We\'ve sent a fresh code to your email.'; }
            else { $error = 'Please start again from the sign-in page.'; }
        } else { // verify
            $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
            $res  = portalVerifyCode($email, $code);
            if (!empty($res['ok'])) {
                if (!empty($res['multi'])) {
                    $showChooser = true;   // pending stored by portalVerifyCode
                } else {
                    maybeRemember($res['identity']);
                    header('Location: ' . verifyTarget()); exit;
                }
            } else {
                $error = $res['error'] ?? 'That code is invalid.';
            }
        }
    }
}

// No email in session → nothing to verify; send back to sign-in.
if (!$showChooser && !$email) { header('Location: /login'); exit; }

$pageTitle = $showChooser ? 'Choose account' : 'Enter your code';
$csrf = portalCsrf();
require_once __DIR__ . '/../includes/account-head.php';
?>
<div class="auth-shell">
  <div class="auth-card">
    <?php if ($showChooser):
      $pending = $_SESSION['portal_pending'] ?? []; ?>
      <h1>Choose your account</h1>
      <p class="auth-sub">This email is set up for more than one account. Which would you like to use?</p>
      <?php if ($error): ?><div class="auth-msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="POST" class="role-choice">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="choose">
        <?php foreach ($pending as $idn): ?>
          <button type="submit" name="role" value="<?= htmlspecialchars($idn['user_type']) ?>">
            <?= $idn['user_type'] === 'stylist' ? '✂️' : '💇' ?>
            <?= $idn['user_type'] === 'stylist' ? 'Stylist portal' : 'My client account' ?>
          </button>
        <?php endforeach; ?>
      </form>
    <?php else: ?>
      <h1>Enter your code</h1>
      <p class="auth-sub">We've emailed a 6-digit code to <strong><?= htmlspecialchars($email) ?></strong>. It expires in 10 minutes.</p>
      <?php if ($error): ?><div class="auth-msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($info):  ?><div class="auth-msg ok"><?= htmlspecialchars($info) ?></div><?php endif; ?>
      <form method="POST" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="verify">
        <div class="auth-field">
          <label for="code">6-digit code</label>
          <input class="auth-input auth-code-input" type="text" id="code" name="code"
                 inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                 required autofocus placeholder="••••••">
        </div>
        <button class="auth-btn" type="submit">Verify &amp; sign in</button>
      </form>
      <div class="auth-alt">
        Didn't get it?
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="resend">
          <button type="submit" class="auth-link" style="background:none;border:none;padding:0;font:inherit;">Send a new code</button>
        </form>
        · <a href="/login">Use a different email</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/account-foot.php'; ?>
