<?php
// ============================================================
// BraidedbyAGB — Stylist portal dashboard
// FILE: /public/portal/index.php  →  /portal
//
// The stylist counterpart to the client /account dashboard. Reuses the same
// account-* layout + CSS. A stylist sees their own schedule and earnings only;
// they never see the admin panel and cannot pick or reassign work.
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireStylist();
$db  = getDB();
$sid = currentStylistId();

$stylist = $db->prepare("SELECT * FROM stylists WHERE id = ?");
$stylist->execute([$sid]);
$stylist = $stylist->fetch();
if (!$stylist) { portalLogout(); header('Location: /login'); exit; }

// ── Money summary ─────────────────────────────────────────
// Owed = earned but not yet in a payout. Projected = pending (booking not
// completed yet). Paid = lifetime payouts.
$owed = $db->prepare("SELECT COALESCE(SUM(earnings_amount),0) FROM booking_assignments
                      WHERE stylist_id = ? AND earnings_status = 'earned' AND payout_id IS NULL");
$owed->execute([$sid]);
$owedTotal = (float)$owed->fetchColumn();

$proj = $db->prepare("SELECT COALESCE(SUM(earnings_amount),0) FROM booking_assignments
                      WHERE stylist_id = ? AND earnings_status = 'pending'");
$proj->execute([$sid]);
$projTotal = (float)$proj->fetchColumn();

$paid = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM stylist_payouts WHERE stylist_id = ?");
$paid->execute([$sid]);
$paidTotal = (float)$paid->fetchColumn();

// ── Upcoming schedule ─────────────────────────────────────
$up = $db->prepare("
    SELECT ba.assign_role, ba.pay_model, ba.earnings_amount, ba.earnings_status,
           b.booked_date, b.booked_time, b.booking_ref, b.status AS booking_status,
           b.guest_name,
           COALESCE(NULLIF(b.custom_style_name,''), s.name) AS service_name,
           c.name AS client_name
    FROM booking_assignments ba
    JOIN bookings  b ON b.id = ba.booking_id
    JOIN services  s ON s.id = b.service_id
    JOIN customers c ON c.id = b.customer_id
    WHERE ba.stylist_id = ? AND b.booked_date >= CURDATE()
      AND b.status IN ('pending','confirmed') AND ba.earnings_status <> 'void'
    ORDER BY b.booked_date, b.booked_time
    LIMIT 12
");
$up->execute([$sid]);
$upcoming = $up->fetchAll();

function portalPill(string $status): string {
    $known = ['completed','confirmed','pending','incomplete','cancelled'];
    $cls = in_array($status, $known, true) ? $status : 'pending';
    return 'account-pill pill-' . $cls;
}
/** First name only — clients aren't the stylist's to manage. */
function firstNameOnly(?string $name): string {
    return htmlspecialchars(trim(explode(' ', (string)$name)[0]) ?: '');
}

$firstName = trim(explode(' ', (string)$stylist['name'])[0]) ?: 'there';
$pageTitle = 'Stylist Portal';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">

  <div class="account-head">
    <h1>Hi <?= htmlspecialchars($firstName) ?> ✂️</h1>
    <p>Your schedule and earnings at BraidedbyAGB.</p>
  </div>

  <?php $activeTab = '/portal'; require __DIR__ . '/../../includes/portal-tabs.php'; ?>

  <div class="account-grid" style="margin-bottom:30px;">
    <div class="account-card">
      <h3>Owed to you</h3>
      <div class="account-stat"><?= formatPrice($owedTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">earned, awaiting payout</p>
    </div>
    <div class="account-card">
      <h3>Projected</h3>
      <div class="account-stat"><?= formatPrice($projTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">from upcoming work</p>
    </div>
    <div class="account-card">
      <h3>Paid to date</h3>
      <div class="account-stat"><?= formatPrice($paidTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">lifetime payouts</p>
    </div>
    <div class="account-card" style="display:flex;flex-direction:column;justify-content:center;">
      <a href="/portal/earnings" class="auth-btn" style="text-decoration:none;text-align:center;margin:0;">View earnings</a>
    </div>
  </div>

  <h2 style="font-family:'Montserrat',sans-serif;color:var(--color-deep-purple,#7A0050);font-size:1.15rem;margin:0 0 14px;">Upcoming appointments</h2>
  <?php if (!$upcoming): ?>
    <div class="account-card account-empty">No upcoming appointments assigned to you yet.</div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($upcoming as $b):
        $date = date('D j M Y', strtotime($b['booked_date']));
        $time = substr((string)$b['booked_time'], 0, 5);
        $who  = $b['guest_name'] ? firstNameOnly($b['guest_name']) : firstNameOnly($b['client_name']); ?>
        <div class="account-row">
          <div>
            <div class="title"><?= htmlspecialchars($b['service_name']) ?>
              <?php if ($b['assign_role'] === 'assist'): ?><span style="font-size:0.72rem;color:#8A7595">· assisting</span><?php endif; ?>
            </div>
            <div class="meta"><?= $date ?> · <?= $time ?> · <?= $who ?> · <?= htmlspecialchars($b['booking_ref']) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-weight:700;color:#7A0050"><?= formatPrice($b['earnings_amount']) ?></div>
            <span class="<?= portalPill($b['booking_status']) ?>"><?= htmlspecialchars($b['booking_status']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
