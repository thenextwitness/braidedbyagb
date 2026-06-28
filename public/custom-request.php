<?php
// ============================================================
// BraidedbyAGB — Custom Service Request Form
// FILE: /public/custom-request.php
// Route: /custom-request (add to .htaccess)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDB();

$sent  = false;
$error = '';

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security error. Please refresh and try again.';
    } elseif (!verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $error = 'Please complete the reCAPTCHA verification.';
    } else {
        $name        = sanitize($_POST['name']         ?? '');
        $email       = sanitizeEmail($_POST['email']   ?? '');
        $phone       = sanitize($_POST['phone']        ?? '');
        $styleDesc   = sanitize($_POST['style_desc']   ?? '');
        $hairLength  = sanitize($_POST['hair_length']  ?? '');
        $prefDate    = sanitize($_POST['preferred_date'] ?? '');
        $budgetRange = sanitize($_POST['budget_range'] ?? '');

        // Validation
        if (!$name || !$email || !$styleDesc) {
            $error = 'Please fill in your name, email and style description.';
        } elseif (!validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Handle image upload
            $inspirationUrl = null;
            if (!empty($_FILES['inspiration_image']['name'])) {
                $upload = uploadImage($_FILES['inspiration_image'], 'custom-requests');
                if ($upload === false) {
                    $error = 'Image upload failed. Please use JPG, PNG or WebP under 5MB.';
                } else {
                    $inspirationUrl = $upload;
                }
            }

            if (!$error) {
                // Generate reference
                $ref = 'AGBCR-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

                // Validate hair length
                $validLengths = ['short','medium','long','extra_long'];
                $hairLength = in_array($hairLength, $validLengths) ? $hairLength : null;
                $prefDate   = $prefDate ? $prefDate : null;

                $db->prepare("
                    INSERT INTO custom_requests
                        (ref, name, email, phone, style_desc, inspiration_url, hair_length, preferred_date, budget_range, status)
                    VALUES (?,?,?,?,?,?,?,?,?,'new')
                ")->execute([$ref, $name, $email, $phone, $styleDesc, $inspirationUrl, $hairLength, $prefDate, $budgetRange]);

                $requestId = $db->lastInsertId();

                // Email notifications
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    emailCustomRequestReceived([
                        'ref'        => $ref,
                        'name'       => $name,
                        'email'      => $email,
                        'style_desc' => $styleDesc,
                    ]);
                    emailAdminNewCustomRequest([
                        'id'          => $requestId,
                        'ref'         => $ref,
                        'name'        => $name,
                        'email'       => $email,
                        'phone'       => $phone,
                        'style_desc'  => $styleDesc,
                        'budget_range'=> $budgetRange,
                        'preferred_date' => $prefDate,
                    ]);
                } catch (Throwable $e) {
                    error_log('Custom request email error: ' . $e->getMessage());
                }

                $sent = true;
            }
        }
    }
}

$pageTitle = 'Custom Style Request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> — BraidedbyAGB</title>
  <meta name="description" content="Can't find your style on our menu? Describe your dream look and we'll create a custom quote just for you.">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300;1,400&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <script src="https://www.google.com/recaptcha/api.js?render=<?= RECAPTCHA_SITE_KEY ?>"></script>
  <style>
    .cr-hero {
      background: linear-gradient(135deg, #2d0050 0%, #7a0050 50%, #4B0082 100%);
      padding: 80px 0 60px;
      text-align: center;
    }
    .cr-hero-eyebrow {
      font-family: var(--font-primary);
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.25em;
      text-transform: uppercase;
      color: var(--color-gold);
      margin-bottom: 14px;
    }
    .cr-hero h1 {
      font-family: var(--font-primary);
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 900;
      color: #fff;
      margin: 0 0 16px;
      line-height: 1.15;
    }
    .cr-hero p {
      color: rgba(255,255,255,0.72);
      font-size: 1.05rem;
      max-width: 520px;
      margin: 0 auto;
      line-height: 1.7;
    }
    .cr-section { padding: 64px 0 80px; background: #faf8fd; }
    .cr-container { max-width: 760px; margin: 0 auto; padding: 0 24px; }
    .cr-card {
      background: #fff;
      border: 1px solid #e8d8f0;
      border-radius: 16px;
      padding: 40px 44px;
      box-shadow: 0 8px 40px rgba(75,0,130,0.07);
    }
    @media (max-width: 600px) {
      .cr-card { padding: 28px 20px; }
    }
    .cr-section-label {
      font-family: var(--font-primary);
      font-size: 0.62rem;
      font-weight: 700;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--color-primary);
      margin-bottom: 18px;
      padding-bottom: 8px;
      border-bottom: 2px solid #f0e0f8;
    }
    .cr-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 560px) { .cr-grid-2 { grid-template-columns: 1fr; } }
    .cr-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
    .cr-field:last-child { margin-bottom: 0; }
    .cr-label {
      font-family: var(--font-primary);
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: #5c3a6e;
    }
    .cr-label .opt { font-weight: 400; opacity: 0.55; text-transform: none; letter-spacing: 0; }
    .cr-input, .cr-select, .cr-textarea {
      font-family: var(--font-secondary);
      font-size: 0.95rem;
      padding: 11px 14px;
      border: 1.5px solid #ddd0e8;
      border-radius: 8px;
      background: #fdf8ff;
      color: #1a0014;
      transition: border-color 0.2s, box-shadow 0.2s;
      outline: none;
      width: 100%;
      box-sizing: border-box;
    }
    .cr-input:focus, .cr-select:focus, .cr-textarea:focus {
      border-color: var(--color-primary);
      background: #fff;
      box-shadow: 0 0 0 3px rgba(204,26,138,0.1);
    }
    .cr-textarea { resize: vertical; min-height: 120px; }
    .cr-upload-zone {
      border: 2px dashed #d0b8e0;
      border-radius: 10px;
      padding: 28px 20px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.2s, background 0.2s;
      background: #fdf8ff;
      position: relative;
    }
    .cr-upload-zone:hover, .cr-upload-zone.dragover {
      border-color: var(--color-primary);
      background: #fef0fa;
    }
    .cr-upload-zone input[type="file"] {
      position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
    }
    .cr-upload-icon { font-size: 2rem; margin-bottom: 8px; }
    .cr-upload-text { font-size: 0.85rem; color: #7a5a8a; line-height: 1.5; }
    .cr-upload-text strong { color: var(--color-primary); }
    #upload-preview {
      margin-top: 14px; display: none;
      align-items: center; gap: 12px;
      padding: 10px 14px;
      background: #f0fdf4;
      border: 1px solid #86efac;
      border-radius: 8px;
    }
    #upload-preview img { width: 56px; height: 56px; object-fit: cover; border-radius: 6px; }
    #upload-preview span { font-size: 0.8rem; color: #166534; flex: 1; }
    #upload-preview button { background: none; border: none; cursor: pointer; color: #dc2626; font-size: 1.1rem; }
    .cr-hint { font-size: 0.73rem; color: #9a7aaa; margin-top: 4px; }
    .cr-alert-error {
      background: #fef2f2;
      border: 1px solid #fca5a5;
      border-left: 4px solid #dc2626;
      color: #991b1b;
      padding: 13px 16px;
      border-radius: 8px;
      font-size: 0.88rem;
      margin-bottom: 22px;
    }
    .cr-submit {
      width: 100%;
      padding: 15px 24px;
      background: linear-gradient(135deg, var(--color-primary), #9400d3);
      color: #fff;
      font-family: var(--font-primary);
      font-weight: 800;
      font-size: 0.9rem;
      letter-spacing: 0.08em;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      transition: opacity 0.2s, transform 0.15s;
      margin-top: 8px;
    }
    .cr-submit:hover { opacity: 0.92; transform: translateY(-1px); }
    .cr-submit:active { transform: translateY(0); }
    .cr-success {
      text-align: center;
      padding: 20px 0 10px;
    }
    .cr-success-icon { font-size: 3.5rem; margin-bottom: 16px; }
    .cr-success h2 {
      font-family: var(--font-primary);
      font-size: 1.6rem;
      font-weight: 900;
      color: #4B0082;
      margin-bottom: 12px;
    }
    .cr-success p { color: #5c3a6e; font-size: 0.95rem; line-height: 1.7; margin-bottom: 12px; }
    .cr-success .ref-tag {
      display: inline-block;
      background: #f0e8fa;
      border: 1px solid #c8a0e8;
      color: #4B0082;
      font-family: var(--font-primary);
      font-weight: 700;
      font-size: 0.85rem;
      padding: 6px 16px;
      border-radius: 20px;
      margin: 8px 0 20px;
      letter-spacing: 0.05em;
    }
    .cr-back-link {
      display: inline-block;
      margin-top: 20px;
      color: rgba(255,255,255,0.55);
      font-size: 0.8rem;
      font-family: var(--font-primary);
      font-weight: 600;
      text-decoration: none;
      letter-spacing: 0.04em;
      border-bottom: 1px solid rgba(255,255,255,0.2);
      padding-bottom: 1px;
      transition: color 0.2s, border-color 0.2s;
    }
    .cr-back-link:hover {
      color: rgba(255,255,255,0.9);
      border-color: rgba(255,255,255,0.5);
    }
    .cr-perks {
      display: flex; gap: 20px; flex-wrap: wrap;
      background: linear-gradient(135deg, #f9f0ff, #fff0f8);
      border: 1px solid #e8c8f0;
      border-radius: 12px;
      padding: 22px 24px;
      margin-bottom: 28px;
    }
    .cr-perk { display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 180px; }
    .cr-perk-icon { font-size: 1.4rem; flex-shrink: 0; margin-top: 2px; }
    .cr-perk-text strong { display: block; font-family: var(--font-primary); font-size: 0.78rem; font-weight: 700; color: #4B0082; margin-bottom: 2px; }
    .cr-perk-text span { font-size: 0.73rem; color: #7a5a8a; line-height: 1.5; }
  </style>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
<?php if ($sent): ?>
<!-- Event snippet for Request quote conversion -->
<script>gtag('event', 'conversion', {'send_to': 'AW-17943670219/k6cyCKr-5sYcEMvbmuxC'});</script>
<?php endif; ?>
</head>
<body>

<?php include __DIR__ . '/../includes/nav.php'; ?>

<!-- Hero -->
<section class="cr-hero">
  <div class="cr-container">
    <p class="cr-hero-eyebrow">✦ Made for you</p>
    <h1>Request a Custom Style</h1>
    <p>Can't find exactly what you're looking for? Describe your dream look and we'll get back to you with a personalised quote.</p>
    <a href="/booking" class="cr-back-link">← Back to standard booking</a>
  </div>
</section>

<section class="cr-section">
  <div class="cr-container">

    <!-- Perks strip -->
    <div class="cr-perks">
      <div class="cr-perk">
        <span class="cr-perk-icon">✨</span>
        <div class="cr-perk-text">
          <strong>Fully bespoke</strong>
          <span>Bring your inspiration — we'll make it happen.</span>
        </div>
      </div>
      <div class="cr-perk">
        <span class="cr-perk-icon">💬</span>
        <div class="cr-perk-text">
          <strong>Quick response</strong>
          <span>We reply within 24 hours with your custom quote.</span>
        </div>
      </div>
      <div class="cr-perk">
        <span class="cr-perk-icon">🔒</span>
        <div class="cr-perk-text">
          <strong>No obligation</strong>
          <span>A quote is just a quote — no commitment required.</span>
        </div>
      </div>
    </div>

    <div class="cr-card">

      <?php if ($sent): ?>
      <!-- ── Success state ─────────────────────────────── -->
      <div class="cr-success">
        <div class="cr-success-icon">💜</div>
        <h2>Request received!</h2>
        <p>Thank you, we've received your custom style request and we'll get back to you within 24 hours with a personalised quote.</p>
        <div class="ref-tag">Your reference: <?= htmlspecialchars($ref ?? '') ?></div>
        <p>Keep an eye on your inbox — and feel free to WhatsApp us if you'd like to chat sooner.</p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:8px">
          <a href="/booking" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,var(--color-primary),#9400d3);color:#fff;font-family:var(--font-primary);font-weight:700;font-size:0.82rem;letter-spacing:0.08em;text-decoration:none;border-radius:8px;">
            Browse Standard Services
          </a>
          <a href="https://wa.me/447769064971" target="_blank" rel="noopener"
             style="display:inline-block;padding:12px 28px;background:#25D366;color:#fff;font-family:var(--font-primary);font-weight:700;font-size:0.82rem;letter-spacing:0.08em;text-decoration:none;border-radius:8px;">
            💬 WhatsApp Us
          </a>
        </div>
      </div>

      <?php else: ?>
      <!-- ── Request form ──────────────────────────────── -->
      <?php if ($error): ?>
        <div class="cr-alert-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" action="/custom-request" enctype="multipart/form-data" id="cr-form">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

        <!-- Contact info -->
        <p class="cr-section-label">Your Details</p>
        <div class="cr-grid-2" style="margin-bottom:18px">
          <div class="cr-field" style="margin-bottom:0">
            <label class="cr-label" for="cr-name">Full Name *</label>
            <input class="cr-input" type="text" id="cr-name" name="name"
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                   placeholder="e.g. Adaeze Williams" required autocomplete="name">
          </div>
          <div class="cr-field" style="margin-bottom:0">
            <label class="cr-label" for="cr-email">Email Address *</label>
            <input class="cr-input" type="email" id="cr-email" name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="you@email.com" required autocomplete="email">
          </div>
        </div>
        <div class="cr-field">
          <label class="cr-label" for="cr-phone">Phone / WhatsApp <span class="opt">(optional)</span></label>
          <input class="cr-input" type="tel" id="cr-phone" name="phone"
                 value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                 placeholder="07700 000 000" autocomplete="tel">
        </div>

        <!-- Style details -->
        <p class="cr-section-label" style="margin-top:28px">Your Style Request</p>
        <div class="cr-field">
          <label class="cr-label" for="cr-style">Describe Your Ideal Style *</label>
          <textarea class="cr-textarea" id="cr-style" name="style_desc" rows="5"
                    placeholder="Tell us as much as you can — type of braids, length, thickness, colour, parting style, any specific technique... The more detail the better!"
                    required><?= htmlspecialchars($_POST['style_desc'] ?? '') ?></textarea>
        </div>

        <!-- Inspiration image upload -->
        <div class="cr-field">
          <label class="cr-label">Inspiration Image <span class="opt">(optional)</span></label>
          <div class="cr-upload-zone" id="upload-zone">
            <input type="file" name="inspiration_image" id="inspiration_image"
                   accept="image/jpeg,image/png,image/webp" onchange="previewUpload(this)">
            <div class="cr-upload-icon">📷</div>
            <div class="cr-upload-text">
              <strong>Click to upload</strong> or drag & drop<br>
              <span>JPG, PNG or WebP · Max 5MB</span>
            </div>
          </div>
          <div id="upload-preview">
            <img id="preview-img" src="" alt="Preview">
            <span id="preview-name"></span>
            <button type="button" onclick="clearUpload()" title="Remove">✕</button>
          </div>
          <p class="cr-hint">Show us what you have in mind — a screenshot, Pinterest pin, or anything that captures the look.</p>
        </div>

        <!-- Hair details -->
        <div class="cr-grid-2" style="margin-bottom:18px">
          <div class="cr-field" style="margin-bottom:0">
            <label class="cr-label" for="cr-hair-length">Hair Length <span class="opt">(optional)</span></label>
            <select class="cr-select" id="cr-hair-length" name="hair_length">
              <option value="">— Select —</option>
              <option value="short"      <?= ($_POST['hair_length']??'')==='short'      ?'selected':'' ?>>Short (ear length or less)</option>
              <option value="medium"     <?= ($_POST['hair_length']??'')==='medium'     ?'selected':'' ?>>Medium (shoulder length)</option>
              <option value="long"       <?= ($_POST['hair_length']??'')==='long'       ?'selected':'' ?>>Long (mid-back)</option>
              <option value="extra_long" <?= ($_POST['hair_length']??'')==='extra_long' ?'selected':'' ?>>Extra Long (waist+)</option>
            </select>
          </div>
          <div class="cr-field" style="margin-bottom:0">
            <label class="cr-label" for="cr-budget">Budget Range <span class="opt">(optional)</span></label>
            <select class="cr-select" id="cr-budget" name="budget_range">
              <option value="">— Not sure —</option>
              <option value="Under £60"   <?= ($_POST['budget_range']??'')==='Under £60'   ?'selected':'' ?>>Under £60</option>
              <option value="£60–£100"    <?= ($_POST['budget_range']??'')==='£60–£100'    ?'selected':'' ?>>£60–£100</option>
              <option value="£100–£150"   <?= ($_POST['budget_range']??'')==='£100–£150'   ?'selected':'' ?>>£100–£150</option>
              <option value="£150–£200"   <?= ($_POST['budget_range']??'')==='£150–£200'   ?'selected':'' ?>>£150–£200</option>
              <option value="£200+"       <?= ($_POST['budget_range']??'')==='£200+'       ?'selected':'' ?>>£200+</option>
              <option value="Flexible"    <?= ($_POST['budget_range']??'')==='Flexible'    ?'selected':'' ?>>Flexible</option>
            </select>
          </div>
        </div>

        <div class="cr-field">
          <label class="cr-label" for="cr-date">Preferred Date <span class="opt">(optional)</span></label>
          <input class="cr-input" type="date" id="cr-date" name="preferred_date"
                 value="<?= htmlspecialchars($_POST['preferred_date'] ?? '') ?>"
                 min="<?= date('Y-m-d', strtotime('+2 days')) ?>">
          <p class="cr-hint">Only used to help us check availability — not a confirmed booking.</p>
        </div>

        <input type="hidden" name="g-recaptcha-response" id="cr-recaptcha-token">

        <button type="submit" class="cr-submit" id="cr-submit-btn">
          ✦ Send My Request →
        </button>
      </form>
      <?php endif; ?>

    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
function previewUpload(input) {
  const preview = document.getElementById('upload-preview');
  const img     = document.getElementById('preview-img');
  const name    = document.getElementById('preview-name');
  const zone    = document.getElementById('upload-zone');
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = e => {
      img.src = e.target.result;
      name.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(1) + ' MB)';
      preview.style.display = 'flex';
      zone.style.display    = 'none';
    };
    reader.readAsDataURL(file);
  }
}
function clearUpload() {
  document.getElementById('inspiration_image').value = '';
  document.getElementById('upload-preview').style.display = 'none';
  document.getElementById('upload-zone').style.display    = 'block';
}
// Drag & drop
const zone = document.getElementById('upload-zone');
zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop',      e => {
  e.preventDefault();
  zone.classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if (file) {
    const input = document.getElementById('inspiration_image');
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    previewUpload(input);
  }
});

// reCAPTCHA v3 — intercept form submit, get token, then submit
(function() {
  var form = document.getElementById('cr-form');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('cr-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Sending…';
    grecaptcha.ready(function() {
      grecaptcha.execute('<?= RECAPTCHA_SITE_KEY ?>', {action: 'custom_request'}).then(function(token) {
        document.getElementById('cr-recaptcha-token').value = token;
        form.submit();
      });
    });
  });
})();
</script>

</body>
</html>
