<?php
// ============================================================
// BraidedbyAGB — Admin Shared Layout
// FILE: /admin/includes/layout.php
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

requireAdmin();

// ── Current page detection (works under URL rewriting) ───
$uri         = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$uriParts    = explode('/', $uri);
// /admin → 'index', /admin/bookings → 'bookings', /admin/bookings/5 → 'bookings'
$currentPage = isset($uriParts[1]) && $uriParts[1] !== '' ? $uriParts[1] : 'index';

// ── DB with graceful error page ───────────────────────────
try {
    $db = getDB();
} catch (PDOException $e) {
    error_log('Admin DB error: ' . $e->getMessage());
    // Show a clean error rather than a blank page
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Database Error — BraidedbyAGB Admin</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0f0020;color:#fff}
    .box{background:#1a003a;border:1px solid #800080;border-radius:12px;padding:40px;max-width:480px;text-align:center}
    h1{color:#D4AF37;font-size:1.4rem;margin-bottom:12px}p{color:rgba(255,255,255,0.7);font-size:0.9rem;line-height:1.6}
    code{background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:4px;font-size:0.85rem}
    </style></head><body><div class="box">
    <h1>⚠️ Database Connection Error</h1>
    <p>The admin portal cannot connect to the database.</p>
    <p>Please check that <code>DB_PASS</code> is set correctly in <code>config/database.php</code>.</p>
    <p style="margin-top:20px;font-size:0.75rem;opacity:0.5">Check cPanel → MySQL Databases to verify your database user and password.</p>
    </div></body></html>';
    exit;
}

// ── Sidebar badge counts ──────────────────────────────────
try {
    $pendingBookings = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
    $pendingOrders   = (int)$db->query("SELECT COUNT(*) FROM orders   WHERE status='pending'")->fetchColumn();
    $pendingReviews  = (int)$db->query("SELECT COUNT(*) FROM reviews  WHERE status='pending'")->fetchColumn();
    $pendingCustomReq = (int)$db->query("SELECT COUNT(*) FROM custom_requests WHERE status='new'")->fetchColumn();
    $lowStock        = (int)$db->query("SELECT COUNT(DISTINCT product_id) FROM product_variants WHERE stock_qty <= low_stock_alert AND stock_qty > 0")->fetchColumn();
    // Live chat unread count (table may not exist yet — silently skip)
    try {
        $unreadChats = (int)$db->query("SELECT COALESCE(SUM(unread_admin),0) FROM chat_sessions WHERE status='active'")->fetchColumn();
    } catch (Exception $ce) { $unreadChats = 0; }
} catch (Exception $e) {
    $pendingBookings = $pendingOrders = $pendingReviews = $lowStock = $unreadChats = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — BraidedbyAGB Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/admin.css">
  <?php include __DIR__ . '/../../includes/brand-styles.php'; ?>
  <meta name="robots" content="noindex,nofollow">
</head>
<body class="admin-body">

<div class="admin-layout">
<div class="admin-sidebar-overlay" id="sidebarOverlay"></div>

  <!-- ══════════════════════════════════════ -->
  <!-- SIDEBAR                               -->
  <!-- ══════════════════════════════════════ -->
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-header">
      <a href="/admin" class="admin-logo">
        <span class="admin-logo-icon">✦</span>
        <span class="admin-logo-text">BraidedbyAGB</span>
      </a>
      <span class="admin-logo-sub">Admin Portal</span>
    </div>

    <nav class="admin-nav">
      <div class="admin-nav-section">
        <p class="admin-nav-label">Overview</p>
        <a href="/admin" class="admin-nav-link <?= $currentPage === 'index' ? 'active' : '' ?>">
          <span class="nav-icon">◈</span> Dashboard
        </a>
      </div>

      <div class="admin-nav-section">
        <p class="admin-nav-label">Appointments</p>
        <a href="/admin/bookings" class="admin-nav-link <?= $currentPage === 'bookings' ? 'active' : '' ?>">
          <span class="nav-icon">📅</span> Bookings
          <?php if ($pendingBookings > 0): ?>
            <span class="admin-badge"><?= $pendingBookings ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/calendar" class="admin-nav-link <?= $currentPage === 'calendar' ? 'active' : '' ?>">
          <span class="nav-icon">🗓</span> Calendar
        </a>
        <a href="/admin/services" class="admin-nav-link <?= $currentPage === 'services' ? 'active' : '' ?>">
          <span class="nav-icon">✂️</span> Services & Prices
        </a>
        </a>
        <a href="/admin/addons" class="admin-nav-link <?= $currentPage === 'addons' ? 'active' : '' ?>">
          <span class="nav-icon">✦</span> Add-ons Manager
      </div>

      <div class="admin-nav-section">
        <p class="admin-nav-label">Shop</p>
        <a href="/admin/orders" class="admin-nav-link <?= $currentPage === 'orders' ? 'active' : '' ?>">
          <span class="nav-icon">📦</span> Orders
          <?php if ($pendingOrders > 0): ?>
            <span class="admin-badge"><?= $pendingOrders ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/products" class="admin-nav-link <?= $currentPage === 'products' ? 'active' : '' ?>">
          <span class="nav-icon">🛍️</span> Products
          <?php if ($lowStock > 0): ?>
            <span class="admin-badge admin-badge-warning"><?= $lowStock ?> low</span>
          <?php endif; ?>
        </a>
        <a href="/admin/pipeline" class="admin-nav-link <?= $currentPage === 'pipeline' ? 'active' : '' ?>">
          <span class="nav-icon">🔗</span> Pipeline
        </a>
      </div>

      <div class="admin-nav-section">
        <p class="admin-nav-label">Clients</p>
        <a href="/admin/livechat" class="admin-nav-link <?= $currentPage === 'livechat' ? 'active' : '' ?>">
          <span class="nav-icon">💬</span> Live Chat
          <?php if ($unreadChats > 0): ?>
            <span class="admin-badge" style="background:#dc2626"><?= $unreadChats ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/customers" class="admin-nav-link <?= $currentPage === 'customers' ? 'active' : '' ?>">
          <span class="nav-icon">👥</span> Customers
        </a>
        <a href="/admin/reviews" class="admin-nav-link <?= $currentPage === 'reviews' ? 'active' : '' ?>">
          <span class="nav-icon">⭐</span> Reviews
          <?php if ($pendingReviews > 0): ?>
            <span class="admin-badge"><?= $pendingReviews ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/custom-requests" class="admin-nav-link <?= $currentPage === 'custom-requests' ? 'active' : '' ?>">
          <span class="nav-icon">✨</span> Custom Requests
          <?php if ($pendingCustomReq > 0): ?>
            <span class="admin-badge" style="background:var(--admin-warning)"><?= $pendingCustomReq ?></span>
          <?php endif; ?>
        </a>
        <a href="/admin/discounts" class="admin-nav-link <?= $currentPage === 'discounts' ? 'active' : '' ?>">
          <span class="nav-icon">🏷️</span> Discount Codes
        </a>
      </div>

      <div class="admin-nav-section">
        <p class="admin-nav-label">Finance</p>
        <a href="/admin/accounting" class="admin-nav-link <?= $currentPage === 'accounting' ? 'active' : '' ?>">
          <span class="nav-icon">💰</span> Accounting
        </a>
      </div>

      <div class="admin-nav-section">
        <p class="admin-nav-label">System</p>
        <a href="/admin/settings" class="admin-nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">
          <span class="nav-icon">⚙️</span> Settings
        </a>
        <a href="/" target="_blank" class="admin-nav-link">
          <span class="nav-icon">🌐</span> View Website ↗
        </a>
        <a href="/admin/logout" class="admin-nav-link admin-nav-logout">
          <span class="nav-icon">→</span> Sign Out
        </a>
      </div>
    </nav>
  </aside>

  <!-- ══════════════════════════════════════ -->
  <!-- MAIN CONTENT                          -->
  <!-- ══════════════════════════════════════ -->
  <div class="admin-main">

    <!-- Top bar -->
    <header class="admin-topbar">
      <button class="admin-sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">☰</button>
      <h1 class="admin-page-title"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
      <div class="admin-topbar-right">
        <?php if ($pendingBookings > 0): ?>
          <a href="/admin/bookings" class="admin-alert-chip">
            <?= $pendingBookings ?> pending booking<?= $pendingBookings !== 1 ? 's' : '' ?>
          </a>
        <?php endif; ?>
        <span class="admin-topbar-user"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></span>
      </div>
    </header>

    <!-- Page content starts here -->
    <div class="admin-content">
