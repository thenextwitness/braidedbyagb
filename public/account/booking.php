<?php
// ============================================================
// BraidedbyAGB — Client booking detail
// FILE: /public/account/booking.php  →  /account/bookings/{ref}
// Read-only. Scoped to the signed-in client — a ref belonging to someone else
// returns not-found, never their data.
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();
$ref = sanitize($_GET['ref'] ?? '');

$bk = null;
if ($ref !== '') {
    $stmt = $db->prepare("
        SELECT b.*, s.name AS s_name, sv.variant_name
        FROM bookings b
        JOIN services s ON s.id = b.service_id
        LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE b.booking_ref = ? AND b.customer_id = ?
        LIMIT 1
    ");
    $stmt->execute([$ref, $cid]);
    $bk = $stmt->fetch();
}

function acctPill(string $status): string {
    $known = ['completed','confirmed','pending','incomplete','cancelled'];
    $cls = in_array($status, $known, true) ? $status : 'pending';
    return 'account-pill pill-' . $cls;
}

$pageTitle = 'Booking ' . ($bk['booking_ref'] ?? '');
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head"><h1>Booking details</h1><p><a class="auth-link" href="/account/bookings">&larr; All bookings</a></p></div>
  <?php $activeTab = '/account/bookings'; require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <?php if (!$bk): ?>
    <div class="account-card account-empty">We couldn't find that booking on your account.</div>
  <?php else:
    $date  = date('l j F Y', strtotime($bk['booked_date']));
    $time  = substr((string)$bk['booked_time'], 0, 5);
    $addons = $db->prepare("SELECT sa.name, ba.price_charged FROM booking_addons ba JOIN service_addons sa ON sa.id = ba.addon_id WHERE ba.booking_id = ?");
    $addons->execute([(int)$bk['id']]);
    $addonRows = $addons->fetchAll();
    $pays = $db->prepare("SELECT amount, type, method, confirmed_at, created_at FROM payments WHERE booking_id = ? AND status = 'succeeded' ORDER BY created_at");
    $pays->execute([(int)$bk['id']]);
    $payRows = $pays->fetchAll();
  ?>
    <div class="account-card" style="max-width:560px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div class="title" style="font-size:1.1rem;"><?= htmlspecialchars($bk['s_name']) ?><?= $bk['variant_name'] ? ' — ' . htmlspecialchars($bk['variant_name']) : '' ?></div>
        <span class="<?= acctPill($bk['status']) ?>"><?= htmlspecialchars($bk['status']) ?></span>
      </div>
      <div class="account-list">
        <div class="account-row"><span class="meta">Reference</span><span class="title"><?= htmlspecialchars($bk['booking_ref']) ?></span></div>
        <div class="account-row"><span class="meta">Date</span><span class="title"><?= $date ?></span></div>
        <div class="account-row"><span class="meta">Time</span><span class="title"><?= $time ?></span></div>
        <?php if (!empty($bk['service_location']) && $bk['service_location'] === 'home'): ?>
        <div class="account-row"><span class="meta">Location</span><span class="title">Home service</span></div>
        <?php endif; ?>
        <div class="account-row"><span class="meta">Total</span><span class="title"><?= formatPrice((float)$bk['total_price']) ?></span></div>
        <div class="account-row"><span class="meta">Deposit</span><span class="title"><?= (int)$bk['deposit_paid'] ? 'Paid ' . formatPrice((float)$bk['deposit_amount']) : formatPrice((float)$bk['deposit_amount']) . ' due' ?></span></div>
        <div class="account-row"><span class="meta">Balance on the day</span><span class="title"><?= formatPrice((float)$bk['remaining_balance']) ?></span></div>
      </div>

      <?php if ($addonRows): ?>
        <h3 style="margin:22px 0 10px;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#8A7595;">Add-ons</h3>
        <div class="account-list">
          <?php foreach ($addonRows as $a): ?>
            <div class="account-row"><span class="title"><?= htmlspecialchars($a['name']) ?></span><span class="meta"><?= formatPrice((float)$a['price_charged']) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($payRows): ?>
        <h3 style="margin:22px 0 10px;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#8A7595;">Payments</h3>
        <div class="account-list">
          <?php foreach ($payRows as $p):
            $when = date('j M Y', strtotime($p['confirmed_at'] ?: $p['created_at'])); ?>
            <div class="account-row">
              <span class="title"><?= formatPrice((float)$p['amount']) ?> · <?= htmlspecialchars(ucfirst((string)$p['type'])) ?></span>
              <span class="meta"><?= $when ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($bk['client_notes'])): ?>
        <h3 style="margin:22px 0 10px;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#8A7595;">Your notes</h3>
        <p style="color:#4A3A54;font-size:0.92rem;margin:0;"><?= nl2br(htmlspecialchars($bk['client_notes'])) ?></p>
      <?php endif; ?>

      <div style="margin-top:24px;">
        <a href="/booking" class="auth-btn" style="display:inline-block;text-decoration:none;">Book again</a>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
