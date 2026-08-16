<?php
// ============================================================
// BraidedbyAGB — Direct Client Payment Page
// FILE: /public/pay.php  (route: /pay?token=xxxx)
// Lets a client pay their deposit directly via an admin-sent link.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$token = sanitize($_GET['token'] ?? '');
$error = '';
$paid  = false;

// ── Load booking by token ─────────────────────────────────
$bk = null;
if ($token) {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT b.*, c.name AS c_name, c.email AS c_email, c.phone AS c_phone,
               s.name AS s_name, sv.variant_name,
               b.payment_method_allowed
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE b.payment_token = ?
          AND b.deposit_paid  = 0
          AND b.status NOT IN ('cancelled','completed')
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $bk = $stmt->fetch();
}

if (!$token || !$bk) {
    // Token invalid, used, or booking already paid
    $pageInvalid = true;
} else {
    $db = $db ?? getDB();
    $allowed = $bk['payment_method_allowed'] ?? 'both';
    // Default tab: prefer stripe if allowed, else bank
    $defaultTab = ($allowed === 'bank_transfer') ? 'bank' : 'stripe';
}

// ── Handle bank-transfer acknowledgement (POST) ───────────
if (!isset($pageInvalid) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['pay_action'] ?? '') === 'bank_transfer') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security error — please refresh and try again.';
    } else {
        $db->prepare("
            UPDATE bookings
            SET payment_method = 'bank_transfer',
                payment_token  = NULL
            WHERE id = ? AND deposit_paid = 0
        ")->execute([$bk['id']]);

        // Record a pending payment row so admin can see it
        $exists = $db->prepare("SELECT id FROM payments WHERE booking_id=? AND type='deposit'")->execute([$bk['id']]);
        $payRow = $db->prepare("SELECT id FROM payments WHERE booking_id=? AND type='deposit'")->execute([$bk['id']]);
        if (!$db->query("SELECT COUNT(*) FROM payments WHERE booking_id={$bk['id']} AND type='deposit'")->fetchColumn()) {
            $db->prepare("
                INSERT INTO payments (booking_id, amount, currency, type, method, status)
                VALUES (?,?,'GBP','deposit','bank_transfer','pending')
            ")->execute([$bk['id'], $bk['deposit_amount']]);
        }

        // Notify admin
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $mail = createMailer();
            $mail->addAddress(SITE_EMAIL);
            $mail->Subject = 'Bank Transfer Initiated — ' . $bk['booking_ref'] . ' — ' . $bk['c_name'];
            $mail->Body    = emailWrapper("
                <h2>Bank Transfer Initiated</h2>
                <div class='detail-box'>
                  <table>
                    <tr><td>Client</td><td>{$bk['c_name']} ({$bk['c_email']})</td></tr>
                    <tr><td>Booking</td><td>{$bk['booking_ref']}</td></tr>
                    <tr><td>Service</td><td>{$bk['s_name']}" . ($bk['variant_name'] ? ' — ' . $bk['variant_name'] : '') . "</td></tr>
                    <tr><td>Date</td><td>" . formatDate($bk['booked_date'], 'l, j F Y') . "</td></tr>
                    <tr><td>Time</td><td>" . formatTime($bk['booked_time']) . "</td></tr>
                    <tr><td>Deposit due</td><td>" . formatPrice($bk['deposit_amount']) . "</td></tr>
                  </table>
                </div>
                <p>The client has confirmed they will send the bank transfer. Please confirm receipt in the admin panel.</p>
                <p><a href='" . ADMIN_URL . "/bookings/{$bk['id']}' style='background:#7A0050;color:#fff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block'>View Booking</a></p>
            ");
            $mail->send();

            // Send the bank details confirmation to the client
            $clientArr = ['name' => $bk['c_name'], 'email' => $bk['c_email']];
            emailBankTransferConfirm($bk, $clientArr);
        } catch (Throwable $e) {
            error_log('Pay.php bank notify error: ' . $e->getMessage());
        }

        $paid = true;
        $paidMethod = 'bank_transfer';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <title>Pay Deposit — BraidedbyAGB</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <?php if (!isset($pageInvalid) && !$paid && in_array($allowed, ['stripe','both'])): ?>
  <script src="https://js.stripe.com/v3/"></script>
  <?php endif; ?>
  <style>
    body { background: var(--color-bg); }
    .pay-wrap {
      max-width: 820px;
      margin: 0 auto;
      padding: var(--space-8) var(--space-4) var(--space-16);
    }
    .pay-logo {
      text-align: center;
      margin-bottom: var(--space-8);
    }
    .pay-logo a {
      font-family: var(--font-primary);
      font-weight: 900;
      font-size: 1.4rem;
      color: var(--color-deep-purple);
      text-decoration: none;
    }
    .pay-grid {
      display: grid;
      grid-template-columns: 1fr 1.4fr;
      gap: var(--space-6);
      align-items: start;
    }
    @media (max-width: 640px) {
      .pay-grid { grid-template-columns: 1fr; }
    }
    .pay-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius-lg);
      padding: var(--space-7);
    }
    .pay-summary-title {
      font-family: var(--font-primary);
      font-weight: 800;
      font-size: 0.65rem;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: var(--color-text-muted);
      margin-bottom: var(--space-5);
    }
    .pay-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      font-size: 0.85rem;
      padding: var(--space-2) 0;
      border-bottom: 1px solid var(--color-border);
    }
    .pay-row:last-child { border-bottom: none; }
    .pay-row .pl { color: var(--color-text-muted); }
    .pay-row .pv { font-weight: 600; color: var(--color-text); }
    .pay-row.deposit-row .pv { color: var(--color-primary); font-size: 1.1rem; font-family: var(--font-primary); font-weight: 800; }
    .tab-row {
      display: flex;
      gap: 0;
      border: 1.5px solid var(--color-border);
      border-radius: var(--border-radius);
      overflow: hidden;
      margin-bottom: var(--space-6);
    }
    .tab-btn {
      flex: 1;
      padding: var(--space-3) var(--space-4);
      font-family: var(--font-primary);
      font-weight: 700;
      font-size: 0.8rem;
      text-align: center;
      cursor: pointer;
      border: none;
      background: var(--color-white);
      color: var(--color-text-muted);
      transition: background 0.15s, color 0.15s;
    }
    .tab-btn.active {
      background: var(--color-primary);
      color: var(--color-white);
    }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    /* The Stripe Payment Element renders its own bordered fields; just give the
       wrapper some breathing room below it before the Pay button. */
    #stripe-payment-element {
      margin-bottom: var(--space-5);
    }
    .bank-detail-box {
      background: var(--color-bg-light);
      border: 1px solid var(--color-border);
      border-radius: var(--border-radius);
      padding: var(--space-5);
      margin-bottom: var(--space-5);
    }
    .bank-detail-row {
      display: flex;
      gap: var(--space-3);
      align-items: baseline;
      font-size: 0.85rem;
      margin-bottom: var(--space-2);
    }
    .bank-detail-row .bdl { color: var(--color-text-muted); min-width: 110px; }
    .bank-detail-row .bdv { font-weight: 700; font-family: var(--font-primary); color: var(--color-deep-purple); }
    .pay-error {
      background: #FDE8E8;
      border: 1px solid #F5A8A8;
      border-radius: var(--border-radius);
      padding: var(--space-3) var(--space-4);
      color: var(--color-error);
      font-size: var(--text-sm);
      margin-bottom: var(--space-5);
    }
    .secure-note {
      text-align: center;
      font-size: 0.72rem;
      color: var(--color-text-muted);
      margin-top: var(--space-4);
    }
    .invalid-page {
      text-align: center;
      padding: var(--space-16) var(--space-4);
    }
  </style>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>

<div class="pay-wrap">

  <div class="pay-logo">
    <a href="/">✨ BraidedbyAGB</a>
  </div>

<?php if (isset($pageInvalid)): ?>
  <!-- ── Invalid / Expired token ─────────────────────────── -->
  <div class="pay-card invalid-page">
    <p style="font-size:3rem;margin-bottom:var(--space-4)">🔒</p>
    <h2 style="font-family:var(--font-primary);color:var(--color-deep-purple);margin-bottom:var(--space-3)">
      Link Not Found
    </h2>
    <p style="color:var(--color-text-muted);max-width:380px;margin:0 auto var(--space-6)">
      This payment link is invalid, has already been used, or has expired.
      Please contact us if you need to make a payment.
    </p>
    <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap">
      <a href="https://wa.me/447769064971?text=Hi%2C%20I%20need%20help%20with%20my%20booking%20payment"
         class="btn btn-primary" target="_blank" rel="noopener">💬 WhatsApp Us</a>
      <a href="/contact" class="btn btn-outline-primary">Contact Page</a>
    </div>
  </div>

<?php elseif ($paid): ?>
  <!-- ── Payment submitted successfully ─────────────────── -->
  <div class="pay-card" style="text-align:center;padding:var(--space-10)">
    <?php if (($paidMethod ?? '') === 'bank_transfer'): ?>
      <p style="font-size:3rem;margin-bottom:var(--space-4)">🏦</p>
      <h2 style="font-family:var(--font-primary);color:var(--color-deep-purple);margin-bottom:var(--space-3)">
        Transfer Details Noted!
      </h2>
      <p style="color:var(--color-text-muted);max-width:400px;margin:0 auto var(--space-4)">
        Thank you! Please send <strong><?= formatPrice($bk['deposit_amount']) ?></strong> to the bank details shown.
        Once we confirm receipt, your booking will be confirmed and you'll receive an email.
      </p>
      <div class="bank-detail-box" style="max-width:360px;margin:var(--space-5) auto;text-align:left">
        <?php
          $bankName   = getSetting('bank_account_name',   'BraidedbyAGB');
          $bankSort   = getSetting('bank_sort_code',       '');
          $bankAcc    = getSetting('bank_account_number',  '');
          $bankRef    = $bk['booking_ref'];
        ?>
        <div class="bank-detail-row"><span class="bdl">Bank:</span><span class="bdv"><?= htmlspecialchars($bankName) ?></span></div>
        <div class="bank-detail-row"><span class="bdl">Sort code:</span><span class="bdv"><?= htmlspecialchars($bankSort) ?></span></div>
        <?php if ($bankAcc): ?><div class="bank-detail-row"><span class="bdl">Account no:</span><span class="bdv"><?= htmlspecialchars($bankAcc) ?></span></div><?php endif; ?>
        <div class="bank-detail-row"><span class="bdl">Amount:</span><span class="bdv" style="color:var(--color-primary)"><?= formatPrice($bk['deposit_amount']) ?></span></div>
        <div class="bank-detail-row"><span class="bdl">Reference:</span><span class="bdv"><?= htmlspecialchars($bankRef) ?></span></div>
      </div>
      <a href="https://wa.me/447769064971?text=Hi%2C%20I%20have%20sent%20my%20deposit%20transfer%20for%20booking%20<?= urlencode($bk['booking_ref']) ?>"
         class="btn btn-primary" target="_blank" rel="noopener">
        Let Us Know via WhatsApp
      </a>
    <?php else: ?>
      <p style="font-size:3rem;margin-bottom:var(--space-4)">💜</p>
      <h2 style="font-family:var(--font-primary);color:var(--color-deep-purple);margin-bottom:var(--space-3)">
        Deposit Paid — You're Booked!
      </h2>
      <p style="color:var(--color-text-muted);max-width:400px;margin:0 auto var(--space-4)">
        Your deposit of <strong><?= formatPrice($bk['deposit_amount']) ?></strong> has been received.
        Your appointment is confirmed — we'll see you soon! 💅
      </p>
      <a href="/" class="btn btn-primary">Back to Home</a>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- ── Payment form ────────────────────────────────────── -->
  <div class="pay-grid">

    <!-- Summary card -->
    <div class="pay-card">
      <div class="pay-summary-title">Your Booking</div>

      <div class="pay-row">
        <span class="pl">Client</span>
        <span class="pv"><?= htmlspecialchars($bk['c_name']) ?></span>
      </div>
      <div class="pay-row">
        <span class="pl">Service</span>
        <span class="pv"><?= htmlspecialchars($bk['s_name']) ?><?= $bk['variant_name'] ? '<br><small style="font-weight:400;color:var(--color-text-muted)">' . htmlspecialchars($bk['variant_name']) . '</small>' : '' ?></span>
      </div>
      <div class="pay-row">
        <span class="pl">Date</span>
        <span class="pv"><?= formatDate($bk['booked_date'], 'D, j M Y') ?></span>
      </div>
      <div class="pay-row">
        <span class="pl">Time</span>
        <span class="pv"><?= formatTime($bk['booked_time']) ?></span>
      </div>
      <div class="pay-row">
        <span class="pl">Total price</span>
        <span class="pv"><?= formatPrice($bk['total_price']) ?></span>
      </div>
      <div class="pay-row">
        <span class="pl">Balance on day</span>
        <span class="pv"><?= formatPrice($bk['remaining_balance']) ?></span>
      </div>
      <div class="pay-row deposit-row" style="margin-top:var(--space-3);padding-top:var(--space-3);border-top:2px solid var(--color-border)">
        <span class="pl" style="font-weight:700;color:var(--color-text)">Deposit due now</span>
        <span class="pv"><?= formatPrice($bk['deposit_amount']) ?></span>
      </div>

      <p style="font-size:0.72rem;color:var(--color-text-muted);margin-top:var(--space-4);line-height:1.6">
        This deposit secures your appointment. The remaining balance of <?= formatPrice($bk['remaining_balance']) ?> is due on the day.
      </p>
    </div>

    <!-- Payment card -->
    <div class="pay-card">
      <div class="pay-summary-title">Pay Deposit — <?= formatPrice($bk['deposit_amount']) ?></div>

      <?php if ($error): ?>
        <div class="pay-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($allowed === 'both'): ?>
        <!-- Tabs -->
        <div class="tab-row">
          <button type="button" class="tab-btn active" id="tab-stripe" onclick="switchTab('stripe')">💳 Card / PayPal / Klarna</button>
          <button type="button" class="tab-btn" id="tab-bank" onclick="switchTab('bank')">🏦 Bank Transfer</button>
        </div>
      <?php endif; ?>

      <!-- ── Stripe card pane ───────────────────────────── -->
      <?php if (in_array($allowed, ['stripe','both'])): ?>
      <div class="tab-pane <?= $defaultTab === 'stripe' ? 'active' : '' ?>" id="pane-stripe">
        <div id="stripe-payment-element"></div>
        <div id="stripe-error" class="pay-error" style="display:none"></div>
        <button type="button" id="stripe-pay-btn" class="btn btn-primary w-full btn-lg"
                onclick="handleStripePay()" style="justify-content:center">
          Pay <?= formatPrice($bk['deposit_amount']) ?> Securely
        </button>
        <div class="secure-note">🔒 Secured by Stripe · Your card details are never stored on our servers</div>
      </div>
      <?php endif; ?>

      <!-- ── Bank transfer pane ─────────────────────────── -->
      <?php if (in_array($allowed, ['bank_transfer','both'])): ?>
      <div class="tab-pane <?= $defaultTab === 'bank' ? 'active' : '' ?>" id="pane-bank">

        <?php
          $bankName = getSetting('bank_account_name',   'BraidedbyAGB');
          $bankSort = getSetting('bank_sort_code',       '');
          $bankAcc  = getSetting('bank_account_number',  '');
        ?>

        <p style="font-size:0.85rem;color:var(--color-text-muted);margin-bottom:var(--space-4);line-height:1.6">
          Please send your deposit to the account below. Use your booking reference as the payment reference so we can match it.
        </p>

        <div class="bank-detail-box">
          <div class="bank-detail-row"><span class="bdl">Bank:</span><span class="bdv"><?= htmlspecialchars($bankName) ?></span></div>
          <div class="bank-detail-row"><span class="bdl">Sort code:</span><span class="bdv"><?= htmlspecialchars($bankSort) ?></span></div>
          <?php if ($bankAcc): ?><div class="bank-detail-row"><span class="bdl">Account no:</span><span class="bdv"><?= htmlspecialchars($bankAcc) ?></span></div><?php endif; ?>
          <div class="bank-detail-row"><span class="bdl">Amount:</span><span class="bdv" style="color:var(--color-primary)"><?= formatPrice($bk['deposit_amount']) ?></span></div>
          <div class="bank-detail-row">
            <span class="bdl">Reference:</span>
            <span class="bdv" style="display:flex;align-items:center;gap:6px">
              <?= htmlspecialchars($bk['booking_ref']) ?>
              <button type="button" onclick="copyRef('<?= htmlspecialchars($bk['booking_ref']) ?>')"
                      style="font-size:0.7rem;background:none;border:1px solid var(--color-border);border-radius:4px;padding:2px 6px;cursor:pointer;color:var(--color-text-muted)"
                      id="copy-ref-btn">Copy</button>
            </span>
          </div>
        </div>

        <form method="POST" action="/pay?token=<?= htmlspecialchars(urlencode($token)) ?>">
          <input type="hidden" name="pay_action" value="bank_transfer">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <button type="submit" class="btn btn-primary w-full btn-lg" style="justify-content:center">
            I've Sent the Transfer
          </button>
        </form>

        <p style="font-size:0.72rem;color:var(--color-text-muted);text-align:center;margin-top:var(--space-3)">
          Your appointment is held while we await payment. We'll confirm via email once received.
        </p>
      </div>
      <?php endif; ?>

    </div>
  </div><!-- /pay-grid -->

<?php endif; ?>

</div><!-- /pay-wrap -->

<?php if (!isset($pageInvalid) && !$paid): ?>
<script>
// ── Tab switching ─────────────────────────────────────────
function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  var btn = document.getElementById('tab-' + tab);
  var pane = document.getElementById('pane-' + tab);
  if (btn) btn.classList.add('active');
  if (pane) pane.classList.add('active');
}

// ── Copy reference ────────────────────────────────────────
function copyRef(text) {
  navigator.clipboard.writeText(text).then(function() {
    var btn = document.getElementById('copy-ref-btn');
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
  });
}

<?php if (in_array($allowed ?? 'both', ['stripe','both'])): ?>
// ── Stripe Payment Element (Card, PayPal, Klarna, Clearpay) ──
var stripe = Stripe('<?= STRIPE_PUBLIC_KEY ?>');
var payElements = stripe.elements({ mode: 'payment', amount: <?= (int)round($bk['deposit_amount'] * 100) ?>, currency: 'gbp', locale: 'en-GB' });
var payElement = payElements.create('payment', { layout: 'tabs' });
payElement.mount('#stripe-payment-element');

function handleStripePay() {
  var btn     = document.getElementById('stripe-pay-btn');
  var errorEl = document.getElementById('stripe-error');
  btn.disabled = true;
  btn.textContent = 'Processing…';
  errorEl.style.display = 'none';

  // Step 1: validate the entered payment details
  payElements.submit().then(function(res) {
    if (res.error) throw new Error(res.error.message || 'Please check your payment details.');

    // Step 2: create the PaymentIntent server-side (booking already exists)
    return fetch('/api/pay-booking-intent', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: '<?= htmlspecialchars($token) ?>' })
    });
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.error) throw new Error(data.error);

    // Step 3: confirm — cards inline, Klarna/Clearpay/PayPal redirect out
    var returnUrl = window.location.origin + '/booking/confirmation?ref=' + encodeURIComponent(data.ref);
    return stripe.confirmPayment({
      elements: payElements,
      clientSecret: data.client_secret,
      confirmParams: { return_url: returnUrl },
      redirect: 'if_required'
    }).then(function(result) {
      if (result.error) throw new Error(result.error.message);
      var url = '/booking/confirmation?ref=' + encodeURIComponent(data.ref);
      if (result.paymentIntent && result.paymentIntent.id) url += '&payment_intent=' + encodeURIComponent(result.paymentIntent.id);
      window.location.href = url;
    });
  })
  .catch(function(err) {
    errorEl.textContent = err.message || 'Payment failed. Please try again or use bank transfer.';
    errorEl.style.display = '';
    btn.disabled = false;
    btn.textContent = 'Pay <?= formatPrice($bk['deposit_amount']) ?> Securely';
  });
}
<?php endif; ?>
</script>
<?php endif; ?>

<?php
// (Legacy ?paid=1 block removed — Stripe JS now redirects directly to /booking/confirmation?ref=...)
?>

</body>
</html>
