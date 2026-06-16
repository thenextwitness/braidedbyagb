<?php
// ============================================================
// BraidedbyAGB — Review Submission Page
// FILE: /public/review.php
// Works TWO ways:
//   1. With ?token=xxx  — from post-appointment email (linked to booking)
//   2. Without token    — walk-in public form (anyone can leave a review)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db      = getDB();
$token   = sanitize($_GET['token'] ?? '');
$request = null;
$error   = '';
$success = false;
$successData = [];

// ── Try to load token-based request ───────────────────────
if ($token) {
    try {
        $stmt = $db->prepare("
            SELECT rr.*, c.name as c_name, c.email as c_email,
                   s.name as s_name
            FROM review_requests rr
            JOIN customers c ON c.id = rr.customer_id
            LEFT JOIN bookings b ON b.id = rr.booking_id
            LEFT JOIN services s ON s.id = b.service_id
            WHERE rr.token = ?
              AND rr.submitted_at IS NULL
              AND (rr.expires_at IS NULL OR rr.expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $request = $stmt->fetch();
        if (!$request) {
            $error = 'This review link has expired or has already been used. You can still leave a review using the form below.';
            $token = ''; // Fall through to public form
        }
    } catch (Exception $e) {
        error_log('Review token error: ' . $e->getMessage());
        $token = '';
    }
}

// ── Handle form submission ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating  = (int)($_POST['rating']      ?? 0);
    $text    = sanitize($_POST['review_text'] ?? '');
    $name    = sanitize($_POST['reviewer_name'] ?? ($request['c_name'] ?? ''));
    $email   = sanitizeEmail($_POST['reviewer_email'] ?? ($request['c_email'] ?? ''));
    $service = sanitize($_POST['service_name'] ?? ($request['s_name'] ?? ''));

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a star rating.';
    } elseif (strlen(trim($text)) < 10) {
        $error = 'Please write at least a short review (10+ characters).';
    } elseif (!$request && (!$name || !$email)) {
        $error = 'Please enter your name and email address.';
    } else {
        try {
            // If walk-in form: find or create customer
            $customerId   = null;
            $bookingId    = null;
            $reviewType   = 'service';

            if ($request) {
                $customerId = $request['customer_id'];
                $bookingId  = $request['booking_id'];
                $reviewType = $request['review_type'];
            } else {
                // Walk-in: upsert customer
                $db->prepare("INSERT INTO customers (name, email) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
                   ->execute([$name, $email]);
                $r = $db->prepare("SELECT id FROM customers WHERE email=?");
                $r->execute([$email]);
                $customerId = (int)$r->fetchColumn();
            }

            // Save review
            $db->prepare("
                INSERT INTO reviews
                    (customer_id, booking_id, review_type, rating, review_text, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ")->execute([$customerId, $bookingId, $reviewType, $rating, $text]);
            $reviewId = (int)$db->lastInsertId();

            // Mark token request as submitted
            if ($request && $token) {
                $db->prepare("UPDATE review_requests SET submitted_at = NOW() WHERE token = ?")
                   ->execute([$token]);
            }

            // Notify admin (non-fatal)
            try {
                if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $customer = ['name' => $name ?: ($request['c_name'] ?? 'Client'), 'email' => $email];
                    $review   = ['rating' => $rating, 'review_text' => $text];
                    emailAdminNewReview($review, $customer);
                }
            } catch (Throwable $e) {
                error_log('Review admin email error: ' . $e->getMessage());
            }

            // Incentive code (for token-based only)
            $incentiveCode = null;
            if ($request && getSetting('review_incentive_enabled', '0') === '1') {
                $discount = (int)getSetting('review_incentive_discount', '10');
                $code = 'THANKYOU' . strtoupper(substr(generateToken(6), 0, 6));
                $db->prepare("INSERT INTO discount_codes (code, type, value, uses_limit, customer_id, expiry_date, is_active) VALUES (?, 'percent', ?, 1, ?, DATE_ADD(NOW(), INTERVAL 60 DAY), 1)")
                   ->execute([$code, $discount, $customerId]);
                $incentiveCode = $code;
            }

            $success     = true;
            $successData = ['code' => $incentiveCode, 'rating' => $rating, 'name' => $name ?: ($request['c_name'] ?? '')];

        } catch (Exception $e) {
            error_log('Review submit error: ' . $e->getMessage());
            $error = 'Sorry, your review could not be submitted. Please try again or contact us.';
        }
    }
}

$greeting = $request ? 'Hi ' . htmlspecialchars(explode(' ', $request['c_name'])[0]) . '!' : '';
$serviceName = $request['s_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Leave a Review — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <style>
    .review-page-wrap { max-width: 620px; margin: 0 auto; padding: 0 var(--space-6); }
    .star-picker { display: flex; gap: var(--space-2); justify-content: center; cursor: pointer; margin: var(--space-3) 0; }
    .star-pick { font-size: 2.8rem; color: var(--color-border); transition: color 0.15s ease, transform 0.15s ease; line-height: 1; }
    .star-pick.lit { color: var(--color-gold); }
    .star-pick:hover { transform: scale(1.15); }
    .photo-upload-area {
      border: 2px dashed var(--color-border); border-radius: var(--border-radius-lg);
      padding: var(--space-8); text-align: center; cursor: pointer; transition: var(--transition);
    }
    .photo-upload-area:hover { border-color: var(--color-primary); background: var(--color-bg-light); }
    .photo-upload-area input[type="file"] { display: none; }
    #photo-preview { max-width: 100%; max-height: 200px; margin: var(--space-3) auto 0; border-radius: var(--border-radius); display: none; }
    .info-notice { background: var(--color-bg-light); border-left: 3px solid var(--color-gold); padding: var(--space-3) var(--space-4); border-radius: var(--border-radius); font-size: var(--text-sm); color: var(--color-text-muted); margin-bottom: var(--space-6); }
  </style>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content" style="background:var(--color-bg-light);min-height:100vh;padding:var(--space-20) 0">

  <div class="review-page-wrap">

    <?php if ($success): ?>
    <!-- ── SUCCESS ─────────────────────────────────── -->
    <div style="text-align:center;padding:var(--space-10) 0">
      <p style="font-size:4rem;margin-bottom:var(--space-4)">💜</p>
      <h1 style="font-size:var(--text-3xl);color:var(--color-deep-purple);margin-bottom:var(--space-3)">
        Thank You<?= $successData['name'] ? ', ' . htmlspecialchars(explode(' ', $successData['name'])[0]) : '' ?>!
      </h1>
      <p style="color:var(--color-text-muted);font-size:var(--text-lg);line-height:1.7;margin-bottom:var(--space-6)">
        Your review has been submitted and is awaiting approval. We truly appreciate you taking the time to share your experience! 🌟
      </p>
      <?php if (!empty($successData['code'])): ?>
      <div style="background:linear-gradient(135deg,var(--color-deep-purple),var(--color-primary));border-radius:var(--border-radius-lg);padding:var(--space-8);color:white;margin:var(--space-6) 0;text-align:center">
        <p style="font-family:var(--font-primary);font-size:0.7rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--color-gold);margin-bottom:var(--space-2)">Your Exclusive Discount</p>
        <p style="font-family:var(--font-primary);font-size:2rem;font-weight:900;letter-spacing:0.1em;margin:var(--space-2) 0"><?= htmlspecialchars($successData['code']) ?></p>
        <p style="font-size:var(--text-sm);color:rgba(255,255,255,0.75)"><?= getSetting('review_incentive_discount','10') ?>% off your next booking — valid for 60 days</p>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:var(--space-4);justify-content:center;flex-wrap:wrap;margin-top:var(--space-6)">
        <a href="/booking" class="btn btn-primary">Book Another Appointment</a>
        <a href="/" class="btn btn-outline-primary">Back to Home</a>
      </div>
      <p style="margin-top:var(--space-8);font-size:var(--text-sm);color:var(--color-text-muted)">
        Don't forget to tag us on Instagram — <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener">@BraidedbyAGB</a> 💜
      </p>
    </div>

    <?php else: ?>
    <!-- ── FORM ────────────────────────────────────── -->
    <div style="text-align:center;margin-bottom:var(--space-8)">
      <p style="font-size:2.5rem;margin-bottom:var(--space-3)">⭐</p>
      <h1 style="font-size:var(--text-2xl);color:var(--color-deep-purple);margin-bottom:var(--space-2)">
        <?= $greeting ? $greeting . ' How Was Your Experience?' : 'Leave a Review' ?>
      </h1>
      <?php if ($serviceName): ?>
        <p style="color:var(--color-text-muted)">We'd love to hear what you thought about your <?= htmlspecialchars($serviceName) ?>.</p>
      <?php else: ?>
        <p style="color:var(--color-text-muted)">We'd love to hear about your experience at BraidedbyAGB.</p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div style="background:#FDE8E8;border:1px solid #F5A8A8;padding:var(--space-4);border-radius:var(--border-radius);color:var(--color-error);margin-bottom:var(--space-6);font-size:var(--text-sm)">
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div style="background:var(--color-white);border-radius:var(--border-radius-xl);padding:var(--space-10);box-shadow:var(--shadow-lg)">

      <?php if (!$request): ?>
      <div class="info-notice">
        ✦ Had your hair done at BraidedbyAGB? We'd love your feedback! Your review helps other clients and means the world to us.
      </div>
      <?php endif; ?>

      <form method="POST" id="review-form">

        <!-- Name + Email (walk-in only) -->
        <?php if (!$request): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);margin-bottom:var(--space-5)">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="reviewer_name">Your Name *</label>
            <input class="form-control" type="text" id="reviewer_name" name="reviewer_name"
                   value="<?= htmlspecialchars($_POST['reviewer_name'] ?? '') ?>"
                   placeholder="e.g. Amara" required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="reviewer_email">Email Address *</label>
            <input class="form-control" type="email" id="reviewer_email" name="reviewer_email"
                   value="<?= htmlspecialchars($_POST['reviewer_email'] ?? '') ?>"
                   placeholder="your@email.com" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="service_name">Which Service Did You Have?</label>
          <input class="form-control" type="text" id="service_name" name="service_name"
                 value="<?= htmlspecialchars($_POST['service_name'] ?? '') ?>"
                 placeholder="e.g. Knotless Braids, Box Braids...">
        </div>
        <?php endif; ?>

        <!-- Star Rating -->
        <div class="form-group" style="text-align:center">
          <label class="form-label" style="display:block;text-align:center;margin-bottom:var(--space-2)">Your Rating *</label>
          <div class="star-picker" id="star-picker">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="star-pick" data-value="<?= $i ?>">★</span>
            <?php endfor; ?>
          </div>
          <input type="hidden" id="rating-input" name="rating" value="0" required>
          <p id="rating-label" style="font-family:var(--font-primary);font-size:0.75rem;color:var(--color-text-muted);margin-top:var(--space-2)">Click to rate</p>
        </div>

        <!-- Review Text -->
        <div class="form-group">
          <label class="form-label" for="review_text">Your Review *</label>
          <textarea class="form-control" id="review_text" name="review_text"
                    rows="5" placeholder="Tell us about your experience — what did you love most about your style?"
                    required minlength="10"><?= htmlspecialchars($_POST['review_text'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary w-full btn-lg" id="review-submit">
          Submit My Review ★
        </button>

        <p style="text-align:center;font-size:var(--text-xs);color:var(--color-text-light);margin-top:var(--space-4)">
          Your review will be published after a quick check. Thank you! 💜
        </p>
      </form>
    </div>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script>
// Star rating picker
const stars     = document.querySelectorAll('.star-pick');
const ratingInput = document.getElementById('rating-input');
const ratingLabel = document.getElementById('rating-label');
const labels    = ['','Poor','Fair','Good','Great','Excellent!'];

if (stars.length) {
  stars.forEach(s => {
    s.addEventListener('mouseover', () => highlightStars(+s.dataset.value));
    s.addEventListener('mouseout',  () => highlightStars(+ratingInput.value));
    s.addEventListener('click', () => {
      ratingInput.value = s.dataset.value;
      highlightStars(+s.dataset.value);
      if (ratingLabel) ratingLabel.textContent = labels[+s.dataset.value] || '';
    });
  });
}

function highlightStars(n) {
  stars.forEach((s, i) => s.classList.toggle('lit', i < n));
}

// Photo preview
const photoInput = document.getElementById('review-photo');
if (photoInput) {
  photoInput.addEventListener('change', function() {
    const preview = document.getElementById('photo-preview');
    if (this.files[0]) {
      const reader = new FileReader();
      reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
      reader.readAsDataURL(this.files[0]);
    }
  });
}

// Validate on submit
document.getElementById('review-form')?.addEventListener('submit', function(e) {
  if (!ratingInput || ratingInput.value === '0') {
    e.preventDefault();
    alert('Please select a star rating first.');
  }
});
</script>
</body>
</html>
