<?php
// ============================================================
// BraidedbyAGB — Client profile (details + optional password)
// FILE: /public/account/profile.php  →  /account/profile
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

$msg = ''; $msgType = 'ok'; $pwMsg = ''; $pwType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyPortalCsrf($_POST['csrf'] ?? '')) {
        $msg = 'Your session expired — please try again.'; $msgType = 'error';
    } elseif (($_POST['action'] ?? '') === 'update_profile') {
        $name  = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $optin = isset($_POST['email_optin']) ? 1 : 0;
        $a1 = sanitize($_POST['address_line1'] ?? '');
        $a2 = sanitize($_POST['address_line2'] ?? '');
        $ci = sanitize($_POST['address_city'] ?? '');
        $pc = sanitize($_POST['address_postcode'] ?? '');
        if ($name === '') { $msg = 'Please enter your name.'; $msgType = 'error'; }
        else {
            $db->prepare("UPDATE customers SET name=?, phone=?, email_optin=?, address_line1=?, address_line2=?, address_city=?, address_postcode=? WHERE id=?")
               ->execute([$name, $phone ?: null, $optin, $a1 ?: null, $a2 ?: null, $ci ?: null, $pc ?: null, $cid]);
            $_SESSION['portal_user_name'] = $name;
            $msg = 'Your details have been saved.';
        }
    } elseif (($_POST['action'] ?? '') === 'set_password') {
        $new = (string)($_POST['new_password'] ?? '');
        $res = portalSetPassword($new);
        if (!empty($res['ok'])) {
            $pwMsg = 'Password saved. You can now sign in with it.';
            try {
                require_once __DIR__ . '/../../includes/mailer.php';
                $c = $db->prepare("SELECT name, email FROM customers WHERE id=?"); $c->execute([$cid]); $c = $c->fetch();
                if ($c) emailPortalPasswordChanged($c['email'], $c['name']);
            } catch (Throwable $e) { error_log('password-changed email: ' . $e->getMessage()); }
        } else { $pwMsg = $res['error'] ?? 'Could not set the password.'; $pwType = 'error'; }
    }
}

$cust = $db->prepare("SELECT * FROM customers WHERE id=?");
$cust->execute([$cid]);
$cust = $cust->fetch();
$hasPassword = !empty($cust['password_hash']);

$pageTitle = 'My Profile';
$csrf = portalCsrf();
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head"><h1>My profile</h1><p>Keep your details up to date so booking is quicker next time.</p></div>

  <nav class="account-tabs">
    <a class="account-tab" href="/account">Dashboard</a>
    <a class="account-tab" href="/account/bookings">Bookings</a>
    <a class="account-tab active" href="/account/profile">Profile</a>
    <a class="account-tab" href="/logout">Sign out</a>
  </nav>

  <div class="account-grid" style="grid-template-columns:1fr;gap:20px;max-width:560px;">
    <div class="account-card">
      <h3 style="margin-bottom:16px;">Your details</h3>
      <?php if ($msg): ?><div class="auth-msg <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="update_profile">
        <div class="auth-field">
          <label>Email</label>
          <input class="auth-input" type="email" value="<?= htmlspecialchars($cust['email']) ?>" readonly
                 style="background:#F3EEF6;color:#8A7595;">
        </div>
        <div class="auth-field">
          <label for="name">Name</label>
          <input class="auth-input" id="name" name="name" value="<?= htmlspecialchars($cust['name']) ?>" required>
        </div>
        <div class="auth-field">
          <label for="phone">Phone</label>
          <input class="auth-input" id="phone" name="phone" value="<?= htmlspecialchars($cust['phone'] ?? '') ?>" autocomplete="tel">
        </div>
        <div class="auth-field">
          <label for="address_line1">Address line 1</label>
          <input class="auth-input" id="address_line1" name="address_line1" value="<?= htmlspecialchars($cust['address_line1'] ?? '') ?>" autocomplete="address-line1">
        </div>
        <div class="auth-field">
          <label for="address_line2">Address line 2</label>
          <input class="auth-input" id="address_line2" name="address_line2" value="<?= htmlspecialchars($cust['address_line2'] ?? '') ?>" autocomplete="address-line2">
        </div>
        <div style="display:flex;gap:12px;">
          <div class="auth-field" style="flex:1;">
            <label for="address_city">Town / City</label>
            <input class="auth-input" id="address_city" name="address_city" value="<?= htmlspecialchars($cust['address_city'] ?? '') ?>" autocomplete="address-level2">
          </div>
          <div class="auth-field" style="width:130px;">
            <label for="address_postcode">Postcode</label>
            <input class="auth-input" id="address_postcode" name="address_postcode" value="<?= htmlspecialchars($cust['address_postcode'] ?? '') ?>" autocomplete="postal-code">
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;color:#6B5575;margin:6px 0 18px;">
          <input type="checkbox" name="email_optin" value="1" <?= (int)($cust['email_optin'] ?? 0) ? 'checked' : '' ?>>
          Email me offers and updates
        </label>
        <button class="auth-btn" type="submit">Save details</button>
      </form>
    </div>

    <div class="account-card">
      <h3 style="margin-bottom:6px;"><?= $hasPassword ? 'Change password' : 'Set a password' ?></h3>
      <p class="auth-sub" style="margin-bottom:16px;">Optional — you can always sign in with a one-time emailed code instead.</p>
      <?php if ($pwMsg): ?><div class="auth-msg <?= $pwType ?>"><?= htmlspecialchars($pwMsg) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="set_password">
        <div class="auth-field">
          <label for="new_password">New password</label>
          <input class="auth-input" type="password" id="new_password" name="new_password"
                 autocomplete="new-password" minlength="8" required placeholder="At least 8 characters">
        </div>
        <button class="auth-btn secondary" type="submit"><?= $hasPassword ? 'Update password' : 'Set password' ?></button>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
