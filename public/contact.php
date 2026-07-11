<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security error. Please refresh and try again.';
    } elseif (!verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $error = 'Please complete the reCAPTCHA verification.';
    } else {
        $name    = sanitize($_POST['name'] ?? '');
        $email   = sanitizeEmail($_POST['email'] ?? '');
        $subject = sanitize($_POST['subject'] ?? '');
        $message = sanitize($_POST['message'] ?? '');

        if (!$name || !$email || !$message) {
            $error = 'Please fill in all required fields.';
        } elseif (!validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Send via PHPMailer if configured
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $mail = createMailer();
                $mail->addAddress(SITE_EMAIL);
                $mail->Subject = 'Contact Form: ' . ($subject ?: 'General Enquiry') . ' — ' . $name;
                $mail->Body    = emailWrapper("
                    <h2>New Contact Form Submission</h2>
                    <div class='detail-box'>
                      <table>
                        <tr><td>Name:</td><td>{$name}</td></tr>
                        <tr><td>Email:</td><td>{$email}</td></tr>
                        <tr><td>Subject:</td><td>{$subject}</td></tr>
                        <tr><td>Message:</td><td>{$message}</td></tr>
                      </table>
                    </div>
                ");
                $mail->send();
                $sent = true;
            } catch (Exception $e) {
                $error = 'Message could not be sent. Please WhatsApp or call us directly.';
            }
        }
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
  <title>Contact — BraidedbyAGB · Farnborough</title>
  <meta name="description" content="Contact BraidedbyAGB in Farnborough, UK. Book an appointment, ask a question or get in touch via WhatsApp, phone or email.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/contact">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/contact">
  <meta property="og:title" content="Contact — BraidedbyAGB">
  <meta property="og:description" content="Contact BraidedbyAGB in Farnborough, UK. Book an appointment, ask a question or get in touch via WhatsApp, phone or email.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">

  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Get in Touch</span></p>
        <h1 class="page-hero-title">Contact Us</h1>
        <p class="page-hero-subtitle">We'd love to hear from you. Reach out via WhatsApp, call, or send us a message.</p>
      </div>
    </div>
  </section>

  <section style="background:var(--color-white)">
    <div class="container">
      <div class="contact-grid">

        <!-- Contact info -->
        <div data-animate="fadeLeft">
          <h2 class="section-title" style="margin-bottom:var(--space-8)">We're Here for You</h2>

          <div class="contact-info-item">
            <div class="contact-info-icon">📞</div>
            <div>
              <p class="contact-info-title">Phone / WhatsApp</p>
              <p class="contact-info-value">
                <a href="tel:07769064971">07769 064 971</a><br>
                <a href="https://wa.me/447769064971">Chat on WhatsApp</a>
              </p>
            </div>
          </div>

          <div class="contact-info-item">
            <div class="contact-info-icon">✉️</div>
            <div>
              <p class="contact-info-title">Email</p>
              <p class="contact-info-value">
                <a href="mailto:hello@braidedbyagb.co.uk">hello@braidedbyagb.co.uk</a>
              </p>
            </div>
          </div>

          <div class="contact-info-item">
            <div class="contact-info-icon">📍</div>
            <div>
              <p class="contact-info-title">Location</p>
              <p class="contact-info-value">Farnborough, Hampshire, UK<br><em>Private home studio — address provided upon booking confirmation.</em></p>
            </div>
          </div>

          <div class="contact-info-item">
            <div class="contact-info-icon">🕐</div>
            <div>
              <p class="contact-info-title">Availability</p>
              <p class="contact-info-value">By appointment only.<br>Book online to see available slots.</p>
            </div>
          </div>

          <!-- Social links -->
          <div style="margin-top:var(--space-8)">
            <p class="section-label"><span>Follow Us</span></p>
            <div style="display:flex;gap:var(--space-3);margin-top:var(--space-4)">
              <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">Instagram</a>
              <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm" id="contact-tiktok">TikTok</a>
              <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm" id="contact-facebook">Facebook</a>
            </div>
          </div>

          <!-- WhatsApp CTA -->
          <div style="margin-top:var(--space-8);padding:var(--space-6);background:var(--color-bg-light);border-radius:var(--border-radius-lg);border:1px solid var(--color-border)">
            <p style="font-family:var(--font-primary);font-weight:700;color:var(--color-deep-purple);margin-bottom:var(--space-3)">💬 Fastest Response via WhatsApp</p>
            <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:var(--space-4);line-height:1.6">For quick questions, booking enquiries or if you're running late to an appointment — WhatsApp is the fastest way to reach us.</p>
            <a href="https://wa.me/447769064971?text=Hi%2C%20I%27d%20like%20to%20enquire%20about%20BraidedbyAGB"
               class="btn btn-primary w-full"
               target="_blank" rel="noopener"
               style="justify-content:center">
              Open WhatsApp Chat
            </a>
          </div>
        </div>

        <!-- Contact form -->
        <div data-animate="fadeRight">
          <?php if ($sent): ?>
            <div style="padding:var(--space-10);background:var(--color-bg-light);border-radius:var(--border-radius-lg);text-align:center;border:1px solid var(--color-border)">
              <p style="font-size:3rem;margin-bottom:var(--space-4)">💜</p>
              <h3 style="color:var(--color-deep-purple);margin-bottom:var(--space-3)">Message Sent!</h3>
              <p style="color:var(--color-text-muted)">Thank you for getting in touch. We'll get back to you as soon as possible.</p>
              <a href="/contact" class="btn btn-primary" style="margin-top:var(--space-6)">Send Another Message</a>
            </div>
          <?php else: ?>
            <div style="background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--border-radius-lg);padding:var(--space-8)">
              <h3 style="color:var(--color-deep-purple);margin-bottom:var(--space-6)">Send Us a Message</h3>

              <?php if ($error): ?>
                <div style="background:#FDE8E8;border:1px solid #F5A8A8;padding:var(--space-4);border-radius:var(--border-radius);color:var(--color-error);margin-bottom:var(--space-6);font-size:var(--text-sm)">
                  <?= htmlspecialchars($error) ?>
                </div>
              <?php endif; ?>

              <form method="POST" action="/contact" id="contact-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                  <label class="form-label" for="name">Your Name *</label>
                  <input class="form-control" type="text" id="name" name="name"
                         value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                         placeholder="Your full name" required>
                  <span class="field-error">Please enter your name.</span>
                </div>

                <div class="form-group">
                  <label class="form-label" for="email">Email Address *</label>
                  <input class="form-control" type="email" id="email" name="email"
                         value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                         placeholder="your@email.com" required>
                  <span class="field-error">Please enter a valid email address.</span>
                </div>

                <div class="form-group">
                  <label class="form-label" for="subject">Subject</label>
                  <select class="form-control" id="subject" name="subject">
                    <option value="">Select a subject...</option>
                    <option value="Booking Enquiry">Booking Enquiry</option>
                    <option value="Product Enquiry">Product Enquiry</option>
                    <option value="Pricing Question">Pricing Question</option>
                    <option value="General Question">General Question</option>
                    <option value="Other">Other</option>
                  </select>
                </div>

                <div class="form-group">
                  <label class="form-label" for="message">Message *</label>
                  <textarea class="form-control" id="message" name="message"
                            rows="5" placeholder="Tell us how we can help..." required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                  <span class="field-error">Please enter your message.</span>
                </div>

                <div class="g-recaptcha" data-sitekey="<?= RECAPTCHA_SITE_KEY ?>" style="margin-bottom:var(--space-6)"></div>

                <button type="submit" class="btn btn-primary w-full btn-lg" id="contact-submit-btn">
                  Send Message
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </section>

  <section class="cta-strip">
    <div class="container">
      <div class="cta-strip-inner">
        <h3 class="cta-strip-title">Ready to book your appointment?</h3>
        <a href="/booking" class="btn btn-gold btn-lg">Book Now — 07769 064 971</a>
      </div>
    </div>
  </section>

</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script>
(function() {
  var form = document.getElementById('contact-form');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    if (!validateForm(form)) { e.preventDefault(); return; }
    if (typeof grecaptcha === 'undefined' || grecaptcha.getResponse().length === 0) {
      e.preventDefault();
      alert('Please tick the “I’m not a robot” box to continue.');
      return;
    }
    var btn = document.getElementById('contact-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Sending…';
  });
})();
</script>
</body>
</html>
