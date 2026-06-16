<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
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
  <title>Policies — BraidedbyAGB</title>
  <meta name="description" content="BraidedbyAGB booking, cancellation, late arrival, refund and shipping policies.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/policies">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/policies">
  <meta property="og:title" content="Policies — BraidedbyAGB">
  <meta property="og:description" content="BraidedbyAGB booking, cancellation, late arrival, refund and shipping policies.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">

  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Important Information</span></p>
        <h1 class="page-hero-title">Our Policies</h1>
        <p class="page-hero-subtitle">Please read our policies before booking your appointment.</p>
      </div>
    </div>
  </section>

  <section style="background:var(--color-white);padding-bottom:var(--space-20)">
    <div class="container">
      <div class="policies-grid">

        <!-- Nav -->
        <nav class="policies-nav" aria-label="Policy sections">
          <a href="#deposit"       class="policies-nav-link">Deposit Policy</a>
          <a href="#cancellation"  class="policies-nav-link">Cancellation Policy</a>
          <a href="#late-arrival"  class="policies-nav-link">Late Arrival Policy</a>
          <a href="#no-show"       class="policies-nav-link">No-Show Policy</a>
          <a href="#refund"        class="policies-nav-link">Refund Policy</a>
          <a href="#shipping"      class="policies-nav-link">Shipping Policy</a>
          <a href="#privacy"       class="policies-nav-link">Privacy Policy</a>
        </nav>

        <!-- Content -->
        <div class="policies-content">

          <div class="policy-section" id="deposit">
            <h2 class="policy-section-title">💳 Deposit Policy</h2>
            <p>A <strong>non-refundable deposit of 30%</strong> of the total service price is required to confirm all appointments. This deposit secures your time slot and covers preparation costs.</p>
            <div class="policy-highlight">
              <strong>Important:</strong> Your appointment is not confirmed until the deposit has been received. Bookings without a deposit will be held for 24 hours (bank transfer) before being automatically cancelled.
            </div>
            <p>The remaining balance is payable <strong>on the day of your appointment</strong>, either in cash or via bank transfer.</p>
            <p>Deposits can be paid by card (Stripe) or bank transfer at the time of booking.</p>
          </div>

          <div class="policy-section" id="cancellation">
            <h2 class="policy-section-title">📅 Cancellation Policy</h2>
            <p>We understand that life happens. However, late cancellations affect other clients and our scheduling significantly.</p>
            <div class="policy-highlight">
              <strong>Cancellations must be made at least 48 hours before your appointment.</strong> Cancellations made within 48 hours of the appointment will result in the deposit being forfeited.
            </div>
            <p>To cancel your appointment, please WhatsApp us on <a href="https://wa.me/447769064971">07769 064 971</a> with your booking reference number.</p>
            <p>If you cancel with more than 48 hours' notice and wish to rebook, your deposit will be transferred to a new appointment within 30 days.</p>
          </div>

          <div class="policy-section" id="late-arrival">
            <h2 class="policy-section-title">⏰ Late Arrival Policy</h2>
            <div class="policy-highlight" style="border-color:var(--color-primary)">
              <strong>Clients are expected to arrive within 20 minutes of their scheduled appointment time.</strong> Arrivals after 20 minutes may result in your appointment being cancelled and your deposit being forfeited.
            </div>
            <p>We operate on a tight schedule to ensure every client receives the full time and care they deserve. Late arrivals affect not only your appointment but those of other clients.</p>
            <p><strong>If you are running late, please WhatsApp us immediately on <a href="https://wa.me/447769064971">07769 064 971</a></strong> so we can do our best to accommodate you. Communication is key — we are always willing to work with you where possible.</p>
            <p>Persistent late arrivals (more than twice) may result in being required to pay a full upfront payment for future bookings.</p>
          </div>

          <div class="policy-section" id="no-show">
            <h2 class="policy-section-title">🚫 No-Show Policy</h2>
            <p>A no-show is defined as failing to attend your appointment without prior notice.</p>
            <div class="policy-highlight">
              <strong>No-shows will result in the full deposit being forfeited.</strong> Clients with two or more no-shows may be required to pay the full service cost upfront for future bookings.
            </div>
            <p>We hold your appointment time exclusively for you. Please respect both our time and the time of other clients by letting us know as early as possible if you cannot attend.</p>
          </div>

          <div class="policy-section" id="refund">
            <h2 class="policy-section-title">💜 Refund Policy</h2>
            <h3 style="font-size:var(--text-lg);color:var(--color-deep-purple);margin:var(--space-4) 0 var(--space-2)">Services</h3>
            <p>Deposits are strictly non-refundable. If you are unhappy with your service, please let us know <strong>before you leave</strong> so we can address any concerns. We take client satisfaction very seriously and will always work to make things right.</p>
            <h3 style="font-size:var(--text-lg);color:var(--color-deep-purple);margin:var(--space-4) 0 var(--space-2)">Products</h3>
            <p>We do not accept returns or offer refunds on <strong>opened hair products</strong> for hygiene reasons. Unopened products in their original packaging may be returned within 14 days of purchase with proof of purchase.</p>
            <div class="policy-highlight">
              If your product arrives damaged or incorrect, please WhatsApp us within 48 hours of receipt with photos and we will resolve the issue promptly.
            </div>
          </div>

          <div class="policy-section" id="shipping">
            <h2 class="policy-section-title">📦 Shipping Policy</h2>
            <p>We offer <strong>UK standard delivery</strong> on all products. Orders are typically dispatched within 1–2 business days.</p>
            <div class="policy-highlight">
              <strong>Estimated delivery times:</strong><br>
              Standard UK delivery: 2–5 working days<br>
              Local Farnborough pickup: Available by arrangement (free of charge)
            </div>
            <p>You will receive an email notification when your order is dispatched. Shipping costs are calculated at checkout based on order size and destination.</p>
            <p>We are not responsible for delays caused by Royal Mail or other carriers. For time-sensitive orders, please contact us before placing your order.</p>
          </div>

          <div class="policy-section" id="privacy">
            <h2 class="policy-section-title">🔒 Privacy Policy</h2>
            <p>BraidedbyAGB is committed to protecting your personal data in accordance with the <strong>UK General Data Protection Regulation (UK GDPR)</strong>.</p>
            <p><strong>What we collect:</strong> Name, email address, phone number, and appointment/order details provided at the time of booking or purchase.</p>
            <p><strong>How we use it:</strong> To process your bookings and orders, send appointment reminders and order updates, and to occasionally contact you about our services (only with your consent).</p>
            <p><strong>Who we share it with:</strong> We do not sell or share your personal data with third parties, except where required to process payments (Stripe) or deliver orders.</p>
            <p><strong>Your rights:</strong> You have the right to access, correct, or request deletion of your personal data at any time. To exercise these rights, please email us at <a href="mailto:hello@braidedbyagb.co.uk">hello@braidedbyagb.co.uk</a>.</p>
            <p><strong>Data retention:</strong> We retain your data for as long as necessary to provide our services and comply with legal obligations.</p>
            <p>By booking an appointment or placing an order, you consent to the above use of your personal data.</p>
          </div>

        </div>
      </div>
    </div>
  </section>

  <section class="cta-strip">
    <div class="container">
      <div class="cta-strip-inner">
        <h3 class="cta-strip-title">Questions about our policies?</h3>
        <a href="https://wa.me/447769064971" class="btn btn-gold btn-lg">WhatsApp Us — 07769 064 971</a>
      </div>
    </div>
  </section>

</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script>
// Highlight active policy nav link on scroll
const sections = document.querySelectorAll('.policy-section');
const navLinks  = document.querySelectorAll('.policies-nav-link');
window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(s => {
    if (window.scrollY >= s.offsetTop - 120) current = s.id;
  });
  navLinks.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#' + current));
}, { passive: true });
</script>
</body>
</html>
