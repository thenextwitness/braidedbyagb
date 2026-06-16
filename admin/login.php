<?php
// ============================================================
// BraidedbyAGB — Admin Login
// FILE: /admin/login.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

// Already logged in → dashboard
if (isAdmin()) {
    header('Location: /admin');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $result   = adminLogin($email, $password);

    if ($result['success'] === true) {
        // POST-redirect-GET pattern — prevents resubmission on refresh
        header('Location: /admin');
        exit;
    }
    $error = $result['message'] ?? 'Login failed.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700;800;900&family=Lato:wght@300;400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/admin.css">
  <meta name="robots" content="noindex,nofollow">
  <style>
    body.admin-body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(135deg, #0f0020 0%, #1a003a 50%, #2a0050 100%);
    }
    .login-card {
      background: #fff;
      border-radius: 16px;
      padding: 40px;
      width: 100%;
      max-width: 380px;
      box-shadow: 0 24px 48px rgba(0,0,0,0.4);
    }
    .login-logo { text-align: center; margin-bottom: 32px; }
    .login-logo-text {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.4rem;
      font-weight: 900;
      color: #4B0082;
      letter-spacing: 0.02em;
      display: block;
    }
    .login-logo-sub {
      font-family: 'Montserrat', sans-serif;
      font-size: 0.6rem;
      font-weight: 700;
      letter-spacing: 0.25em;
      text-transform: uppercase;
      color: #D4AF37;
      display: block;
      margin-top: 4px;
    }
    .login-title {
      font-family: 'Montserrat', sans-serif;
      font-size: 0.9rem;
      font-weight: 800;
      color: #4B0082;
      margin-bottom: 24px;
    }
    .login-error {
      background: #fee2e2;
      border: 1px solid #fca5a5;
      color: #991b1b;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 0.8rem;
      margin-bottom: 20px;
    }
    .login-footer {
      text-align: center;
      margin-top: 20px;
      font-size: 0.72rem;
      color: #9b8ba5;
    }
    .login-footer a { color: #800080; }
  </style>
</head>
<body class="admin-body">
<div class="login-card">
  <div class="login-logo">
    <span class="login-logo-text">BraidedbyAGB</span>
    <span class="login-logo-sub">Admin Portal</span>
  </div>
  <h2 class="login-title">Sign In</h2>

  <?php if ($error): ?>
    <div class="login-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/admin/login">
    <div class="admin-form-group">
      <label class="admin-label" for="email">Email Address</label>
      <input class="admin-input" type="email" id="email" name="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             autocomplete="email" required autofocus
             placeholder="hello@braidedbyagb.co.uk">
    </div>
    <div class="admin-form-group" style="margin-top:14px">
      <label class="admin-label" for="password">Password</label>
      <input class="admin-input" type="password" id="password" name="password"
             autocomplete="current-password" required>
    </div>
    <button type="submit"
            class="btn-admin btn-admin-primary"
            style="width:100%;justify-content:center;padding:10px;margin-top:20px;font-size:0.82rem">
      Sign In →
    </button>
  </form>
  <p class="login-footer"><a href="/">← Back to website</a></p>
</div>
</body>
</html>
