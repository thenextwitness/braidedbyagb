<?php
// ============================================================
// BraidedbyAGB — Client bookings list
// FILE: /public/account/bookings.php  →  /account/bookings
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

$rows = $db->prepare("
    SELECT b.booking_ref, b.booked_date, b.booked_time, b.status, b.total_price,
           b.deposit_paid, b.remaining_balance, b.confirm_token,
           COALESCE(NULLIF(b.custom_style_name,''), s.name) AS service_name
    FROM bookings b
    JOIN services s ON s.id = b.service_id
    WHERE b.customer_id = ?
    ORDER BY b.booked_date DESC, b.booked_time DESC
");
$rows->execute([$cid]);
$bookings = $rows->fetchAll();

function acctPill(string $status): string {
    $known = ['completed','confirmed','pending','incomplete','cancelled'];
    $cls = in_array($status, $known, true) ? $status : 'pending';
    return 'account-pill pill-' . $cls;
}

$pageTitle = 'My Bookings';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head"><h1>My bookings</h1><p>Every appointment you've made with us.</p></div>

  <nav class="account-tabs">
    <a class="account-tab" href="/account">Dashboard</a>
    <a class="account-tab active" href="/account/bookings">Bookings</a>
    <a class="account-tab" href="/account/profile">Profile</a>
    <a class="account-tab" href="/logout">Sign out</a>
  </nav>

  <?php if (!$bookings): ?>
    <div class="account-card account-empty">No bookings yet. <a class="auth-link" href="/booking">Book your first appointment »</a></div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($bookings as $b):
        $date = date('D j M Y', strtotime($b['booked_date']));
        $time = substr((string)$b['booked_time'], 0, 5);
        $link = '/booking/confirmation?ref=' . urlencode($b['booking_ref']) . '&t=' . urlencode((string)$b['confirm_token']); ?>
        <a class="account-row" style="text-decoration:none" href="<?= htmlspecialchars($link) ?>">
          <div>
            <div class="title"><?= htmlspecialchars($b['service_name']) ?></div>
            <div class="meta">
              <?= $date ?> · <?= $time ?> · <?= htmlspecialchars($b['booking_ref']) ?>
              · <?= formatPrice((float)$b['total_price']) ?>
            </div>
          </div>
          <span class="<?= acctPill($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
