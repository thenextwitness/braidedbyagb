<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
$db = getDB();
try { $services = $db->query("SELECT s.*, MIN(sv.price) as min_price FROM services s LEFT JOIN service_variants sv ON sv.service_id = s.id WHERE s.is_active = 1 GROUP BY s.id ORDER BY s.display_order ASC LIMIT 9")->fetchAll(); } catch(Exception $e) { $services = []; }
try { $reviews = $db->query("SELECT r.*, c.name as client_name, s.name as service_name FROM reviews r JOIN customers c ON c.id = r.customer_id LEFT JOIN services s ON s.id = r.service_id WHERE r.status = 'approved' ORDER BY r.is_featured DESC, r.approved_at DESC LIMIT 6")->fetchAll(); } catch(Exception $e) { $reviews = []; }
try { $rating = getAverageRating(); } catch(Exception $e) { $rating = ['average'=>0,'total'=>0]; }
$placeholderReviews = [
    ['name'=>'Adaeze O.','service'=>'Knotless Braids','rating'=>5,'text'=>'Absolutely incredible work! My knotless braids are so neat and natural-looking. I\'ve had so many compliments. Will definitely be coming back!'],
    ['name'=>'Fatima B.','service'=>'Box Braids','rating'=>5,'text'=>'Professional, warm and so talented. The atmosphere was lovely and my braids lasted over 2 months. 100% recommend BraidedbyAGB.'],
    ['name'=>'Sarah K.','service'=>'Cornrows','rating'=>5,'text'=>'Found BraidedbyAGB on Instagram and I\'m so glad I did. Punctual, professional and the results were flawless. Booking again next month!'],
    ['name'=>'Blessing A.','service'=>'Starter Locs','rating'=>5,'text'=>'Started my loc journey here and I couldn\'t be happier. So knowledgeable and patient. The aftercare advice alone was worth it!'],
];
$displayReviews = !empty($reviews) ? $reviews : $placeholderReviews;
$reviewsAreReal = !empty($reviews);
function getServiceStyle(string $slug): array {
    $map = ['knotless'=>['grad'=>'linear-gradient(145deg,#4B0082,#9400D3)','tag'=>'Most Popular'],'box'=>['grad'=>'linear-gradient(145deg,#3a0060,#7a0080)','tag'=>'Classic'],'feed'=>['grad'=>'linear-gradient(145deg,#2d0050,#6600aa)','tag'=>'Seamless'],'cornrow'=>['grad'=>'linear-gradient(145deg,#5c003c,#8B0060)','tag'=>'Neat'],'twist'=>['grad'=>'linear-gradient(145deg,#380060,#800080)','tag'=>'Elegant'],'loc'=>['grad'=>'linear-gradient(145deg,#1a0040,#4B0082)','tag'=>'Protective'],'starter'=>['grad'=>'linear-gradient(145deg,#1a0040,#4B0082)','tag'=>'Journey'],'faux'=>['grad'=>'linear-gradient(145deg,#2a0044,#660099)','tag'=>'Protective'],'kid'=>['grad'=>'linear-gradient(145deg,#660044,#aa0066)','tag'=>'Gentle'],'underwig'=>['grad'=>'linear-gradient(145deg,#3a0030,#700055)','tag'=>'Foundation']];
    foreach ($map as $k => $s) { if (stripos($slug,$k)!==false) return $s; }
    static $i=0; $f=[['grad'=>'linear-gradient(145deg,#4B0082,#800080)','tag'=>'Style'],['grad'=>'linear-gradient(145deg,#2d0050,#800080)','tag'=>'Style'],['grad'=>'linear-gradient(145deg,#500050,#9400D3)','tag'=>'Style']]; return $f[($i++)%3];
}
$formSent = false; $formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $formError = 'Security error. Please refresh and try again.';
    } else {
        $name    = sanitize($_POST['contact_name']    ?? '');
        $email   = sanitizeEmail($_POST['contact_email'] ?? '');
        $phone   = sanitize($_POST['contact_phone']   ?? '');
        $message = sanitize($_POST['contact_message'] ?? '');
        if (!$name || !$email || !$message) {
            $formError = 'Please fill in your name, email and message.';
        } elseif (!validateEmail($email)) {
            $formError = 'Please enter a valid email address.';
        } else {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $mail = createMailer();
                $mail->addAddress(SITE_EMAIL);
                $mail->Subject = 'Website Enquiry from ' . $name;
                $mail->Body = emailWrapper("
                    <h2>New Homepage Enquiry</h2>
                    <div class='detail-box'>
                      <table>
                        <tr><td>Name:</td><td>{$name}</td></tr>
                        <tr><td>Email:</td><td>{$email}</td></tr>
                        <tr><td>Phone:</td><td>" . ($phone ?: 'Not provided') . "</td></tr>
                        <tr><td>Message:</td><td>{$message}</td></tr>
                      </table>
                    </div>
                ");
                $mail->send();
                $formSent = true;
            } catch (Exception $e) {
                $formError = 'Message could not be sent. Please WhatsApp or call us directly on 07769 064 971.';
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
  <title>BraidedbyAGB — African Hair Braiding Specialist · Farnborough, UK</title>
  <meta name="description" content="Professional African hair braiding specialist in Farnborough, Hampshire. Knotless braids, box braids, cornrows, starter locs and more. Book online today.">
 <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="shortcut icon" href="/favicon.ico" />
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
<link rel="manifest" href="/site.webmanifest" />
  <link rel="canonical" href="https://braidedbyagb.co.uk">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk">
  <meta property="og:title" content="BraidedbyAGB — African Hair Braiding Specialist · Farnborough, UK">
  <meta property="og:description" content="Professional African hair braiding specialist in Farnborough, Hampshire. Knotless braids, box braids, cornrows, starter locs and more. Book online today.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <meta name="twitter:card" content="summary_large_image">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "BraidedbyAGB",
    "description": "Professional African hair braiding specialist in Farnborough, Hampshire. Knotless braids, box braids, cornrows, starter locs and more.",
    "url": "https://braidedbyagb.co.uk",
    "telephone": "+447769064971",
    "email": "hello@braidedbyagb.co.uk",
    "image": "https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png",
    "priceRange": "££",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Farnborough",
      "addressRegion": "Hampshire",
      "postalCode": "GU14",
      "addressCountry": "GB"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": "51.2965",
      "longitude": "-0.7486"
    },
    "openingHoursSpecification": [
      { "@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday","Tuesday","Wednesday","Thursday","Friday"], "opens": "09:00", "closes": "18:00" },
      { "@type": "OpeningHoursSpecification", "dayOfWeek": "Saturday", "opens": "09:00", "closes": "17:00" }
    ],
    "sameAs": [
      "https://instagram.com/BraidedbyAGB",
      "https://wa.me/447769064971"
    ]
  }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300;1,400&family=Cormorant+Garamond:ital,wght@0,600;1,400;1,600;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/home.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>

<!-- NAV -->
<header class="sp-nav" id="site-header">
  <div class="sp-nav-inner">
    <a href="/" class="sp-logo"><img src="/assets/images/braidedbyagblogo.png" alt="BraidedbyAGB"></a>
    <nav class="sp-nav-links" id="spNavLinks">
      <a href="#about">About</a>
      <a href="#services">Services</a>
      <a href="#testimonials">Testimonials</a>
      <a href="#policy">Policy</a>
      <a href="#contact">Contact</a>
    </nav>
    <a href="/booking" class="btn btn-gold sp-book-btn">Book Now</a>
    <button class="sp-hamburger" id="spHamburger" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
  </div>
  <div class="sp-mobile-menu" id="spMobileMenu">
    <a href="#about">About</a>
    <a href="#services">Services</a>
    <a href="#testimonials">Testimonials</a>
    <a href="#policy">Policy</a>
    <a href="#contact">Contact</a>
    <a href="/booking" class="btn btn-gold" style="margin-top:8px;width:100%;justify-content:center;">Book Now</a>
  </div>
</header>

<main>

<!-- HERO -->
<section class="sp-hero">
  <div class="sp-hero-bg">
    <img src="/assets/images/homeimg.png" alt="" class="sp-hero-img" onerror="this.style.display='none'">
    <div class="sp-hero-overlay"></div>
  </div>
  <div class="sp-hero-content">
    <p class="sp-hero-eyebrow">Premium Hair Studio</p>
    <h1 class="sp-hero-title">Precision.<br>Artistry.<br><em>Confidence.</em></h1>
    <p class="sp-hero-body">Welcome to BraidedbyAGB — your destination for authentic African braiding and protective styles. Expert technique, a warm private studio, and styles crafted to celebrate you.</p>
    <div class="sp-hero-btns">
      <a href="/booking" class="btn btn-gold btn-lg">Book Appointment</a>
      <a href="#policy" class="btn btn-outline btn-lg">View Policy</a>
    </div>
  </div>
  <div class="sp-hero-aside">
    <img src="/assets/images/homeimg.png" alt="BraidedbyAGB styles" onerror="this.parentElement.classList.add('no-img')">
  </div>
</section>

<!-- ABOUT -->
<section class="sp-section sp-about" id="about">
  <div class="sp-container">
    <p class="sp-label dark">Our Founder</p>
    <h2 class="sp-title dark">Meet the creative mind<br>behind BraidedbyAGB</h2>
    <div class="sp-founder-grid">
      <div class="sp-founder-img">
        <img src="/assets/images/founder.png" alt="Founder" onerror="this.parentElement.classList.add('no-img')">
        
      </div>
      <div class="sp-founder-body">
        <h3>Glory</h3>
        <p class="sp-founder-role">Founder &amp; Lead Stylist · Farnborough, Hampshire</p>
        <p>I founded Braidedbyagb from a deep love of African hair culture and a passion for making every client feel seen, celebrated and beautiful. I am based in Farnborough and every appointment is a one-to-one experience. No rush, no compromises, just flawless braids crafted with care.</p>
        <p>BraidedbyAGB is a luxury African hair braiding studio offering refined protective styles and bespoke braiding. With a focus on clean partings, healthy hair, and elegant finishes, every client receives a personalised experience crafted with precision and care.</p>
        <p style="margin-top:14px">Each appointment is a relaxed, one-to-one experience designed to leave you feeling confident, polished, and celebrated — with every service delivered in excellence.</p>
        <div class="sp-usps">
          <div class="sp-usp">
            <span>✓</span>
            <div><strong>Authentic Craftsmanship</strong><em>Neat, detailed braids with a luxury finish rooted in African tradition</em></div>
          </div>
          <div class="sp-usp">
            <span>✓</span>
            <div><strong>Client-Centred Experience</strong><em>Thoughtfully tailored styles in a warm, private studio just for you</em></div>
          </div>
          <div class="sp-usp">
            <span>✓</span>
            <div><strong>Long-Lasting Results</strong><em>Premium techniques and quality extensions that go the distance</em></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SERVICES -->
<section class="sp-section sp-services" id="services">
  <div class="sp-container">
    <p class="sp-label dark">What We Offer</p>
    <h2 class="sp-title dark">Our Services</h2>
    <p class="sp-lead dark">Every style crafted with precision and care using premium techniques.</p>
    <?php if (!empty($services)): ?>
    <div class="sp-svc-grid">
      <?php foreach ($services as $svc):
        $slug=$svc['slug']??''; $style=getServiceStyle($slug);
        $price=$svc['min_price']??$svc['price_from']; $dur=formatDuration((int)$svc['duration_mins']);
      ?>
      <article class="sp-svc-card">
        <?php if (!empty($svc['is_new'])): ?><span class="sp-badge-new">New</span><?php endif; ?>
        <div class="sp-svc-vis" style="background:<?= $style['grad'] ?>">
          <?php if (!empty($svc['image_url'])): ?>
            <img src="<?= htmlspecialchars($svc['image_url']) ?>" alt="<?= htmlspecialchars($svc['name']) ?>">
          <?php else: ?>
            <span class="sp-svc-tag"><?= $style['tag'] ?></span>
          <?php endif; ?>
          <div class="sp-svc-price">From £<?= number_format((float)$price,0) ?></div>
        </div>
        <div class="sp-svc-body">
          <h3><?= htmlspecialchars($svc['name']) ?></h3>
          <?php if (!empty($svc['description'])): ?><p><?= htmlspecialchars(mb_strimwidth($svc['description'],0,90,'...')) ?></p><?php endif; ?>
          <div class="sp-svc-foot"><span>⏱ <?= $dur ?></span><a href="/booking/<?= htmlspecialchars($slug) ?>" class="btn btn-primary btn-sm">Book Now</a></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <!-- Custom -->
    <div class="sp-custom">
      <div class="sp-custom-icon">✦</div>
      <div class="sp-custom-text">
        <h3>Have Something Unique in Mind?</h3>
        <p>Zigzag cornrows, geometric partings, braids with beads, goddess styles — if you can imagine it, we can create it.</p>
        <ul>
          <li>Geometric &amp; zigzag partings</li>
          <li>Braids with beads or accessories</li>
          <li>Custom cornrow patterns &amp; designs</li>
          <li>Mixed technique styles</li>
        </ul>
      </div>
      <div class="sp-custom-cta">
        <p>Get in touch to discuss your vision</p>
        <a href="https://wa.me/447769064971?text=Hi!%20I%27d%20like%20to%20enquire%20about%20a%20custom%20braid%20style%20%E2%80%94%20" target="_blank" rel="noopener" class="btn btn-gold">
          <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" style="margin-right:6px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
          Chat on WhatsApp
        </a>
        <a href="#contact" class="btn btn-outline">Send an Enquiry</a>
      </div>
    </div>
    <div style="text-align:center;margin-top:40px"><a href="/services" class="btn btn-outline btn-lg">View All Services &amp; Prices</a></div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="sp-section sp-testimonials" id="testimonials">
  <div class="sp-container">
    <p class="sp-label dark">Testimonials</p>
    <h2 class="sp-title dark">What Our Clients Say</h2>
    <p class="sp-lead dark">Don't just take our word for it. Here's what our satisfied clients have to say about their experience with us.</p>
    <div class="sp-reviews-grid">
      <?php foreach ($displayReviews as $r):
        $rName=$reviewsAreReal?$r['client_name']:$r['name'];
        $rText=$reviewsAreReal?$r['review_text']:$r['text'];
        $rSvc=$reviewsAreReal?($r['service_name']??''):$r['service'];
        $rStars=$reviewsAreReal?(int)$r['rating']:$r['rating'];
      ?>
      <div class="sp-review">
        <div class="sp-stars"><?php for($i=1;$i<=5;$i++): ?><span class="<?= $i<=$rStars?'on':'' ?>">★</span><?php endfor; ?></div>
        <blockquote>"<?= htmlspecialchars($rText) ?>"</blockquote>
        <div class="sp-review-meta"><strong><?= htmlspecialchars($rName) ?></strong><?php if($rSvc): ?><span><?= htmlspecialchars($rSvc) ?></span><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:40px"><a href="/review" class="btn btn-gold">Leave a Review ★</a></div>
  </div>
</section>

<!-- CONTACT -->
<section class="sp-section sp-contact" id="contact">
  <div class="sp-container">
    <p class="sp-label dark">Contact Us</p>
    <h2 class="sp-title dark">Have a question? We'd love<br>to hear from you.</h2>
    <div class="sp-contact-grid">
      <div class="sp-contact-info">
        <h3>Get In Touch</h3>
        <p>We're here to help you achieve your perfect hairstyle. Reach out and let's create something beautiful together.</p>
        <div class="sp-contact-items">
          <div class="sp-ci"><span>📍</span><div><strong>Location</strong><em>Farnborough, Hampshire, UK</em><small>Serving Hampshire &amp; Surrey</small></div></div>
          <div class="sp-ci"><span>📞</span><div><strong>Phone / WhatsApp</strong><a href="tel:07769064971">07769 064 971</a></div></div>
          <div class="sp-ci"><span>✉️</span><div><strong>Email</strong><a href="mailto:hello@braidedbyagb.co.uk">hello@braidedbyagb.co.uk</a><small>We'll respond within 24 hours</small></div></div>
        </div>
        <div class="sp-social">
          <p>Follow Our Journey</p>
          <div class="sp-social-row">
            <a href="https://instagram.com/BraidedbyAGB" target="_blank" rel="noopener" aria-label="Instagram">
              <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
              Instagram
            </a>
            <a href="https://wa.me/447769064971" target="_blank" rel="noopener" aria-label="WhatsApp">
              <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
              WhatsApp
            </a>
          </div>
        </div>
      </div>
      <div class="sp-contact-form-wrap">
        <?php if ($formSent): ?>
          <div class="sp-form-success"><span>✓</span><h4>Message Sent!</h4><p>Thank you, we'll be in touch within 24 hours.</p></div>
        <?php else: ?>
          <?php if ($formError): ?><div class="sp-form-error"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
          <form method="POST" class="sp-form">
            <input type="hidden" name="contact_submit" value="1">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="sp-field"><label>Full Name</label><input type="text" name="contact_name" placeholder="Your full name" required></div>
            <div class="sp-field"><label>Email</label><input type="email" name="contact_email" placeholder="your@email.com" required></div>
            <div class="sp-field"><label>Phone <span>(optional)</span></label><input type="tel" name="contact_phone" placeholder="07700 000000"></div>
            <div class="sp-field"><label>Message</label><textarea name="contact_message" rows="5" placeholder="How can we help you?" required></textarea></div>
            <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center">Send Message</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- POLICY -->
<section class="sp-section sp-policy" id="policy">
  <div class="sp-container">
    <p class="sp-label">Booking Policy</p>
    <h2 class="sp-title">Our Booking Policy</h2>
    <p class="sp-lead">At BraidedbyAGB, your time and ours are valuable. Please read and respect our policies for a smooth experience.</p>
    <div class="sp-policy-grid">
      <div class="sp-policy-card"><div class="sp-policy-icon">📅</div><h4>Booking &amp; Deposit</h4><p>A non-refundable deposit is required to secure your appointment. This confirms your slot and is deducted from your final balance on the day.</p></div>
      <div class="sp-policy-card"><div class="sp-policy-icon">⏰</div><h4>Cancellations</h4><p>Please give at least 48 hours notice to cancel or reschedule. Cancellations with less than 48 hours notice will forfeit the deposit.</p></div>
      <div class="sp-policy-card"><div class="sp-policy-icon">🕐</div><h4>Late Arrivals</h4><p>If you arrive more than 20 minutes late without prior notice, we reserve the right to cancel the appointment and retain the deposit.</p></div>
      <div class="sp-policy-card"><div class="sp-policy-icon">💇</div><h4>Hair Preparation</h4><p>Please arrive with clean, detangled, and fully dry hair. Hair requiring washing or detangling may incur an additional charge.</p></div>
      <div class="sp-policy-card"><div class="sp-policy-icon">✨</div><h4>Extensions</h4><p>Extensions are available from our shop or you may bring your own. Please confirm requirements when booking so we can prepare everything in advance.</p></div>
      <div class="sp-policy-card"><div class="sp-policy-icon">💳</div><h4>Payment</h4><p>We accept card, bank transfer, and cash. The remaining balance is due on the day. Prices shown are starting prices — final cost confirmed at booking.</p></div>
    </div>
    <p class="sp-policy-note">Thank you for respecting my time and business. By booking with BraidedbyAGB, you agree to these terms. Let's keep things professional and smooth for both of us.</p>
    <div style="text-align:center;margin-top:32px"><a href="/policies" class="btn btn-outline">Read Full Policy</a></div>
  </div>
</section>

</main>

<!-- FOOTER -->
<footer class="sp-footer">
  <div class="sp-container">
    <div class="sp-footer-inner">
      <p>Copyright © <?= date('Y') ?> BraidedbyAGB. All rights reserved.</p>
      <div class="sp-footer-links">
        <a href="/policies">Privacy Policy</a>
        <a href="/policies#cancellation">Cancellation Policy</a>
        <a href="/policies#refund">Refund Policy</a>
      </div>
    </div>
  </div>
</footer>

<a href="https://wa.me/447769064971?text=Hi%2C%20I%27d%20like%20to%20book%20an%20appointment%20at%20BraidedbyAGB" class="whatsapp-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
  <svg viewBox="0 0 24 24" fill="white" width="28" height="28"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.134.558 4.135 1.532 5.875L0 24l6.318-1.508A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.002-1.368l-.36-.213-3.731.89.933-3.618-.234-.372A9.818 9.818 0 1112 21.818z"/></svg>
</a>
<div class="toast" id="toast" role="alert" aria-live="polite"></div>

<link rel="stylesheet" href="/assets/css/chat-widget.css">
<script src="/assets/js/main.js"></script>
<script src="/assets/js/cart.js"></script>
<script src="/assets/js/chat-widget.js" defer></script>
<script>
const ham=document.getElementById('spHamburger'),menu=document.getElementById('spMobileMenu');
if(ham&&menu){ham.addEventListener('click',()=>{ham.classList.toggle('open');menu.classList.toggle('open');});menu.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{ham.classList.remove('open');menu.classList.remove('open');}));}
document.querySelectorAll('a[href^="#"]').forEach(a=>{a.addEventListener('click',e=>{const t=document.querySelector(a.getAttribute('href'));if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'});}});});
</script>
</body>
</html>