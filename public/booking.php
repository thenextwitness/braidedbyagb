<?php
// ============================================================
// BraidedbyAGB — Booking Page
// FILE: /public/booking.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();

// Pre-select service from URL slug e.g. /booking/box-braids
$serviceSlug = sanitize($_GET['service'] ?? '');
$preselected = null;
if ($serviceSlug) {
    $stmt = $db->prepare("SELECT * FROM services WHERE slug = ? AND is_active = 1");
    $stmt->execute([$serviceSlug]);
    $preselected = $stmt->fetch();
}

// Fetch all active services with variants
$services = $db->query("
    SELECT s.id, s.name, s.slug, s.description, s.duration_mins, s.category, s.prep_notes, s.price_from,
           JSON_ARRAYAGG(
               JSON_OBJECT(
                   'id', sv.id,
                   'name', sv.variant_name,
                   'price', sv.price,
                   'duration', sv.duration_mins
               )
           ) as variants_json
    FROM services s
    LEFT JOIN service_variants sv ON sv.service_id = s.id
    WHERE s.is_active = 1
    GROUP BY s.id
    ORDER BY s.display_order ASC
")->fetchAll();

// Build JS-friendly services object
$servicesJs = [];
foreach ($services as $svc) {
    $variants = json_decode($svc['variants_json'], true);
    // Filter out null variants (services with no variants)
    $variants = array_filter($variants ?? [], fn($v) => $v['id'] !== null);
    $servicesJs[$svc['id']] = [
        'id'          => $svc['id'],
        'name'        => $svc['name'],
        'slug'        => $svc['slug'],
        'duration'    => $svc['duration_mins'],
        'price_from'  => $svc['price_from'],
        'prep_notes'  => $svc['prep_notes'],
        'description' => $svc['description'],
        'variants'    => array_values($variants),
    ];
}

$stripePublicKey = STRIPE_PUBLIC_KEY;
$depositPct      = (int)getSetting('deposit_percent', '30');
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
  <title>Book an Appointment — BraidedbyAGB</title>
  <meta name="description" content="Book your African hair braiding appointment with BraidedbyAGB in Farnborough, UK.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/booking">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/booking">
  <meta property="og:title" content="Book an Appointment — BraidedbyAGB">
  <meta property="og:description" content="Book your African hair braiding appointment with BraidedbyAGB in Farnborough, UK.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <link rel="stylesheet" href="/assets/css/booking.css">
  <script src="https://js.stripe.com/v3/"></script>
  <style>
    /* ── Custom Request Nudge (Step 1 bottom) ── */
    .custom-request-nudge {
      margin-top: var(--space-5);
      border: 1.5px dashed rgba(204,26,138,0.35);
      border-radius: 12px;
      background: linear-gradient(135deg, #fdf6ff 0%, #fff5fb 100%);
      overflow: hidden;
    }
    .crn-inner {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      padding: var(--space-4) var(--space-5);
      flex-wrap: wrap;
    }
    .crn-icon {
      font-size: 1.6rem;
      flex-shrink: 0;
    }
    .crn-text {
      flex: 1;
      min-width: 180px;
    }
    .crn-text strong {
      display: block;
      font-family: var(--font-primary);
      font-weight: 800;
      font-size: 0.88rem;
      color: var(--color-primary-dark);
      margin-bottom: 3px;
    }
    .crn-text span {
      font-size: 0.78rem;
      color: var(--color-text-muted);
      line-height: 1.5;
    }
    .crn-btn {
      flex-shrink: 0;
      padding: 9px 18px;
      background: var(--color-primary);
      color: #fff;
      font-family: var(--font-primary);
      font-weight: 700;
      font-size: 0.75rem;
      letter-spacing: 0.04em;
      text-decoration: none;
      border-radius: 8px;
      white-space: nowrap;
      transition: background 0.2s, transform 0.15s;
    }
    .crn-btn:hover {
      background: var(--color-primary-dark);
      transform: translateY(-1px);
    }
    @media (max-width: 500px) {
      .crn-inner { flex-direction: column; align-items: flex-start; gap: var(--space-3); }
      .crn-btn   { width: 100%; text-align: center; }
    }

    /* ── Sidebar Custom Request Card ── */
    .booking-custom-cta {
      margin-top: var(--space-5);
      background: linear-gradient(135deg, #fdf6ff, #fff5fb);
      border: 1px solid rgba(204,26,138,0.2);
      border-radius: 12px;
      padding: var(--space-4) var(--space-5);
    }
    .bcc-eyebrow {
      font-family: var(--font-primary);
      font-size: 0.7rem;
      font-weight: 800;
      letter-spacing: 0.1em;
      color: var(--color-primary);
      margin: 0 0 var(--space-2);
    }
    .bcc-body {
      font-size: var(--text-sm);
      color: var(--color-text-muted);
      line-height: 1.55;
      margin: 0 0 var(--space-3);
    }
  </style>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="page-content" style="background:var(--color-bg-light)">

  <section class="page-hero" style="padding:140px 0 60px">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Farnborough, UK</span></p>
        <h1 class="page-hero-title">Book Your Appointment</h1>
        <p class="page-hero-subtitle">Secure your spot with a <?= $depositPct ?>% deposit. Simple, fast, confirmed.</p>
      </div>
    </div>
  </section>

  <!-- Progress Bar -->
  <div class="booking-progress-bar">
    <div class="container">
      <div class="booking-steps-nav">
        <div class="step-nav-item active" data-step="1">
          <span class="step-nav-num">1</span>
          <span class="step-nav-label">Service</span>
        </div>
        <div class="step-nav-divider"></div>
        <div class="step-nav-item" data-step="2">
          <span class="step-nav-num">2</span>
          <span class="step-nav-label">Date & Time</span>
        </div>
        <div class="step-nav-divider"></div>
        <div class="step-nav-item" data-step="3">
          <span class="step-nav-num">3</span>
          <span class="step-nav-label">Your Details</span>
        </div>
        <div class="step-nav-divider"></div>
        <div class="step-nav-item" data-step="4">
          <span class="step-nav-num">4</span>
          <span class="step-nav-label">Extras</span>
        </div>
        <div class="step-nav-divider"></div>
        <div class="step-nav-item" data-step="5">
          <span class="step-nav-num">5</span>
          <span class="step-nav-label">Payment</span>
        </div>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="booking-layout">

      <!-- ════════════════════════════════════════════ -->
      <!-- MAIN BOOKING STEPS                          -->
      <!-- ════════════════════════════════════════════ -->
      <div class="booking-main">

        <!-- STEP 1: Choose Service -->
        <div class="booking-step active" id="step-1">
          <div class="booking-step-header">
            <span class="step-number">1</span>
            <h2 class="step-title">Choose Your Service</h2>
          </div>

          <div class="service-select-grid">
            <?php foreach ($services as $svc):
              $isActive = $preselected && $preselected['id'] == $svc['id'];
            ?>
            <button class="service-select-card <?= $isActive ? 'selected' : '' ?>"
                    data-service-id="<?= (int)$svc['id'] ?>">
              <div class="service-select-name"><?= htmlspecialchars($svc['name']) ?></div>
              <div class="service-select-price">From £<?= number_format((float)$svc['price_from'], 0) ?></div>
            </button>
            <?php endforeach; ?>
          </div>

          <!-- Service description (shown once a service is chosen) -->
          <div id="service-desc-box" style="display:none;margin-top:var(--space-4);padding:var(--space-4);background:var(--color-bg-light,#faf7fb);border-left:4px solid var(--color-primary);border-radius:0 var(--border-radius-lg,8px) var(--border-radius-lg,8px) 0">
            <p id="service-desc-text" style="margin:0;font-size:var(--text-sm);color:var(--color-text,#3a2540);line-height:1.6"></p>
          </div>

          <!-- Variant selector (shown once a service is chosen) -->
          <div class="variant-section" id="variant-section" style="display:none">
            <h4 class="variant-title">Select Your Option</h4>
            <div class="variant-grid" id="variant-grid"></div>
          </div>

          <!-- Prep notes -->
          <div class="prep-note-box" id="prep-note-box" style="display:none">
            <strong>📋 Preparation:</strong>
            <span id="prep-note-text"></span>
          </div>

          <!-- Add-ons -->
          <div class="addons-section" id="addons-section" style="display:none">
            <h4 class="addon-title">Optional Add-ons</h4>
            <div id="addon-list"></div>
          </div>

          <button class="btn btn-primary btn-lg w-full" id="step1-next" disabled onclick="goToStep(2)">
            Continue to Date & Time →
          </button>

          <!-- Custom request nudge -->
          <div class="custom-request-nudge">
            <div class="crn-inner">
              <span class="crn-icon">✨</span>
              <div class="crn-text">
                <strong>Can't find your style?</strong>
                <span>Describe your dream look and we'll create a personalised quote just for you.</span>
              </div>
              <a href="/custom-request" class="crn-btn">Request Custom Style →</a>
            </div>
          </div>
        </div>


        <!-- STEP 2: Choose Date & Time -->
        <div class="booking-step" id="step-2">
          <div class="booking-step-header">
            <span class="step-number">2</span>
            <h2 class="step-title">Choose Date & Time</h2>
          </div>

          <!-- Mini calendar -->
          <div class="calendar-nav">
            <button class="cal-nav-btn" id="cal-prev">←</button>
            <h3 class="cal-month-title" id="cal-month-label"></h3>
            <button class="cal-nav-btn" id="cal-next">→</button>
          </div>
          <div class="calendar-grid" id="calendar-grid"></div>

          <!-- Time slots -->
          <div id="time-slots-section" style="display:none">
            <h4 class="time-slots-title">Available Times — <span id="selected-date-label"></span></h4>
            <div class="time-slots-grid" id="time-slots-grid">
              <p style="color:var(--color-text-muted);font-size:var(--text-sm)">Loading available times...</p>
            </div>
          </div>
          <input type="hidden" id="booking-date" name="date">
          <input type="hidden" id="booking-time" name="time">

          <div style="display:flex;gap:var(--space-4);margin-top:var(--space-6)">
            <button class="btn btn-outline-primary" onclick="goToStep(1)">← Back</button>
            <button class="btn btn-primary btn-lg flex-1" id="step2-next" disabled onclick="goToStep(3)">
              Continue to Your Details →
            </button>
          </div>
        </div>


        <!-- STEP 3: Your Details -->
        <div class="booking-step" id="step-3">
          <div class="booking-step-header">
            <span class="step-number">3</span>
            <h2 class="step-title">Your Details</h2>
          </div>

          <!-- Who is this appointment for? (per appointment) -->
          <div class="form-group">
            <label class="form-label" for="guest-name">Who is this appointment for? *</label>
            <input class="form-control" type="text" id="guest-name" placeholder="e.g. Myself, or Sarah (my daughter)" required>
            <span class="field-error">Please tell us who this appointment is for.</span>
            <span style="font-size:var(--text-xs);color:var(--color-text-muted);display:block;margin-top:4px">
              Booking for the family? Add each person — you'll pay one combined deposit at the end.
            </span>
          </div>

          <!-- Payer contact details — collected once for the whole booking -->
          <div id="payer-contact-block">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="client-name">Full Name *</label>
                <input class="form-control" type="text" id="client-name" placeholder="Your full name" required>
                <span class="field-error">Please enter your name.</span>
              </div>
              <div class="form-group">
                <label class="form-label" for="client-phone">Phone Number *</label>
                <input class="form-control" type="tel" id="client-phone" placeholder="07700 000000" required>
                <span class="field-error">Please enter your phone number.</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="client-email">Email Address *</label>
              <input class="form-control" type="email" id="client-email" placeholder="your@email.com" required>
              <span class="field-error">Please enter a valid email.</span>
            </div>

            <div class="form-group">
              <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer">
                <input type="checkbox" id="email-optin" checked style="accent-color:var(--color-primary);width:16px;height:16px">
                <span style="font-size:var(--text-sm);color:var(--color-text-muted)">
                  I'd like to receive appointment reminders and updates by email
                </span>
              </label>
            </div>
          </div>

          <!-- Shown for 2nd+ appointments instead of the contact block -->
          <div id="payer-locked-note" style="display:none;font-size:var(--text-sm);color:var(--color-text-muted);background:var(--color-bg-light);border-radius:8px;padding:var(--space-3) var(--space-4);margin-bottom:var(--space-4)">
            Booking under <strong id="payer-locked-name"></strong>. All appointments share one confirmation and one combined deposit.
          </div>

          <div class="form-group">
            <label class="form-label" for="client-notes">Additional Notes for this appointment (Optional)</label>
            <textarea class="form-control" id="client-notes" rows="3"
                      placeholder="Hair length, specific style preferences, any allergies..."></textarea>
          </div>

          <!-- Media consent (per appointment) -->
          <div class="form-group">
            <label class="form-label">📸 Photos &amp; videos</label>
            <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin:0 0 var(--space-3)">
              We love sharing our work on social media. Are you happy for us to take photos/videos during or after this appointment?
            </p>
            <div class="media-consent-options" style="display:flex;flex-direction:column;gap:var(--space-2)">
              <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:var(--text-sm)">
                <input type="radio" name="media-consent" value="hair_face" style="accent-color:var(--color-primary);width:16px;height:16px"> Yes — hair &amp; me (my face may be shown)
              </label>
              <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:var(--text-sm)">
                <input type="radio" name="media-consent" value="hair" style="accent-color:var(--color-primary);width:16px;height:16px"> Yes — hair only (no face)
              </label>
              <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;font-size:var(--text-sm)">
                <input type="radio" name="media-consent" value="none" checked style="accent-color:var(--color-primary);width:16px;height:16px"> No, please don't
              </label>
            </div>
          </div>

          <div style="display:flex;gap:var(--space-4);margin-top:var(--space-6)">
            <button class="btn btn-outline-primary" onclick="goToStep(2)">← Back</button>
            <button class="btn btn-primary btn-lg flex-1" onclick="validateStep3()">
              Continue to Extras →
            </button>
          </div>
        </div>


        <!-- STEP 4: Pipeline — Product Recommendation -->
        <div class="booking-step" id="step-4">
          <div class="booking-step-header">
            <span class="step-number">4</span>
            <h2 class="step-title">Complete Your Look</h2>
          </div>

          <div id="pipeline-loading" style="text-align:center;padding:var(--space-10)">
            <div class="spinner"></div>
            <p style="color:var(--color-text-muted);margin-top:var(--space-4)">Finding the perfect products for your style...</p>
          </div>

          <div id="pipeline-recommendations" style="display:none"></div>

          <div id="pipeline-none" style="display:none;text-align:center;padding:var(--space-8)">
            <p style="color:var(--color-text-muted)">No product recommendations for this service.</p>
          </div>

          <div style="display:flex;gap:var(--space-4);margin-top:var(--space-6)">
            <button class="btn btn-outline-primary" onclick="goToStep(3)">← Back</button>
            <button class="btn btn-primary btn-lg flex-1" onclick="addToCart()">
              Add to Cart →
            </button>
          </div>
        </div>


        <!-- STEP 5: Review & Pay -->
        <div class="booking-step" id="step-5">
          <div class="booking-step-header">
            <span class="step-number">5</span>
            <h2 class="step-title">Review & Pay Deposit</h2>
          </div>

          <!-- Add another appointment -->
          <button class="btn btn-outline-primary w-full" style="margin-bottom:var(--space-5)" onclick="addAnotherPerson()">
            ＋ Add another person / appointment
          </button>

          <!-- Where? Salon vs Home service -->
          <div class="location-section" style="margin-bottom:var(--space-5)">
            <h4 style="margin:0 0 var(--space-3);color:var(--color-deep-purple);font-size:var(--text-md)">Where would you like your appointment?</h4>
            <div class="location-tabs" style="display:flex;gap:var(--space-3);flex-wrap:wrap">
              <button type="button" class="location-tab active" id="loc-salon" onclick="selectLocationType('salon')"
                      style="flex:1;min-width:140px;padding:var(--space-4);border:2px solid var(--color-primary);border-radius:var(--border-radius-lg,8px);background:var(--color-primary);color:#fff;cursor:pointer;font-weight:600">
                🏠 At the salon<br><span style="font-weight:400;font-size:var(--text-xs)">Farnborough — free</span>
              </button>
              <button type="button" class="location-tab" id="loc-home" onclick="selectLocationType('home')"
                      style="flex:1;min-width:140px;padding:var(--space-4);border:2px solid var(--color-border,#E8D8EE);border-radius:var(--border-radius-lg,8px);background:#fff;color:var(--color-text);cursor:pointer;font-weight:600">
                🚗 Home service<br><span id="home-tab-note" style="font-weight:400;font-size:var(--text-xs);color:var(--color-text-muted)">Bookings £<span id="home-min-label"></span>+</span>
              </button>
            </div>

            <!-- Home service details (shown when Home is chosen) -->
            <div id="home-service-details" style="display:none;margin-top:var(--space-4);padding:var(--space-4);background:var(--color-bg-light,#faf7fb);border-radius:var(--border-radius-lg,8px)">
              <div class="form-group">
                <label class="form-label" for="travel-area">Your area *</label>
                <select class="form-control" id="travel-area" onchange="onTravelAreaChange()">
                  <option value="">Select your area…</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="service-address">Full address (incl. postcode) *</label>
                <textarea class="form-control" id="service-address" rows="3" placeholder="House number and street, town, postcode" oninput="updatePayButtonState()"></textarea>
                <span style="font-size:var(--text-xs);color:var(--color-text-muted);display:block;margin-top:4px">
                  The travel fee is paid in full now, on top of your deposit.
                </span>
              </div>
            </div>
          </div>

          <!-- Booking summary review (cart) -->
          <div class="booking-review-table" id="booking-review-table"></div>

          <!-- Policy checkbox — MUST tick before paying -->
          <div class="policy-checkbox" style="margin:var(--space-6) 0">
            <input type="checkbox" id="policy-checkbox">
            <label for="policy-checkbox">
              I have read and agree to the <a href="/policies" target="_blank">BraidedbyAGB policies</a>.
              I understand that: my <strong>deposit is non-refundable</strong>,
              cancellations must be made at least <strong>48 hours</strong> before my appointment,
              and I must arrive within <strong>20 minutes</strong> of my appointment time or my
              appointment may be cancelled and deposit forfeited.
            </label>
          </div>

          <!-- Payment method tabs -->
          <div class="payment-tabs">
            <button class="payment-tab active" data-method="stripe" onclick="selectPaymentMethod('stripe', this)">
              💳 Card / PayPal / Klarna
            </button>
            <button class="payment-tab" data-method="bank_transfer" onclick="selectPaymentMethod('bank_transfer', this)">
              🏦 Bank Transfer
            </button>
          </div>

          <!-- Stripe payment section (Card, PayPal, Klarna, Clearpay via Payment Element) -->
          <div id="stripe-section">
            <div class="stripe-card-wrap">
              <label class="form-label">Payment Details</label>
              <div id="stripe-payment-element"></div>
              <div id="stripe-card-errors" class="stripe-error" role="alert"></div>
            </div>
            <button class="btn btn-gold btn-lg w-full" id="stripe-pay-btn" disabled onclick="submitStripePayment()">
              Pay Deposit — <span id="stripe-deposit-amount"></span>
            </button>
          </div>

          <!-- Bank transfer section -->
          <div id="bank-transfer-section" style="display:none">
            <div class="bank-transfer-box" id="bank-details-box">
              <!-- Populated by PHP via JS -->
            </div>
            <div class="policy-checkbox" style="margin:var(--space-4) 0">
              <input type="checkbox" id="bank-confirm-checkbox">
              <label for="bank-confirm-checkbox">
                I will transfer the deposit amount of <strong id="bank-deposit-label"></strong> within <strong>24 hours</strong>.
                I understand my booking will be automatically cancelled if payment is not received.
              </label>
            </div>
            <button class="btn btn-primary btn-lg w-full" id="bank-submit-btn" disabled onclick="submitBankTransfer()">
              Confirm Bank Transfer Booking
            </button>
          </div>

          <div style="display:flex;gap:var(--space-4);margin-top:var(--space-6)">
            <button class="btn btn-outline-primary" onclick="goToStep(4)">← Back</button>
          </div>
        </div>

      </div><!-- /booking-main -->


      <!-- ════════════════════════════════════════════ -->
      <!-- BOOKING SUMMARY SIDEBAR                     -->
      <!-- ════════════════════════════════════════════ -->
      <aside class="booking-sidebar">
        <div class="booking-summary">
          <div class="booking-summary-header">Your Booking Summary</div>
          <div class="booking-summary-body">

            <!-- Cart items (appointments already added) -->
            <div id="cart-items"></div>

            <!-- Current appointment being configured (draft) -->
            <div id="draft-summary">
              <div class="summary-row">
                <span class="label">Service</span>
                <span class="value" id="sum-service">—</span>
              </div>
              <div class="summary-row">
                <span class="label">Option</span>
                <span class="value" id="sum-variant">—</span>
              </div>
              <div class="summary-row">
                <span class="label">For</span>
                <span class="value" id="sum-guest">—</span>
              </div>
              <div class="summary-row">
                <span class="label">Date</span>
                <span class="value" id="sum-date">—</span>
              </div>
              <div class="summary-row">
                <span class="label">Time</span>
                <span class="value" id="sum-time">—</span>
              </div>
              <div class="summary-row" id="sum-addons-row" style="display:none">
                <span class="label">Add-ons</span>
                <span class="value" id="sum-addons">—</span>
              </div>
            </div>

            <div class="summary-row total">
              <span class="label" id="sum-total-label">Total Price</span>
              <span class="value" id="sum-total">—</span>
            </div>
            <div class="summary-row deposit">
              <span class="label">Deposit Due (<?= $depositPct ?>%)</span>
              <span class="value" id="sum-deposit">—</span>
            </div>
            <div class="summary-row">
              <span class="label">Balance on Day</span>
              <span class="value" id="sum-balance">—</span>
            </div>
          </div>
        </div>

        <!-- Trust signals -->
        <div class="booking-trust">
          <div class="trust-item">
            <span>🔒</span>
            <span>Secure payment via Stripe</span>
          </div>
          <div class="trust-item">
            <span>✓</span>
            <span>Instant email confirmation</span>
          </div>
          <div class="trust-item">
            <span>⏰</span>
            <span>24hr & 2hr reminders sent</span>
          </div>
          <div class="trust-item">
            <span>💬</span>
            <span>WhatsApp support always available</span>
          </div>
        </div>

        <!-- Custom request CTA -->
        <div class="booking-custom-cta">
          <p class="bcc-eyebrow">✨ Don't see your style?</p>
          <p class="bcc-body">Looking for something bespoke? Describe your dream look and we'll quote you personally.</p>
          <a href="/custom-request" class="btn btn-outline-primary w-full" style="margin-bottom:var(--space-3)">
            Request a Custom Style
          </a>
        </div>

        <!-- Need help -->
        <div style="margin-top:var(--space-5);text-align:center">
          <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:var(--space-3)">Questions? Get in touch:</p>
          <a href="https://wa.me/447769064971" class="btn btn-outline-primary w-full" target="_blank" rel="noopener">
            💬 WhatsApp Us
          </a>
        </div>
      </aside>

    </div><!-- /booking-layout -->
  </div>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// ═══════════════════════════════════════════════════════════
// BOOKING JAVASCRIPT
// ═══════════════════════════════════════════════════════════

// All services data from PHP
const SERVICES    = <?= json_encode($servicesJs) ?>;
const DEPOSIT_PCT = <?= $depositPct ?>;
const STRIPE_KEY  = '<?= $stripePublicKey ?>';
const BANK_NAME   = '<?= htmlspecialchars(getSetting('bank_account_name', 'BraidedbyAGB')) ?>';
const BANK_SORT   = '<?= htmlspecialchars(getSetting('bank_sort_code', 'XX-XX-XX')) ?>';
const BANK_ACC    = '<?= htmlspecialchars(getSetting('bank_account_number', 'XXXXXXXX')) ?>';

// Home-service (mobile) config — editable via settings
const HOME_SERVICE_MIN = <?= (float)getSetting('home_service_min', '70') ?>;
const TRAVEL_FEES = <?= json_encode([
  ['key' => 'farnborough',         'label' => 'Farnborough',           'fee' => (float)getSetting('travel_fee_farnborough', '25')],
  ['key' => 'camberley_aldershot', 'label' => 'Camberley / Aldershot', 'fee' => (float)getSetting('travel_fee_camberley_aldershot', '30')],
  ['key' => 'further',             'label' => 'Further locations',     'fee' => (float)getSetting('travel_fee_further', '45')],
]) ?>;

// ═══ State: payer + cart + draft (current appointment) ═══
// The draft is the appointment currently being configured. When the customer
// taps "Add to Cart" it is pushed into `cart`. Contact details belong to the
// `payer` and are collected once. One combined deposit is paid for the whole cart.
function freshDraft() {
  return {
    serviceId: null, variantId: null, serviceName: '', variantName: '',
    servicePrice: 0, durationMins: 0,
    addons: [], date: '', time: '', guestName: '', notes: '',
    mediaConsent: 'none',
    pipelineProducts: [],
  };
}
let draft = freshDraft();
let cart  = [];     // committed appointments (array of draft snapshots)
let payer = { name: '', email: '', phone: '', emailOptin: true, set: false };
let paymentMethod = 'stripe';

<?php if ($preselected): ?>
draft.serviceId = <?= $preselected['id'] ?>;
<?php endif; ?>

// ── Step navigation ─────────────────────────────────────
function goToStep(n) {
  document.querySelectorAll('.booking-step').forEach(s => s.classList.remove('active'));
  document.getElementById('step-' + n).classList.add('active');
  document.querySelectorAll('.step-nav-item').forEach((item, i) => {
    item.classList.toggle('active',   i < n);
    item.classList.toggle('complete', i < n - 1);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (n === 2) initCalendar();
  if (n === 3) showStep3PayerState();
  if (n === 4) loadPipelineRecommendations();
  if (n === 5) renderPaymentStep();
}

// ── Service selection ────────────────────────────────────
document.querySelectorAll('.service-select-card').forEach(card => {
  card.addEventListener('click', function() {
    document.querySelectorAll('.service-select-card').forEach(c => c.classList.remove('selected'));
    this.classList.add('selected');
    selectService(parseInt(this.dataset.serviceId));
  });
});

<?php if ($preselected): ?>
// Pre-select service from URL
window.addEventListener('DOMContentLoaded', () => selectService(<?= $preselected['id'] ?>));
<?php endif; ?>

function selectService(id) {
  const svc = SERVICES[id];
  if (!svc) return;
  draft.serviceId    = id;
  draft.serviceName  = svc.name;
  draft.variantId    = null;
  draft.variantName  = '';
  draft.servicePrice = parseFloat(svc.price_from) || 0;
  draft.durationMins = parseInt(svc.duration) || 0;
  draft.addons       = [];

  // Show variants
  const variantSection = document.getElementById('variant-section');
  const variantGrid    = document.getElementById('variant-grid');
  variantGrid.innerHTML = '';

  if (svc.variants && svc.variants.length > 0) {
    variantSection.style.display = 'block';
    svc.variants.forEach(v => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'variant-btn';
      btn.dataset.variantId    = v.id;
      btn.dataset.variantName  = v.name;
      btn.dataset.variantPrice = v.price;
      btn.innerHTML = `<span class="variant-btn-name">${v.name}</span><span class="variant-btn-price">£${parseFloat(v.price).toFixed(0)}</span>`;
      btn.addEventListener('click', () => {
        document.querySelectorAll('.variant-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        draft.variantId    = v.id;
        draft.variantName  = v.name;
        draft.servicePrice = parseFloat(v.price);
        draft.durationMins = parseInt(v.duration) || parseInt(svc.duration) || 0;
        updateSummary();
        loadAddons(id);
        document.getElementById('step1-next').disabled = false;
      });
      variantGrid.appendChild(btn);
    });
  } else {
    // No variants — service has single price
    variantSection.style.display = 'none';
    loadAddons(id);
    document.getElementById('step1-next').disabled = false;
  }

  // Service description
  const descBox  = document.getElementById('service-desc-box');
  const descText = document.getElementById('service-desc-text');
  if (svc.description && svc.description.trim()) {
    descText.textContent = svc.description;
    descBox.style.display = 'block';
  } else {
    descBox.style.display = 'none';
  }

  // Prep notes
  const prepBox  = document.getElementById('prep-note-box');
  const prepText = document.getElementById('prep-note-text');
  if (svc.prep_notes) {
    prepText.textContent = svc.prep_notes;
    prepBox.style.display = 'block';
  } else {
    prepBox.style.display = 'none';
  }

  updateSummary();
}

// ── Add-ons ──────────────────────────────────────────────
async function loadAddons(serviceId) {
  const addonSection = document.getElementById('addons-section');
  const addonList    = document.getElementById('addon-list');
  addonList.innerHTML = '';
  draft.addons = [];

  try {
    const res  = await fetch(`/api/addons?service_id=${serviceId}`);
    const data = await res.json();
    if (data.addons && data.addons.length > 0) {
      addonSection.style.display = 'block';
      data.addons.forEach(addon => {
        const div = document.createElement('div');
        div.className = 'addon-item';
        div.innerHTML = `
          <label style="display:flex;align-items:center;gap:var(--space-3);cursor:pointer;padding:var(--space-3) 0">
            <input type="checkbox" class="addon-checkbox"
                   data-addon-id="${addon.id}"
                   data-addon-name="${addon.name}"
                   data-addon-price="${addon.price}"
                   style="accent-color:var(--color-primary);width:16px;height:16px">
            <span style="flex:1;font-size:var(--text-sm)">${addon.name}</span>
            <span style="font-weight:700;color:var(--color-primary)">+£${parseFloat(addon.price).toFixed(2)}</span>
          </label>`;
        div.querySelector('input').addEventListener('change', function() {
          if (this.checked) {
            draft.addons.push({ id: addon.id, name: addon.name, price: parseFloat(addon.price) });
          } else {
            draft.addons = draft.addons.filter(a => a.id !== addon.id);
          }
          updateSummary();
        });
        addonList.appendChild(div);
      });
    } else {
      addonSection.style.display = 'none';
    }
  } catch(e) {
    addonSection.style.display = 'none';
  }
}

// ── Calendar ─────────────────────────────────────────────
let calYear, calMonth;

function initCalendar() {
  const now = new Date();
  calYear  = now.getFullYear();
  calMonth = now.getMonth();
  renderCalendar();
}

function renderCalendar() {
  const monthNames = ['January','February','March','April','May','June',
                      'July','August','September','October','November','December'];
  document.getElementById('cal-month-label').textContent = monthNames[calMonth] + ' ' + calYear;

  const grid = document.getElementById('calendar-grid');
  grid.innerHTML = '';

  // Day headers
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d => {
    const h = document.createElement('div');
    h.className = 'cal-day-header';
    h.textContent = d;
    grid.appendChild(h);
  });

  const firstDay = new Date(calYear, calMonth, 1).getDay();
  const daysIn   = new Date(calYear, calMonth + 1, 0).getDate();
  const today    = new Date(); today.setHours(0,0,0,0);

  // Blank cells
  for (let i = 0; i < firstDay; i++) {
    const blank = document.createElement('div');
    blank.className = 'cal-day empty';
    grid.appendChild(blank);
  }

  for (let d = 1; d <= daysIn; d++) {
    const cell = document.createElement('button');
    cell.type = 'button';
    const date = new Date(calYear, calMonth, d);
    // Build date string from components — never use toISOString() which converts to UTC
    // and can shift the date by 1 day for UK BST users (UTC+1).
    const mm = String(calMonth + 1).padStart(2, '0');
    const dd = String(d).padStart(2, '0');
    const dateStr = `${calYear}-${mm}-${dd}`;
    cell.className = 'cal-day';
    cell.textContent = d;
    cell.dataset.date = dateStr;

    if (date < today) {
      cell.classList.add('cal-past');
      cell.disabled = true;
    } else if (date.getTime() === today.getTime()) {
      cell.classList.add('cal-today');
    }

    cell.addEventListener('click', function() {
      if (this.disabled || this.classList.contains('cal-blocked')) return;
      document.querySelectorAll('.cal-day').forEach(c => c.classList.remove('selected'));
      this.classList.add('selected');
      draft.date = dateStr;
      document.getElementById('booking-date').value = dateStr;
      document.getElementById('selected-date-label').textContent =
        date.toLocaleDateString('en-GB', { weekday:'long', day:'numeric', month:'long' });
      loadTimeSlots(dateStr);
      document.getElementById('time-slots-section').style.display = 'block';
      updateSummary();
    });
    grid.appendChild(cell);
  }

  // Fetch blocked dates and mark them
  fetch(`/api/availability?year=${calYear}&month=${calMonth + 1}`)
    .then(r => r.json())
    .then(data => {
      if (data.blocked) {
        data.blocked.forEach(dateStr => {
          const cell = grid.querySelector(`[data-date="${dateStr}"]`);
          if (cell) { cell.classList.add('cal-blocked'); cell.disabled = true; }
        });
      }
    }).catch(() => {});
}

document.getElementById('cal-prev').addEventListener('click', () => {
  calMonth--;
  if (calMonth < 0) { calMonth = 11; calYear--; }
  renderCalendar();
});
document.getElementById('cal-next').addEventListener('click', () => {
  calMonth++;
  if (calMonth > 11) { calMonth = 0; calYear++; }
  renderCalendar();
});

// ── Time slots (cart-aware) ──────────────────────────────
// Converts an "HH:MM[:SS]" time on a given date into a unix timestamp (seconds).
function slotStartTs(date, time) {
  const [h, m] = time.split(':');
  return new Date(date + 'T' + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':00').getTime() / 1000;
}
// Windows already taken by appointments in the cart on a given date.
function cartWindowsOn(date) {
  return cart
    .filter(it => it.date === date)
    .map(it => {
      const s = slotStartTs(date, it.time);
      return [s, s + (parseInt(it.durationMins) || 60) * 60];
    });
}

async function loadTimeSlots(date) {
  const grid = document.getElementById('time-slots-grid');
  grid.innerHTML = '<p style="color:var(--color-text-muted);font-size:var(--text-sm)">Loading...</p>';
  draft.time = '';
  document.getElementById('booking-time').value = '';
  document.getElementById('step2-next').disabled = true;

  try {
    const res  = await fetch(`/api/slots?date=${date}&service_id=${draft.serviceId}`);
    const data = await res.json();
    grid.innerHTML = '';

    if (!data.slots || data.slots.length === 0) {
      grid.innerHTML = '<p style="color:var(--color-text-muted);font-size:var(--text-sm);grid-column:1/-1">No available times on this date. Please choose another day.</p>';
      return;
    }

    // Cart-aware greying: a slot is also unavailable if THIS appointment's window
    // [start, start+duration) overlaps any appointment already in the cart that day.
    const windows = cartWindowsOn(date);
    const durSec  = (parseInt(draft.durationMins) || 60) * 60;

    data.slots.forEach(slot => {
      let available = slot.available;
      if (available && windows.length) {
        const start = slotStartTs(date, slot.time);
        const end   = start + durSec;
        for (const w of windows) {
          if (start < w[1] && end > w[0]) { available = false; break; }
        }
      }
      const btn = document.createElement('button');
      btn.type = 'button'; // prevent accidental form submission
      btn.className = 'time-slot' + (available ? '' : ' slot-blocked');
      btn.textContent = slot.label;
      btn.dataset.time = slot.time;
      btn.disabled = !available;
      if (available) {
        btn.addEventListener('click', function() {
          document.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'));
          this.classList.add('selected');
          draft.time = slot.time;
          document.getElementById('booking-time').value = slot.time;
          document.getElementById('step2-next').disabled = false;
          updateSummary();
        });
      }
      grid.appendChild(btn);
    });
  } catch(e) {
    grid.innerHTML = '<p style="color:var(--color-error);font-size:var(--text-sm)">Could not load times. Please try again.</p>';
  }
}

// ── Step 3: show/hide payer contact depending on whether it's already set ──
function showStep3PayerState() {
  const block = document.getElementById('payer-contact-block');
  const note  = document.getElementById('payer-locked-note');
  if (payer.set) {
    block.style.display = 'none';
    note.style.display  = 'block';
    document.getElementById('payer-locked-name').textContent = payer.name;
  } else {
    block.style.display = 'block';
    note.style.display  = 'none';
  }
  // Pre-fill the guest field (first appointment defaults to "Myself")
  const guestInput = document.getElementById('guest-name');
  if (!guestInput.value) guestInput.value = (cart.length === 0 && !payer.set) ? 'Myself' : '';
}

// ── Step 3 validation ────────────────────────────────────
function validateStep3() {
  const guest = document.getElementById('guest-name').value.trim();
  if (!guest) {
    showToast('Please tell us who this appointment is for.', 'error');
    return;
  }

  // Collect payer contact details the first time only
  if (!payer.set) {
    const name  = document.getElementById('client-name').value.trim();
    const email = document.getElementById('client-email').value.trim();
    const phone = document.getElementById('client-phone').value.trim();
    if (!name || !email || !phone) {
      showToast('Please fill in all required fields.', 'error');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showToast('Please enter a valid email address.', 'error');
      return;
    }
    payer.name       = name;
    payer.email      = email;
    payer.phone      = phone;
    payer.emailOptin = document.getElementById('email-optin').checked;
    payer.set        = true;
  }

  draft.guestName    = guest;
  draft.notes        = document.getElementById('client-notes').value.trim();
  draft.mediaConsent = document.querySelector('input[name="media-consent"]:checked')?.value || 'none';
  goToStep(4);
}

// ── Pipeline recommendations ─────────────────────────────
async function loadPipelineRecommendations() {
  if (!draft.serviceId) return;
  document.getElementById('pipeline-loading').style.display       = 'block';
  document.getElementById('pipeline-recommendations').style.display = 'none';
  document.getElementById('pipeline-none').style.display           = 'none';

  try {
    const res  = await fetch(`/api/pipeline?service_id=${draft.serviceId}`);
    const data = await res.json();

    document.getElementById('pipeline-loading').style.display = 'none';

    if (!data.products || data.products.length === 0) {
      document.getElementById('pipeline-none').style.display = 'block';
      return;
    }

    const container = document.getElementById('pipeline-recommendations');
    container.innerHTML = '';

    data.products.forEach(product => {
      const isAdded = draft.pipelineProducts.some(p => p.id === product.id);
      const div = document.createElement('div');
      div.className = 'pipeline-recommendation';
      div.innerHTML = `
        <div class="pipeline-rec-inner">
          ${product.image_url
            ? `<img src="${product.image_url}" alt="${product.name}" class="pipeline-rec-img">`
            : `<div class="pipeline-rec-img-placeholder">🛍️</div>`
          }
          <div class="pipeline-rec-info">
            <p class="pipeline-rec-label">${product.display_label || 'Recommended for your style'}</p>
            <h4 class="rec-product-name">${product.name}</h4>
            ${product.description ? `<p class="pipeline-rec-desc">${product.description}</p>` : ''}
            ${product.free_gift ? `<span class="badge badge-gold">🎁 Free Gift Included</span>` : ''}
            <p class="rec-product-price">£${parseFloat(product.price).toFixed(2)}</p>
          </div>
          <div class="pipeline-rec-action">
            <button class="btn ${isAdded ? 'btn-outline-primary' : 'btn-primary'} pipeline-add-btn"
                    data-product-id="${product.id}"
                    data-product-name="${product.name}"
                    data-product-price="${product.price}"
                    onclick="togglePipelineProduct(this, ${JSON.stringify(product).replace(/"/g, '&quot;')})">
              ${isAdded ? '✓ Added' : 'Add to Order'}
            </button>
            <a href="/shop/${product.slug}" target="_blank"
               style="font-size:var(--text-xs);color:var(--color-text-muted);text-decoration:underline;display:block;margin-top:var(--space-2)">
              View details
            </a>
          </div>
        </div>`;
      container.appendChild(div);
    });

    container.style.display = 'block';
  } catch(e) {
    document.getElementById('pipeline-loading').style.display = 'none';
    document.getElementById('pipeline-none').style.display    = 'block';
  }
}

function togglePipelineProduct(btn, product) {
  const idx = draft.pipelineProducts.findIndex(p => p.id === product.id);
  if (idx > -1) {
    draft.pipelineProducts.splice(idx, 1);
    btn.textContent = 'Add to Order';
    btn.className   = btn.className.replace('btn-outline-primary', 'btn-primary');
    showToast(`"${product.name}" removed`, 'default');
  } else {
    draft.pipelineProducts.push(product);
    btn.textContent = '✓ Added';
    btn.className   = btn.className.replace('btn-primary', 'btn-outline-primary');
    showToast(`"${product.name}" added to your order! 🛍️`, 'success');
  }
  updateSummary();
}

// ── Cart management ──────────────────────────────────────
function addToCart() {
  if (!draft.serviceId || !draft.date || !draft.time) {
    showToast('Please complete the appointment details first.', 'error');
    return;
  }
  if (!draft.guestName) { showToast('Please tell us who this appointment is for.', 'error'); goToStep(3); return; }
  // Deep snapshot of the draft into the cart
  cart.push(JSON.parse(JSON.stringify(draft)));
  draft = freshDraft();
  resetDraftUI();
  updateSummary();
  showToast('Appointment added to your cart 🛍️', 'success');
  goToStep(5);
}

function addAnotherPerson() {
  // draft is already fresh after addToCart — return to step 1 to configure the next person
  resetDraftUI();
  goToStep(1);
  updateSummary();
}

function resetDraftUI() {
  document.querySelectorAll('.service-select-card').forEach(c => c.classList.remove('selected'));
  document.querySelectorAll('.variant-btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('variant-section').style.display   = 'none';
  document.getElementById('addons-section').style.display    = 'none';
  document.getElementById('prep-note-box').style.display     = 'none';
  document.getElementById('service-desc-box').style.display  = 'none';
  document.getElementById('addon-list').innerHTML            = '';
  document.getElementById('step1-next').disabled             = true;
  document.getElementById('step2-next').disabled             = true;
  document.getElementById('time-slots-section').style.display = 'none';
  document.getElementById('booking-date').value = '';
  document.getElementById('booking-time').value = '';
  document.getElementById('guest-name').value   = '';
  document.getElementById('client-notes').value = '';
  const mcNone = document.querySelector('input[name="media-consent"][value="none"]');
  if (mcNone) mcNone.checked = true;
  document.querySelectorAll('.cal-day').forEach(c => c.classList.remove('selected'));
}

function removeCartItem(idx) {
  cart.splice(idx, 1);
  showToast('Appointment removed', 'default');
  if (cart.length === 0) { goToStep(1); }
  else { renderPaymentStep(); }
  updateSummary();
}

// ── Money helpers ────────────────────────────────────────
function itemTotal(it) {
  const base   = parseFloat(it.servicePrice) || 0;
  const addons = (it.addons || []).reduce((s, a) => s + parseFloat(a.price), 0);
  const prods  = (it.pipelineProducts || []).reduce((s, p) => s + parseFloat(p.price), 0);
  return base + addons + prods;
}
function getDeposit(total) {
  return Math.round(total * (DEPOSIT_PCT / 100) * 100) / 100;
}
function itemDeposit(it) { return getDeposit(itemTotal(it)); }
function draftTotal()    { return draft.serviceId ? itemTotal(draft) : 0; }
function cartTotal()     { return cart.reduce((s, it) => s + itemTotal(it), 0); }
function cartDeposit()   { return cart.reduce((s, it) => s + itemDeposit(it), 0); }

// ── Home service (mobile) location ───────────────────────
// Home service is a whole-booking choice (one visit, one travel fee), available
// only when the combined booking total is >= HOME_SERVICE_MIN. The travel fee is
// paid in full now, on top of the deposit.
let locationType  = 'salon';   // 'salon' | 'home'
let travelAreaKey = '';

function homeEligible()      { return cartTotal() >= HOME_SERVICE_MIN; }
function currentTravelFee()  {
  if (locationType !== 'home' || !travelAreaKey) return 0;
  const a = TRAVEL_FEES.find(f => f.key === travelAreaKey);
  return a ? a.fee : 0;
}
function amountDueNow()      { return cartDeposit() + currentTravelFee(); }
function homeReady() {
  if (locationType !== 'home') return true;
  return !!travelAreaKey && document.getElementById('service-address').value.trim().length > 5;
}
function homePayload() {
  return {
    service_location: locationType,
    travel_area:      locationType === 'home' ? travelAreaKey : null,
    travel_fee:       currentTravelFee(),
    service_address:  locationType === 'home' ? document.getElementById('service-address').value.trim() : null,
  };
}

function populateTravelAreas() {
  const sel = document.getElementById('travel-area');
  if (!sel || sel.options.length > 1) return;
  TRAVEL_FEES.forEach(f => {
    const o = document.createElement('option');
    o.value = f.key;
    o.textContent = `${f.label} — £${f.fee.toFixed(0)}`;
    sel.appendChild(o);
  });
}

function selectLocationType(type) {
  if (type === 'home' && !homeEligible()) {
    showToast(`Home service is available on bookings £${HOME_SERVICE_MIN.toFixed(0)} and above.`, 'error');
    return;
  }
  locationType = type;
  const salonBtn = document.getElementById('loc-salon');
  const homeBtn  = document.getElementById('loc-home');
  const on  = b => { b.style.background='var(--color-primary)'; b.style.color='#fff';               b.style.borderColor='var(--color-primary)';       b.classList.add('active'); };
  const off = b => { b.style.background='#fff';                 b.style.color='var(--color-text)';   b.style.borderColor='var(--color-border,#E8D8EE)'; b.classList.remove('active'); };
  if (type === 'home') { on(homeBtn); off(salonBtn); }
  else {
    on(salonBtn); off(homeBtn);
    travelAreaKey = '';
    const s = document.getElementById('travel-area'); if (s) s.value = '';
  }
  document.getElementById('home-service-details').style.display = type === 'home' ? 'block' : 'none';
  refreshPaymentAmount();
}

function onTravelAreaChange() {
  travelAreaKey = document.getElementById('travel-area').value;
  refreshPaymentAmount();
}

// Re-render the review + payment amount when the location/fee changes.
function refreshPaymentAmount() { renderPaymentStep(); }

function updatePayButtonState() {
  const policy = document.getElementById('policy-checkbox');
  const ready  = policy && policy.checked && homeReady();
  const payBtn = document.getElementById('stripe-pay-btn');
  if (payBtn) payBtn.disabled = !ready;
  const bankChk = document.getElementById('bank-confirm-checkbox');
  const bankBtn = document.getElementById('bank-submit-btn');
  if (bankBtn) bankBtn.disabled = !(ready && bankChk && bankChk.checked);
}

// ── Payment step ─────────────────────────────────────────
let stripe, elements, paymentElement, paymentHandlersBound = false;

function renderPaymentStep() {
  const deposit = cartDeposit();
  const total   = cartTotal();
  const balance = total - deposit;

  // Build the cart review
  let rows = '';
  cart.forEach((it, idx) => {
    const t = itemTotal(it);
    const d = itemDeposit(it);
    const extras = []
      .concat((it.addons || []).map(a => a.name))
      .concat((it.pipelineProducts || []).map(p => p.name));
    const extrasHtml = extras.length
      ? `<div class="summary-row"><span class="label" style="font-size:var(--text-xs)">+ ${extras.join(', ')}</span><span class="value"></span></div>` : '';
    rows += `
      <div class="booking-summary" style="margin-bottom:var(--space-4)">
        <div class="booking-summary-header" style="display:flex;justify-content:space-between;align-items:center">
          <span>${it.guestName || payer.name}</span>
          <button type="button" onclick="removeCartItem(${idx})"
                  style="background:none;border:none;color:#fff;font-size:0.75rem;cursor:pointer;text-decoration:underline">Remove</button>
        </div>
        <div class="booking-summary-body">
          <div class="summary-row"><span class="label">Service</span><span class="value">${it.serviceName}${it.variantName ? ' — ' + it.variantName : ''}</span></div>
          <div class="summary-row"><span class="label">Date</span><span class="value">${formatDateDisplay(it.date)}</span></div>
          <div class="summary-row"><span class="label">Time</span><span class="value">${formatTimeDisplay(it.time)}</span></div>
          ${extrasHtml}
          <div class="summary-row"><span class="label">Price</span><span class="value">£${t.toFixed(2)}</span></div>
          <div class="summary-row deposit"><span class="label">Deposit (${DEPOSIT_PCT}%)</span><span class="value">£${d.toFixed(2)}</span></div>
        </div>
      </div>`;
  });

  // ── Home service gate + travel fee ──────────────────────
  const travelFee = currentTravelFee();
  const amountNow = deposit + travelFee;   // deposit + full travel, paid now
  const dueLater  = total - deposit;       // services balance (travel prepaid)

  const homeMinLabel = document.getElementById('home-min-label');
  if (homeMinLabel) homeMinLabel.textContent = HOME_SERVICE_MIN.toFixed(0);
  populateTravelAreas();
  const homeBtnEl = document.getElementById('loc-home');
  if (homeBtnEl) {
    const eligible = homeEligible();
    homeBtnEl.disabled     = !eligible;
    homeBtnEl.style.opacity = eligible ? '1' : '0.5';
    homeBtnEl.style.cursor  = eligible ? 'pointer' : 'not-allowed';
    // If the cart dropped below the threshold after a home selection, revert to salon.
    if (!eligible && locationType === 'home') {
      locationType = 'salon'; travelAreaKey = '';
      document.getElementById('home-service-details').style.display = 'none';
      const salonBtnEl = document.getElementById('loc-salon');
      salonBtnEl.style.background = 'var(--color-primary)'; salonBtnEl.style.color = '#fff'; salonBtnEl.classList.add('active');
      homeBtnEl.style.background = '#fff'; homeBtnEl.style.color = 'var(--color-text)'; homeBtnEl.classList.remove('active');
    }
  }

  const travelRow = travelFee > 0
    ? `<div class="summary-row"><span class="label">Home service travel (paid now)</span><span class="value">£${travelFee.toFixed(2)}</span></div>` : '';

  document.getElementById('booking-review-table').innerHTML = `
    ${rows}
    <div class="booking-summary" style="margin-bottom:var(--space-6);border:2px solid var(--color-primary)">
      <div class="booking-summary-header">Combined Total — ${cart.length} appointment${cart.length !== 1 ? 's' : ''}${travelFee > 0 ? ' + home service' : ''}</div>
      <div class="booking-summary-body">
        <div class="summary-row"><span class="label">Services</span><span class="value">£${total.toFixed(2)}</span></div>
        ${travelRow}
        <div class="summary-row deposit"><span class="label">Deposit (${DEPOSIT_PCT}% of services)</span><span class="value">£${deposit.toFixed(2)}</span></div>
        <div class="summary-row total"><span class="label">Pay now${travelFee > 0 ? ' (deposit + travel)' : ''}</span><span class="value">£${amountNow.toFixed(2)}</span></div>
        <div class="summary-row"><span class="label">Balance on the day</span><span class="value">£${dueLater.toFixed(2)}</span></div>
      </div>
    </div>`;

  document.getElementById('stripe-deposit-amount').textContent = '£' + amountNow.toFixed(2);
  document.getElementById('bank-deposit-label').textContent    = '£' + amountNow.toFixed(2);

  // Bank details — reference uses the payer's first name + first appointment date
  const firstDate = cart.length ? cart[0].date : '';
  const payerFirst = (payer.name || '').split(' ')[0].toUpperCase() || 'BOOKING';
  document.getElementById('bank-details-box').innerHTML = `
    <h4 style="color:var(--color-deep-purple);margin-bottom:var(--space-4)">🏦 Bank Transfer Details</h4>
    <div class="bank-detail-row"><span>Account Name:</span><strong>${BANK_NAME}</strong></div>
    <div class="bank-detail-row"><span>Sort Code:</span><strong>${BANK_SORT}</strong></div>
    <div class="bank-detail-row"><span>Account Number:</span><strong>${BANK_ACC}</strong></div>
    <div class="bank-detail-row"><span>Reference:</span><strong>${payerFirst}-${firstDate.replace(/-/g,'')}</strong></div>
    <div class="bank-detail-row total"><span>Amount to Transfer:</span><strong style="color:var(--color-primary)">£${amountNow.toFixed(2)}</strong></div>
    <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-top:var(--space-4)">
      ⚠️ Please use your name as the payment reference. Your booking will be held for 24 hours pending confirmation of payment.
    </p>`;

  // Init the Stripe Payment Element (once). Deferred-intent flow: the element is
  // mounted now with the deposit amount, and the PaymentIntent is created only
  // when the customer clicks Pay. Cards confirm inline; Klarna/Clearpay/PayPal
  // redirect out and return to the confirmation page.
  const depositPence = Math.max(30, Math.round(amountNow * 100));
  if (!stripe) {
    stripe = Stripe(STRIPE_KEY);
    elements = stripe.elements({ mode: 'payment', amount: depositPence, currency: 'gbp', locale: 'en-GB' });
    paymentElement = elements.create('payment', { layout: 'tabs' });
    paymentElement.mount('#stripe-payment-element');
  } else {
    elements.update({ amount: depositPence });
  }
  updatePayButtonState();

  // Bind policy/bank checkbox handlers once
  if (!paymentHandlersBound) {
    document.getElementById('policy-checkbox').addEventListener('change', updatePayButtonState);
    document.getElementById('bank-confirm-checkbox').addEventListener('change', updatePayButtonState);
    paymentHandlersBound = true;
  }
}

function selectPaymentMethod(method, btn) {
  paymentMethod = method;
  document.querySelectorAll('.payment-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('stripe-section').style.display        = method === 'stripe'        ? 'block' : 'none';
  document.getElementById('bank-transfer-section').style.display = method === 'bank_transfer' ? 'block' : 'none';
}

// ── Stripe payment submission (Payment Element, deferred intent) ──
async function submitStripePayment() {
  const btn   = document.getElementById('stripe-pay-btn');
  const errEl = document.getElementById('stripe-card-errors');
  const payLabel = '£' + amountDueNow().toFixed(2);
  if (!homeReady()) { showToast('Please select your area and enter your full address for home service.', 'error'); return; }
  btn.disabled = true;
  btn.textContent = 'Processing...';
  errEl.textContent = '';

  try {
    // 1. Validate the details entered in the Payment Element
    const { error: submitError } = await elements.submit();
    if (submitError) { errEl.textContent = submitError.message || 'Please check your payment details.'; resetStripeBtn(payLabel); return; }

    // 2. Create the pending booking(s) + PaymentIntent server-side
    const res = await fetch('/api/cart-intent', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        payer: { name: payer.name, email: payer.email, phone: payer.phone, email_optin: payer.emailOptin ? 1 : 0 },
        payment_method: 'stripe',
        items: buildCartItems(),
        ...homePayload()
      })
    });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    // 3. Confirm — cards resolve inline; Klarna/Clearpay/PayPal redirect out.
    const returnUrl = window.location.origin + '/booking/confirmation?ref=' + encodeURIComponent(data.ref);
    const { error, paymentIntent } = await stripe.confirmPayment({
      elements,
      clientSecret: data.client_secret,
      confirmParams: { return_url: returnUrl },
      redirect: 'if_required'
    });
    if (error) { errEl.textContent = error.message || 'Payment failed. Please try again.'; resetStripeBtn(payLabel); return; }

    // No redirect required (e.g. card) — go to the confirmation page, which finalizes.
    let url = '/booking/confirmation?ref=' + encodeURIComponent(data.ref);
    if (paymentIntent && paymentIntent.id) url += '&payment_intent=' + encodeURIComponent(paymentIntent.id);
    window.location.href = url;
  } catch(e) {
    showToast(e.message || 'Payment failed. Please try again.', 'error');
    resetStripeBtn(payLabel);
  }
}

function resetStripeBtn(depositLabel) {
  const btn = document.getElementById('stripe-pay-btn');
  btn.disabled = false;
  btn.textContent = 'Pay Deposit — ' + depositLabel;
}

// ── Bank transfer submission ─────────────────────────────
async function submitBankTransfer() {
  const btn = document.getElementById('bank-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Confirming...';
  try {
    await confirmCart('bank_transfer');
  } catch(e) {
    showToast(e.message || 'Error. Please try again.', 'error');
    btn.disabled = false;
    btn.textContent = 'Confirm Bank Transfer Booking';
  }
}

async function confirmCart(method) {
  if (!homeReady()) { throw new Error('Please select your area and enter your full address for home service.'); }
  const res = await fetch('/api/cart-intent', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      payer: { name: payer.name, email: payer.email, phone: payer.phone, email_optin: payer.emailOptin ? 1 : 0 },
      payment_method: method,
      items: buildCartItems(),
      ...homePayload()
    })
  });
  const data = await res.json();
  if (data.error) throw new Error(data.error);
  if (data.refs && data.refs.length) {
    window.location.href = '/booking/confirmation?ref=' + encodeURIComponent(data.refs[0]);
  }
}

function buildCartItems() {
  return cart.map(it => ({
    service_id:        it.serviceId,
    variant_id:        it.variantId,
    date:              it.date,
    time:              it.time,
    guest_name:        it.guestName,
    notes:             it.notes,
    addons:            (it.addons || []).map(a => a.id),
    pipeline_products: (it.pipelineProducts || []).map(p => ({ id: p.id, price: p.price })),
    media_consent:     it.mediaConsent || 'none',
    total:             itemTotal(it),
    deposit:           itemDeposit(it)
  }));
}

// ── Sidebar summary (cart + current draft) ───────────────
function renderCartSidebar() {
  const wrap = document.getElementById('cart-items');
  if (!cart.length) { wrap.innerHTML = ''; return; }
  wrap.innerHTML = cart.map((it, idx) => `
    <div class="summary-row" style="align-items:flex-start;border-bottom:1px dashed var(--color-border,#E8D8EE);padding-bottom:6px;margin-bottom:6px">
      <span class="label" style="flex:1">
        <strong>${it.guestName || payer.name}</strong><br>
        <span style="font-size:var(--text-xs);color:var(--color-text-muted)">${it.serviceName}${it.variantName ? ' — ' + it.variantName : ''}<br>${formatDateDisplay(it.date)} · ${formatTimeDisplay(it.time)}</span>
      </span>
      <span class="value" style="text-align:right">
        £${itemTotal(it).toFixed(2)}<br>
        <button type="button" onclick="removeCartItem(${idx})" style="background:none;border:none;color:var(--color-error,#c0392b);font-size:var(--text-xs);cursor:pointer;text-decoration:underline">Remove</button>
      </span>
    </div>`).join('');
}

function updateSummary() {
  renderCartSidebar();

  // Draft (current appointment) summary block
  const hasDraft = !!draft.serviceId;
  document.getElementById('draft-summary').style.display = hasDraft ? '' : 'none';
  if (hasDraft) {
    document.getElementById('sum-service').textContent = draft.serviceName || '—';
    document.getElementById('sum-variant').textContent = draft.variantName || (draft.servicePrice ? '£' + draft.servicePrice.toFixed(0) : '—');
    document.getElementById('sum-guest').textContent   = draft.guestName || '—';
    document.getElementById('sum-date').textContent    = draft.date ? formatDateDisplay(draft.date) : '—';
    document.getElementById('sum-time').textContent    = draft.time ? formatTimeDisplay(draft.time) : '—';
    if (draft.addons.length) {
      document.getElementById('sum-addons-row').style.display = '';
      document.getElementById('sum-addons').textContent = draft.addons.map(a => a.name).join(', ');
    } else {
      document.getElementById('sum-addons-row').style.display = 'none';
    }
  }

  // Combined totals = everything in the cart + the in-progress draft
  const total   = cartTotal() + draftTotal();
  const deposit = cartDeposit() + (hasDraft ? draftDeposit() : 0);
  const balance = total - deposit;
  document.getElementById('sum-total-label').textContent = cart.length ? 'Combined Total' : 'Total Price';
  document.getElementById('sum-total').textContent   = total   ? '£' + total.toFixed(2)   : '—';
  document.getElementById('sum-deposit').textContent = deposit ? '£' + deposit.toFixed(2) : '—';
  document.getElementById('sum-balance').textContent = balance ? '£' + balance.toFixed(2) : '—';
}
function draftDeposit() { return getDeposit(draftTotal()); }

function formatDateDisplay(d) {
  if (!d) return '—';
  return new Date(d + 'T12:00:00').toLocaleDateString('en-GB', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
}
function formatTimeDisplay(t) {
  if (!t) return '—';
  const [h, m] = t.split(':');
  const hr = parseInt(h);
  return `${hr > 12 ? hr - 12 : (hr === 0 ? 12 : hr)}:${m} ${hr >= 12 ? 'PM' : 'AM'}`;
}

// Init
updateSummary();
if (draft.serviceId) {
  document.querySelector(`[data-service-id="${draft.serviceId}"]`)?.classList.add('selected');
}
</script>

<script src="/assets/js/main.js"></script>
</body>
</html>
