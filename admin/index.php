<?php
// ============================================================
// BraidedbyAGB — Admin Dashboard
// FILE: /admin/index.php
// ============================================================
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/layout.php';

// ── Stats (safe defaults if DB is empty) ──────────────────
try {
    $totalRevenue    = $db->query("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed'")->fetchColumn();
    $revenueThisMonth= $db->query("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed' AND MONTH(booked_date)=MONTH(CURDATE()) AND YEAR(booked_date)=YEAR(CURDATE())")->fetchColumn();
    $totalBookings   = $db->query("SELECT COUNT(*) FROM bookings WHERE status != 'cancelled'")->fetchColumn();
    $bookingsThisMonth=$db->query("SELECT COUNT(*) FROM bookings WHERE status != 'cancelled' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
    $totalOrders     = $db->query("SELECT COUNT(*) FROM orders WHERE status != 'cancelled'")->fetchColumn();
    $ordersRevenue   = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='paid'")->fetchColumn();
    $totalCustomers  = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $newCustomers    = $db->query("SELECT COUNT(*) FROM customers WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
    $avgRating       = $db->query("SELECT ROUND(AVG(rating),1) FROM reviews WHERE status='approved'")->fetchColumn();
    $totalReviews    = $db->query("SELECT COUNT(*) FROM reviews WHERE status='approved'")->fetchColumn();
    $todayBookings   = $db->query("SELECT b.*, c.name as c_name, s.name as s_name FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN services s ON s.id=b.service_id WHERE b.booked_date=CURDATE() AND b.status!='cancelled' ORDER BY b.booked_time ASC")->fetchAll();
    $upcomingBookings= $db->query("SELECT b.*, c.name as c_name, s.name as s_name FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN services s ON s.id=b.service_id WHERE b.booked_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND b.status IN ('pending','confirmed') ORDER BY b.booked_date ASC, b.booked_time ASC LIMIT 8")->fetchAll();
    $recentOrders    = $db->query("SELECT o.*, c.name as c_name FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.created_at DESC LIMIT 6")->fetchAll();
    $revChart        = $db->query("SELECT DATE_FORMAT(booked_date,'%b') as month, DATE_FORMAT(booked_date,'%Y-%m') as ym, COALESCE(SUM(total_price),0) as revenue FROM bookings WHERE booked_date>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH) AND status='completed' GROUP BY ym,month ORDER BY ym ASC")->fetchAll();
    $totalWithPipeline  = $db->query("SELECT COUNT(DISTINCT booking_id) FROM orders WHERE from_pipeline=1 AND booking_id IS NOT NULL")->fetchColumn();
    $totalBookingsCount = $db->query("SELECT COUNT(*) FROM bookings WHERE status!='cancelled' AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn();
    $pipelineRate = $totalBookingsCount > 0 ? round(($totalWithPipeline / max($totalBookingsCount,1)) * 100) : 0;
} catch (Exception $e) {
    $totalRevenue = $revenueThisMonth = $totalBookings = $bookingsThisMonth = 0;
    $totalOrders = $ordersRevenue = $totalCustomers = $newCustomers = 0;
    $avgRating = $totalReviews = $pipelineRate = $totalWithPipeline = 0;
    $todayBookings = $upcomingBookings = $recentOrders = $revChart = [];
}
?>

<!-- ── Stat Cards ───────────────────────────────────────── -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon purple">💜</div>
    <div class="stat-body">
      <div class="stat-value">£<?= number_format((float)$totalRevenue + (float)$ordersRevenue, 0) ?></div>
      <div class="stat-label">Total Revenue</div>
      <div class="stat-change">£<?= number_format((float)$revenueThisMonth, 0) ?> this month</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold">📅</div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format((int)$totalBookings) ?></div>
      <div class="stat-label">Total Bookings</div>
      <div class="stat-change"><?= $bookingsThisMonth ?> this month</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">👥</div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format((int)$totalCustomers) ?></div>
      <div class="stat-label">Customers</div>
      <div class="stat-change"><?= $newCustomers ?> new this month</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange">⭐</div>
    <div class="stat-body">
      <div class="stat-value"><?= $avgRating ?: '—' ?></div>
      <div class="stat-label">Average Rating</div>
      <div class="stat-change"><?= $totalReviews ?> verified reviews</div>
    </div>
  </div>
</div>

<!-- ── Action Alerts ────────────────────────────────────── -->
<?php if ($pendingBookings || $pendingOrders || $pendingReviews || $lowStock): ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
  <?php if ($pendingBookings): ?>
    <a href="/admin/bookings?status=pending" class="btn-admin btn-admin-warning">
      📅 <?= $pendingBookings ?> pending booking<?= $pendingBookings != 1 ? 's' : '' ?>
    </a>
  <?php endif; ?>
  <?php if ($pendingOrders): ?>
    <a href="/admin/orders?status=pending" class="btn-admin btn-admin-warning">
      📦 <?= $pendingOrders ?> pending order<?= $pendingOrders != 1 ? 's' : '' ?>
    </a>
  <?php endif; ?>
  <?php if ($pendingReviews): ?>
    <a href="/admin/reviews?status=pending" class="btn-admin btn-admin-outline">
      ⭐ <?= $pendingReviews ?> review<?= $pendingReviews != 1 ? 's' : '' ?> to approve
    </a>
  <?php endif; ?>
  <?php if ($lowStock): ?>
    <a href="/admin/products?filter=low_stock" class="btn-admin btn-admin-danger">
      ⚠️ <?= $lowStock ?> product<?= $lowStock != 1 ? 's' : '' ?> low stock
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Dashboard Grid ────────────────────────────────────── -->
<div class="dashboard-grid" style="margin-bottom:20px">

  <!-- Today's appointments -->
  <div class="dashboard-card">
    <div class="dashboard-card-header">
      <span class="dashboard-card-title">📅 Today's Appointments</span>
      <a href="/admin/bookings" class="btn-admin btn-admin-outline btn-admin-sm">View all</a>
    </div>
    <div class="dashboard-card-body">
      <?php if (empty($todayBookings)): ?>
        <div class="table-empty"><div class="table-empty-icon">🗓</div><p>No appointments today</p></div>
      <?php else: ?>
        <?php foreach ($todayBookings as $bk): ?>
          <a href="/admin/bookings/<?= $bk['id'] ?>" class="mini-row" style="text-decoration:none;color:inherit">
            <div class="mini-row-name"><?= htmlspecialchars($bk['c_name']) ?></div>
            <div style="font-size:0.72rem;color:var(--admin-muted)"><?= htmlspecialchars($bk['s_name']) ?></div>
            <div class="mini-row-time"><?= formatTime($bk['booked_time']) ?></div>
            <span class="status-badge status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span>
            <div class="mini-row-price"><?= formatPrice($bk['total_price']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Upcoming bookings -->
  <div class="dashboard-card">
    <div class="dashboard-card-header">
      <span class="dashboard-card-title">🔮 Upcoming (7 days)</span>
      <a href="/admin/calendar" class="btn-admin btn-admin-outline btn-admin-sm">Calendar</a>
    </div>
    <div class="dashboard-card-body">
      <?php if (empty($upcomingBookings)): ?>
        <div class="table-empty"><div class="table-empty-icon">📭</div><p>No upcoming bookings</p></div>
      <?php else: ?>
        <?php foreach ($upcomingBookings as $bk): ?>
          <a href="/admin/bookings/<?= $bk['id'] ?>" class="mini-row" style="text-decoration:none;color:inherit">
            <div class="mini-row-name"><?= htmlspecialchars($bk['c_name']) ?></div>
            <div class="mini-row-time" style="font-size:0.7rem"><?= formatDate($bk['booked_date'], 'D j M') ?> <?= formatTime($bk['booked_time']) ?></div>
            <span class="status-badge status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent orders -->
  <div class="dashboard-card">
    <div class="dashboard-card-header">
      <span class="dashboard-card-title">📦 Recent Orders</span>
      <a href="/admin/orders" class="btn-admin btn-admin-outline btn-admin-sm">View all</a>
    </div>
    <div class="dashboard-card-body">
      <?php if (empty($recentOrders)): ?>
        <div class="table-empty"><div class="table-empty-icon">🛍️</div><p>No orders yet</p></div>
      <?php else: ?>
        <?php foreach ($recentOrders as $ord): ?>
          <a href="/admin/orders/<?= $ord['id'] ?>" class="mini-row" style="text-decoration:none;color:inherit">
            <div class="mini-row-name"><?= htmlspecialchars($ord['c_name']) ?></div>
            <div class="mini-row-time" style="font-size:0.7rem"><?= date('j M', strtotime($ord['created_at'])) ?></div>
            <span class="status-badge status-<?= $ord['status'] ?>"><?= ucfirst($ord['status']) ?></span>
            <div class="mini-row-price"><?= formatPrice($ord['total']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pipeline & metrics -->
  <div class="dashboard-card">
    <div class="dashboard-card-header">
      <span class="dashboard-card-title">🔗 Pipeline Performance</span>
      <a href="/admin/pipeline" class="btn-admin btn-admin-outline btn-admin-sm">Manage</a>
    </div>
    <div class="dashboard-card-body" style="padding:20px">
      <div class="detail-row">
        <span class="dl">Pipeline conversion rate</span>
        <span class="dv"><?= $pipelineRate ?>%</span>
      </div>
      <div class="detail-row">
        <span class="dl">Bookings with add-on order</span>
        <span class="dv"><?= $totalWithPipeline ?></span>
      </div>
      <div class="detail-row">
        <span class="dl">Pending reviews</span>
        <span class="dv"><?= $pendingReviews ?></span>
      </div>
      <div class="detail-row">
        <span class="dl">Avg review rating</span>
        <span class="dv"><?= $avgRating ? $avgRating . ' ★' : '—' ?></span>
      </div>
      <div class="detail-row">
        <span class="dl">Low stock alerts</span>
        <span class="dv" style="color:<?= $lowStock ? 'var(--admin-warning)' : 'var(--admin-success)' ?>">
          <?= $lowStock ? $lowStock . ' products' : 'All good ✓' ?>
        </span>
      </div>
      <?php if (!empty($revChart)): ?>
      <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--admin-border)">
        <p style="font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">Revenue — last 6 months</p>
        <?php
        $maxRev = max(array_column($revChart, 'revenue')) ?: 1;
        foreach ($revChart as $r):
          $pct = round(($r['revenue'] / $maxRev) * 100);
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">
          <span style="font-size:0.68rem;color:var(--admin-muted);width:28px;flex-shrink:0"><?= $r['month'] ?></span>
          <div style="flex:1;background:#f3f0f8;border-radius:3px;height:8px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,var(--admin-primary),#c020c0);border-radius:3px"></div>
          </div>
          <span style="font-size:0.68rem;font-weight:700;color:var(--admin-text);width:44px;text-align:right">£<?= number_format($r['revenue'],0) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Quick actions -->
<div style="display:flex;gap:10px;flex-wrap:wrap;padding:16px 20px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);box-shadow:var(--admin-shadow)">
  <span style="font-family:'Montserrat',sans-serif;font-size:0.68rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:var(--admin-muted);align-self:center;margin-right:4px">Quick:</span>
  <a href="/admin/bookings/new" class="btn-admin btn-admin-primary">+ New Booking</a>
  <a href="/admin/products/new" class="btn-admin btn-admin-outline">+ Add Product</a>
  <a href="/admin/services"     class="btn-admin btn-admin-outline">Edit Prices</a>
  <a href="/admin/calendar"     class="btn-admin btn-admin-outline">Block Dates</a>
  <a href="/admin/discounts/new" class="btn-admin btn-admin-outline">+ Discount Code</a>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
