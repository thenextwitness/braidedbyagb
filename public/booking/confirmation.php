<?php
// ============================================================
// BraidedbyAGB — Booking Confirmation Page
// FILE: /public/booking/confirmation.php
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

$db  = getDB();
$ref = sanitize($_GET['ref'] ?? '');

// If the customer just returned from a Stripe payment (card confirmed inline, or
// a Klarna/Clearpay/PayPal redirect), finalize it now for instant confirmation.
// This is the fast path; the Stripe webhook is the authoritative backstop. Both
// call the same idempotent finalizer, so running here is safe even if the
// webhook already fired.
$piMeta = [];
if (!empty($_GET['payment_intent'])) {
    try {
        require_once __DIR__ . '/../../includes/stripe.php';
        $pi = stripeRetrievePaymentIntent(sanitize($_GET['payment_intent']));
        $piMeta = $pi['metadata'] ?? [];
        finalizeStripePayment($db, $pi, 'return_page');
    } catch (Throwable $e) {
        error_log('booking confirmation finalize error: ' . $e->getMessage());
    }
}
portalResumeFromRemember();

$booking = null;
if ($ref) {
    $stmt = $db->prepare("
        SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
               s.name as s_name, sv.variant_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services s  ON s.id = b.service_id
        LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE b.booking_ref = ?
        LIMIT 1
    ");
    $stmt->execute([$ref]);
    $booking = $stmt->fetch();
}
if (!$booking) {
    header('Location: /booking');
    exit;
}

// ── B6: authorize before showing personal data ────────────
// The page is reachable by booking_ref alone, so name/email are gated. A viewer
// sees personal details only if they own the booking (signed in), hold its
// confirm_token, or just paid for it (arrived from the Stripe return with a
// PaymentIntent whose metadata maps to this booking). Everyone else still gets
// a working acknowledgement — just without the personal fields.
$providedToken = (string)($_GET['t'] ?? '');
$ownsViaLogin  = isClient() && currentClientId() === (int)$booking['customer_id'];
$ownsViaToken  = !empty($booking['confirm_token']) && is_string($providedToken) && $providedToken !== ''
                 && hash_equals((string)$booking['confirm_token'], $providedToken);
$ownsViaPayment = (
    (!empty($piMeta['group_ref']) && !empty($booking['cart_group_ref']) && $piMeta['group_ref'] === $booking['cart_group_ref'])
 || (!empty($piMeta['booking_id']) && (int)$piMeta['booking_id'] === (int)$booking['id'])
);
$authorized = $ownsViaLogin || $ownsViaToken || $ownsViaPayment;

// Give a brand-new booking a confirm_token so its future account/email links can
// prove ownership without exposing anything.
if (empty($booking['confirm_token'])) {
    try {
        $newTok = bin2hex(random_bytes(32));
        $db->prepare("UPDATE bookings SET confirm_token = ? WHERE id = ?")->execute([$newTok, (int)$booking['id']]);
        $booking['confirm_token'] = $newTok;
    } catch (Throwable $e) { /* non-fatal */ }
}
// Hide the contactable PII (email, phone) from anyone not authorized. The
// first name is kept: it is the only name ever shown, it is needed for the
// bank-transfer reference, and it is far less sensitive than an email address.
if (!$authorized) {
    $booking['c_email'] = '';
    $booking['c_phone'] = '';
}
// Safe first name for greetings ('' when absent).
$greetName = trim((string)$booking['c_name']) !== '' ? htmlspecialchars(explode(' ', $booking['c_name'])[0]) : '';

// If this booking was part of a multi-appointment (family) checkout, load its
// siblings so we can acknowledge the whole group. Defensive: cart_group_ref may
// not be migrated on the live DB yet.
$groupBookings = [];
if (!empty($booking['cart_group_ref'])) {
    try {
        $gstmt = $db->prepare("
            SELECT b.booking_ref, b.guest_name, b.booked_date, b.booked_time,
                   b.total_price, b.deposit_amount,
                   s.name AS s_name, sv.variant_name
            FROM bookings b
            JOIN services s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE b.cart_group_ref = ?
            ORDER BY b.booked_date ASC, b.booked_time ASC
        ");
        $gstmt->execute([$booking['cart_group_ref']]);
        $groupBookings = $gstmt->fetchAll();
    } catch (Throwable $e) {
        $groupBookings = [];
    }
}
$isGroup = count($groupBookings) > 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="robots" content="noindex,nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Confirmed — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php include __DIR__ . '/../../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../../includes/nav.php'; ?>
<main class="page-content" style="background:var(--color-bg-light);min-height:100vh;padding:var(--space-20) 0">
  <div style="max-width:640px;margin:0 auto;padding:0 var(--space-6)">

    <!-- Success card -->
    <div style="background:var(--color-white);border-radius:var(--border-radius-xl);padding:var(--space-10);box-shadow:var(--shadow-xl);text-align:center;margin-bottom:var(--space-6)">
      <div style="width:72px;height:72px;background:linear-gradient(135deg,var(--color-primary),var(--color-deep-purple));border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-5);font-size:2rem">
        ✓
      </div>
      <h1 style="font-size:var(--text-3xl);color:var(--color-deep-purple);margin-bottom:var(--space-2)">
        <?= $booking['payment_method'] === 'stripe' ? 'Booking Request Received!' : 'Almost There!' ?>
      </h1>
      <?php if ($booking['payment_method'] === 'stripe'): ?>
        <p style="color:var(--color-text-muted);font-size:var(--text-md);line-height:1.7">
          Thank you<?= $greetName ? ', <strong>' . $greetName . '</strong>' : '' ?>! Your deposit has been paid and your booking is pending confirmation. We'll be in touch shortly.
        </p>
      <?php else: ?>
        <p style="color:var(--color-text-muted);font-size:var(--text-md);line-height:1.7">
          Thank you<?= $greetName ? ', <strong>' . $greetName . '</strong>' : '' ?>! Your booking is being held while we await your bank transfer. Please transfer your deposit within <strong>24 hours</strong> to confirm your appointment.
        </p>
      <?php endif; ?>
    </div>

    <?php if ($isGroup): ?>
    <!-- Group booking summary (family / multiple appointments) -->
    <div class="booking-summary" style="margin-bottom:var(--space-6)">
      <div class="booking-summary-header">Your <?= count($groupBookings) ?> Appointments</div>
      <div class="booking-summary-body">
        <?php
          $grpTotal = 0; $grpDeposit = 0;
          foreach ($groupBookings as $g):
            $grpTotal   += (float)$g['total_price'];
            $grpDeposit += (float)$g['deposit_amount'];
            $who = !empty($g['guest_name']) ? $g['guest_name'] : ($greetName ?: 'You');
        ?>
        <div class="summary-row" style="align-items:flex-start">
          <span class="label" style="flex:1">
            <strong><?= htmlspecialchars($who) ?></strong><br>
            <span style="font-size:var(--text-xs);color:var(--color-text-muted)">
              <?= htmlspecialchars($g['s_name']) ?><?= $g['variant_name'] ? ' — ' . htmlspecialchars($g['variant_name']) : '' ?><br>
              <?= formatDate($g['booked_date'], 'D j M') ?> · <?= formatTime($g['booked_time']) ?> · Ref <?= htmlspecialchars($g['booking_ref']) ?>
            </span>
          </span>
          <span class="value"><?= formatPrice((float)$g['total_price']) ?></span>
        </div>
        <?php endforeach; ?>
        <div class="summary-row total"><span class="label">Combined Total</span><span class="value"><?= formatPrice($grpTotal) ?></span></div>
        <div class="summary-row deposit"><span class="label">Deposit <?= $booking['deposit_paid'] ? 'Paid ✓' : 'Pending' ?></span><span class="value"><?= formatPrice($grpDeposit) ?></span></div>
        <div class="summary-row"><span class="label">Balance on Days</span><span class="value"><?= formatPrice($grpTotal - $grpDeposit) ?></span></div>
      </div>
    </div>
    <?php else: ?>
    <!-- Booking details -->
    <div class="booking-summary" style="margin-bottom:var(--space-6)">
      <div class="booking-summary-header">Your Booking Details</div>
      <div class="booking-summary-body">
        <div class="summary-row">
          <span class="label">Reference</span>
          <span class="value" style="font-family:var(--font-primary);letter-spacing:0.05em"><?= htmlspecialchars($booking['booking_ref']) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Service</span>
          <span class="value"><?= htmlspecialchars($booking['s_name']) ?><?= $booking['variant_name'] ? ' — ' . htmlspecialchars($booking['variant_name']) : '' ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Date</span>
          <span class="value"><?= formatDate($booking['booked_date']) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Time</span>
          <span class="value"><?= formatTime($booking['booked_time']) ?></span>
        </div>
        <div class="summary-row total">
          <span class="label">Total Price</span>
          <span class="value"><?= formatPrice((float)$booking['total_price']) ?></span>
        </div>
        <div class="summary-row deposit">
          <span class="label">Deposit <?= $booking['deposit_paid'] ? 'Paid ✓' : 'Pending' ?></span>
          <span class="value"><?= formatPrice((float)$booking['deposit_amount']) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Balance on Day</span>
          <span class="value"><?= formatPrice((float)$booking['remaining_balance']) ?></span>
        </div>
        <div class="summary-row">
          <span class="label">Status</span>
          <span class="value">
            <?php
            $statusLabels = [
              'pending'   => '🕐 Awaiting Confirmation',
              'confirmed' => '✅ Confirmed',
            ];
            echo $statusLabels[$booking['status']] ?? ucfirst($booking['status']);
            ?>
          </span>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($booking['payment_method'] === 'bank_transfer' && !$booking['deposit_paid']): ?>
    <!-- Bank transfer reminder -->
    <div style="background:#FFF3E0;border:1px solid #F5C584;border-radius:var(--border-radius-lg);padding:var(--space-6);margin-bottom:var(--space-6)">
      <h4 style="color:#7A4500;margin-bottom:var(--space-3)">⏰ Transfer Your Deposit Within 24 Hours</h4>
      <?php
      $bankName = getSetting('bank_account_name', 'BraidedbyAGB');
      $sortCode = getSetting('bank_sort_code', '');
      $accNum   = getSetting('bank_account_number', '');
      $ref_code = strtoupper(explode(' ', $booking['c_name'])[0]) . '-' . str_replace('-', '', $booking['booked_date']);
      ?>
      <div class="bank-detail-row"><span>Account Name:</span><strong><?= htmlspecialchars($bankName) ?></strong></div>
      <?php if ($sortCode): ?>
      <div class="bank-detail-row"><span>Sort Code:</span><strong><?= htmlspecialchars($sortCode) ?></strong></div>
      <?php endif; ?>
      <?php if ($accNum): ?>
      <div class="bank-detail-row"><span>Account Number:</span><strong><?= htmlspecialchars($accNum) ?></strong></div>
      <?php endif; ?>
      <div class="bank-detail-row"><span>Reference:</span><strong><?= htmlspecialchars($ref_code) ?></strong></div>
      <div class="bank-detail-row" style="border-top:1px solid #F5C584;padding-top:var(--space-3);margin-top:var(--space-3)">
        <span>Amount:</span><strong style="color:var(--color-primary);font-size:var(--text-xl)"><?= formatPrice((float)$booking['deposit_amount']) ?></strong>
      </div>
      <p style="font-size:var(--text-sm);color:#7A4500;margin-top:var(--space-4)">Your booking will be automatically cancelled if payment is not received within 24 hours.</p>
    </div>
    <?php endif; ?>

    <!-- What happens next -->
    <div style="background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--border-radius-lg);padding:var(--space-6);margin-bottom:var(--space-6)">
      <h4 style="color:var(--color-deep-purple);margin-bottom:var(--space-5)">What Happens Next</h4>
      <div style="display:flex;flex-direction:column;gap:var(--space-4)">
        <?php
        $steps = $booking['payment_method'] === 'stripe'
          ? [
              ['✉️', $booking['c_email'] ? 'Confirmation email sent to ' . htmlspecialchars($booking['c_email']) : 'A confirmation email is on its way to you'],
              ['✅', 'We\'ll review and confirm your booking (usually within a few hours)'],
              ['📅', 'Reminder email 24 hours before your appointment'],
              ['⏰', 'Final reminder 2 hours before your appointment'],
            ]
          : [
              ['✉️', $booking['c_email'] ? 'Confirmation email sent to ' . htmlspecialchars($booking['c_email']) : 'A confirmation email is on its way to you'],
              ['🏦', 'Transfer your deposit of ' . formatPrice((float)$booking['deposit_amount']) . ' within 24 hours'],
              ['✅', 'Once payment is received, we\'ll confirm your booking'],
              ['📅', 'Reminder emails 24 hours and 2 hours before your appointment'],
            ];
        foreach ($steps as $step):
        ?>
        <div style="display:flex;gap:var(--space-3);align-items:flex-start">
          <span style="font-size:1.2rem;flex-shrink:0"><?= $step[0] ?></span>
          <span style="font-size:var(--text-sm);color:var(--color-text-muted);line-height:1.6"><?= $step[1] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Policy reminder -->
    <div style="background:var(--color-bg-light);border:1px solid var(--color-border);border-radius:var(--border-radius-lg);padding:var(--space-5);margin-bottom:var(--space-6);font-size:var(--text-sm);color:var(--color-text-muted)">
      <strong style="color:var(--color-deep-purple)">Important reminders:</strong><br>
      ⏰ Please arrive within <strong>20 minutes</strong> of your appointment time.<br>
      ❌ Cancellations must be made at least <strong>48 hours</strong> in advance.<br>
      💬 Running late? WhatsApp us immediately on <a href="https://wa.me/447769064971" style="color:var(--color-primary)">07769 064 971</a>.
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:var(--space-4);flex-wrap:wrap">
      <a href="https://wa.me/447769064971" class="btn btn-primary flex-1" target="_blank" rel="noopener">
        💬 WhatsApp Us
      </a>
      <a href="/shop" class="btn btn-outline-primary flex-1">Shop Extensions</a>
    </div>

    <p style="text-align:center;margin-top:var(--space-6);font-size:var(--text-sm);color:var(--color-text-muted)">
      Share your look after your appointment — tag us <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener" style="color:var(--color-primary)">@BraidedbyAGB</a> 💜
    </p>
  </div>
</main>

<style>
.bank-detail-row {
  display: flex;
  justify-content: space-between;
  padding: var(--space-2) 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  font-size: var(--text-sm);
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
</body>
</html>
