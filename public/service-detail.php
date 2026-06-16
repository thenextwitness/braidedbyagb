<?php
// ============================================================
// BraidedbyAGB — Service Detail Page
// FILE: /public/service-detail.php
// Route: /services/{slug}  (add to root .htaccess)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();

$slug = sanitize($_GET['slug'] ?? '');
if (!$slug) {
    header('Location: /services');
    exit;
}

// Load service
$stmt = $db->prepare("SELECT * FROM services WHERE slug = ? AND is_active = 1");
$stmt->execute([$slug]);
$svc = $stmt->fetch();

if (!$svc) {
    http_response_code(404);
    // Graceful redirect rather than a raw 404
    header('Location: /services');
    exit;
}

// Load variants
$variants = $db->prepare("SELECT * FROM service_variants WHERE service_id = ? ORDER BY display_order ASC");
$variants->execute([$svc['id']]);
$variants = $variants->fetchAll();

// Load add-ons
$addons = $db->prepare("SELECT * FROM service_addons WHERE service_id = ? AND is_active = 1 ORDER BY id ASC");
$addons->execute([$svc['id']]);
$addons = $addons->fetchAll();

// Load approved reviews for this service
$reviews = $db->prepare("
    SELECT r.*, c.name as client_name
    FROM reviews r
    JOIN customers c ON c.id = r.customer_id
    WHERE r.service_id = ? AND r.status = 'approved'
    ORDER BY r.is_featured DESC, r.approved_at DESC
    LIMIT 6
");
$reviews->execute([$svc['id']]);
$reviews = $reviews->fetchAll();

// Load 3 other active services (for "You might also like")
$others = $db->prepare("
    SELECT s.*, MIN(sv.price) as min_price
    FROM services s
    LEFT JOIN service_variants sv ON sv.service_id = s.id
    WHERE s.is_active = 1 AND s.id != ?
    GROUP BY s.id
    ORDER BY s.display_order ASC
    LIMIT 3
");
$others->execute([$svc['id']]);
$others = $others->fetchAll();

// Price display
$minPrice = !empty($variants) ? min(array_column($variants, 'price')) : $svc['price_from'];
$maxPrice = !empty($variants) ? max(array_column($variants, 'price')) : null;
$duration = formatDuration((int)$svc['duration_mins']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($svc['name']) ?> — BraidedbyAGB · African Hair Braiding Farnborough</title>
  <meta name="description" content="<?= htmlspecialchars($svc['description'] ? mb_substr($svc['description'], 0, 155) : $svc['name'] . ' by BraidedbyAGB — African hair braiding specialist in Farnborough, UK.') ?>">
  <link rel="canonical" href="https://braidedbyagb.co.uk/services/<?= htmlspecialchars($svc['slug']) ?>">
  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/services/<?= htmlspecialchars($svc['slug']) ?>">
  <meta property="og:title" content="<?= htmlspecialchars($svc['name']) ?> — BraidedbyAGB">
  <meta property="og:description" content="<?= htmlspecialchars($svc['description'] ? mb_substr($svc['description'], 0, 155) : $svc['name'] . ' by BraidedbyAGB.') ?>">
  <meta property="og:image" content="<?= $svc['image_url'] ? 'https://braidedbyagb.co.uk' . htmlspecialchars($svc['image_url']) : 'https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png' ?>">
  <meta name="twitter:card" content="summary_large_image">
  <!-- JSON-LD Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "Service",
    "name": "<?= addslashes(htmlspecialchars_decode($svc['name'])) ?>",
    "description": "<?= addslashes(htmlspecialchars_decode(mb_substr($svc['description'] ?? '', 0, 200))) ?>",
    "provider": {
      "@type": "LocalBusiness",
      "name": "BraidedbyAGB",
      "telephone": "07769064971",
      "address": {
        "@type": "PostalAddress",
        "addressLocality": "Farnborough",
        "addressRegion": "Hampshire",
        "addressCountry": "GB"
      }
    },
    "areaServed": "Farnborough, Hampshire, UK",
    "url": "https://braidedbyagb.co.uk/services/<?= htmlspecialchars($svc['slug']) ?>",
    "offers": {
      "@type": "Offer",
      "priceCurrency": "GBP",
      "price": "<?= number_format((float)$minPrice, 2) ?>",
      "availability": "https://schema.org/InStock"
    }
  }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <style>
    /* ── Breadcrumb ── */
    .svc-breadcrumb {
      background: var(--color-bg-light);
      padding: 14px 0;
      border-bottom: 1px solid var(--color-border);
    }
    .svc-breadcrumb nav {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.78rem;
      color: var(--color-text-muted);
    }
    .svc-breadcrumb a {
      color: var(--color-primary);
      text-decoration: none;
      font-weight: 600;
    }
    .svc-breadcrumb a:hover { text-decoration: underline; }
    .svc-breadcrumb .sep { opacity: 0.4; }
    .svc-breadcrumb .current { color: var(--color-text); font-weight: 600; }

    /* ── Main layout ── */
    .svc-detail-layout {
      display: grid;
      grid-template-columns: 1fr 360px;
      gap: 48px;
      align-items: start;
      padding: 56px 0 80px;
    }
    @media (max-width: 900px) {
      .svc-detail-layout { grid-template-columns: 1fr; gap: 32px; padding: 36px 0 60px; }
      .svc-detail-sidebar { order: -1; }
    }

    /* ── Image ── */
    .svc-detail-image {
      width: 100%;
      aspect-ratio: 16/9;
      object-fit: cover;
      border-radius: 16px;
      margin-bottom: 32px;
      box-shadow: 0 12px 40px rgba(75,0,130,0.12);
    }
    .svc-detail-image-placeholder {
      width: 100%;
      aspect-ratio: 16/9;
      background: var(--gradient-hero);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 4rem;
      margin-bottom: 32px;
    }

    /* ── Content ── */
    .svc-detail-badges {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }
    .svc-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 12px;
      border-radius: 20px;
      font-family: var(--font-primary);
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.06em;
    }
    .svc-badge-category {
      background: rgba(75,0,130,0.08);
      color: var(--color-deep-purple);
      border: 1px solid rgba(75,0,130,0.15);
    }
    .svc-badge-new {
      background: var(--color-gold);
      color: #5a3800;
    }
    .svc-detail-title {
      font-family: var(--font-primary);
      font-size: clamp(2rem, 4vw, 2.8rem);
      font-weight: 900;
      color: var(--color-text);
      line-height: 1.1;
      margin: 0 0 16px;
    }
    .svc-detail-desc {
      font-size: 1.05rem;
      color: var(--color-text-muted);
      line-height: 1.8;
      margin-bottom: 32px;
    }
    .svc-section-title {
      font-family: var(--font-primary);
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--color-primary);
      margin-bottom: 14px;
      padding-bottom: 8px;
      border-bottom: 2px solid rgba(204,26,138,0.12);
    }

    /* ── Variants table ── */
    .svc-variants {
      border: 1px solid var(--color-border);
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 32px;
    }
    .svc-variant-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      border-bottom: 1px solid var(--color-border);
      gap: 12px;
    }
    .svc-variant-row:last-child { border-bottom: none; }
    .svc-variant-row:nth-child(even) { background: var(--color-bg-light); }
    .svc-variant-name {
      font-weight: 700;
      font-size: 0.9rem;
      color: var(--color-text);
    }
    .svc-variant-meta {
      font-size: 0.78rem;
      color: var(--color-text-muted);
      margin-top: 2px;
    }
    .svc-variant-price {
      font-family: var(--font-primary);
      font-weight: 900;
      font-size: 1.1rem;
      color: var(--color-primary);
      white-space: nowrap;
    }

    /* ── Add-ons ── */
    .svc-addons-grid {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 32px;
    }
    .svc-addon-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 16px;
      background: var(--color-bg-light);
      border: 1px solid var(--color-border);
      border-radius: 8px;
      gap: 12px;
    }
    .svc-addon-name {
      font-size: 0.88rem;
      font-weight: 600;
      color: var(--color-text);
    }
    .svc-addon-price {
      font-family: var(--font-primary);
      font-weight: 700;
      font-size: 0.88rem;
      color: var(--color-primary);
      white-space: nowrap;
    }

    /* ── Prep notes ── */
    .svc-prep-box {
      background: #FFF8E1;
      border: 1px solid #F5C584;
      border-left: 4px solid var(--color-gold);
      border-radius: 10px;
      padding: 16px 18px;
      margin-bottom: 32px;
    }
    .svc-prep-box p {
      font-size: 0.88rem;
      color: #5a3800;
      line-height: 1.7;
      margin: 0;
    }
    .svc-prep-box strong {
      display: block;
      font-family: var(--font-primary);
      font-size: 0.7rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      margin-bottom: 6px;
    }

    /* ── Reviews ── */
    .svc-reviews-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 16px;
      margin-bottom: 32px;
    }
    .svc-review-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: 12px;
      padding: 18px;
    }
    .svc-review-stars { color: var(--color-gold); font-size: 0.9rem; margin-bottom: 8px; }
    .svc-review-text {
      font-size: 0.85rem;
      color: var(--color-text-muted);
      line-height: 1.65;
      font-style: italic;
      margin-bottom: 10px;
    }
    .svc-review-author {
      font-family: var(--font-primary);
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--color-text);
    }

    /* ── Sidebar booking card ── */
    .svc-detail-sidebar { position: sticky; top: 100px; }
    .svc-book-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 8px 32px rgba(75,0,130,0.08);
    }
    .svc-book-card-header {
      background: var(--gradient-hero);
      padding: 22px 24px;
    }
    .svc-book-card-header .price-from-label {
      font-family: var(--font-primary);
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.65);
      margin-bottom: 4px;
    }
    .svc-book-card-header .price-big {
      font-family: var(--font-primary);
      font-size: 2.4rem;
      font-weight: 900;
      color: #fff;
      line-height: 1;
    }
    .svc-book-card-header .price-big span {
      font-size: 1rem;
      font-weight: 400;
      color: rgba(255,255,255,0.65);
    }
    .svc-book-card-body { padding: 20px 24px; }
    .svc-meta-row {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 0;
      border-bottom: 1px solid var(--color-border);
      font-size: 0.85rem;
      color: var(--color-text-muted);
    }
    .svc-meta-row:last-of-type { border-bottom: none; margin-bottom: 8px; }
    .svc-meta-row strong { color: var(--color-text); margin-left: auto; text-align: right; }
    .svc-book-btn {
      display: block;
      width: 100%;
      padding: 15px 20px;
      background: linear-gradient(135deg, var(--color-primary), #9400d3);
      color: #fff;
      text-align: center;
      font-family: var(--font-primary);
      font-weight: 800;
      font-size: 0.9rem;
      letter-spacing: 0.06em;
      text-decoration: none;
      border-radius: 10px;
      margin-top: 16px;
      transition: opacity 0.2s, transform 0.15s;
    }
    .svc-book-btn:hover { opacity: 0.92; transform: translateY(-1px); }
    .svc-custom-link {
      display: block;
      text-align: center;
      margin-top: 12px;
      font-size: 0.78rem;
      color: var(--color-text-muted);
    }
    .svc-custom-link a {
      color: var(--color-primary);
      font-weight: 700;
      text-decoration: none;
    }
    .svc-custom-link a:hover { text-decoration: underline; }
    .svc-trust-row {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 18px;
      padding-top: 18px;
      border-top: 1px solid var(--color-border);
    }
    .svc-trust-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.75rem;
      color: var(--color-text-muted);
    }

    /* ── Also like ── */
    .svc-also-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }
    .svc-also-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: 12px;
      overflow: hidden;
      text-decoration: none;
      color: inherit;
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .svc-also-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 32px rgba(75,0,130,0.1);
    }
    .svc-also-img {
      width: 100%;
      aspect-ratio: 3/2;
      object-fit: cover;
    }
    .svc-also-img-placeholder {
      width: 100%;
      aspect-ratio: 3/2;
      background: var(--gradient-hero);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
    }
    .svc-also-body { padding: 14px 16px; }
    .svc-also-name {
      font-family: var(--font-primary);
      font-weight: 800;
      font-size: 0.88rem;
      color: var(--color-text);
      margin-bottom: 4px;
    }
    .svc-also-price {
      font-size: 0.8rem;
      color: var(--color-primary);
      font-weight: 700;
    }
  </style>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<!-- Breadcrumb -->
<div class="svc-breadcrumb">
  <div class="container">
    <nav aria-label="Breadcrumb">
      <a href="/">Home</a>
      <span class="sep">›</span>
      <a href="/services">Services</a>
      <span class="sep">›</span>
      <span class="current"><?= htmlspecialchars($svc['name']) ?></span>
    </nav>
  </div>
</div>

<main class="page-content" style="background:var(--color-white)">
  <div class="container">
    <div class="svc-detail-layout">

      <!-- ══ LEFT: Content ══ -->
      <div class="svc-detail-main">

        <!-- Image -->
        <?php if ($svc['image_url']): ?>
          <img src="<?= htmlspecialchars($svc['image_url']) ?>"
               alt="<?= htmlspecialchars($svc['name']) ?>"
               class="svc-detail-image">
        <?php else: ?>
          <div class="svc-detail-image-placeholder">💜</div>
        <?php endif; ?>

        <!-- Title block -->
        <div class="svc-detail-badges">
          <?php if ($svc['category']): ?>
            <span class="svc-badge svc-badge-category"><?= htmlspecialchars($svc['category']) ?></span>
          <?php endif; ?>
          <?php if ($svc['is_new']): ?>
            <span class="svc-badge svc-badge-new">✦ New</span>
          <?php endif; ?>
        </div>
        <h1 class="svc-detail-title"><?= htmlspecialchars($svc['name']) ?></h1>
        <?php if ($svc['description']): ?>
          <p class="svc-detail-desc"><?= nl2br(htmlspecialchars($svc['description'])) ?></p>
        <?php endif; ?>

        <!-- Pricing options -->
        <?php if (!empty($variants)): ?>
        <p class="svc-section-title">Pricing Options</p>
        <div class="svc-variants">
          <?php foreach ($variants as $v): ?>
          <div class="svc-variant-row">
            <div>
              <div class="svc-variant-name"><?= htmlspecialchars($v['variant_name']) ?></div>
              <?php if ($v['duration_mins']): ?>
                <div class="svc-variant-meta">⏱ <?= formatDuration((int)$v['duration_mins']) ?></div>
              <?php endif; ?>
            </div>
            <div class="svc-variant-price">£<?= number_format((float)$v['price'], 0) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Add-ons -->
        <?php if (!empty($addons)): ?>
        <p class="svc-section-title">Optional Add-ons</p>
        <div class="svc-addons-grid">
          <?php foreach ($addons as $addon): ?>
          <div class="svc-addon-row">
            <span class="svc-addon-name"><?= htmlspecialchars($addon['name']) ?></span>
            <span class="svc-addon-price">+£<?= number_format((float)$addon['price'], 2) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Prep notes -->
        <?php if ($svc['prep_notes']): ?>
        <div class="svc-prep-box">
          <p><strong>📋 Before Your Appointment</strong><?= nl2br(htmlspecialchars($svc['prep_notes'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Aftercare -->
        <?php if ($svc['aftercare']): ?>
        <p class="svc-section-title">Aftercare</p>
        <p style="font-size:0.92rem;color:var(--color-text-muted);line-height:1.8;margin-bottom:32px">
          <?= nl2br(htmlspecialchars($svc['aftercare'])) ?>
        </p>
        <?php endif; ?>

        <!-- Reviews -->
        <?php if (!empty($reviews)): ?>
        <p class="svc-section-title">Client Reviews</p>
        <div class="svc-reviews-grid">
          <?php foreach ($reviews as $r): ?>
          <div class="svc-review-card">
            <div class="svc-review-stars">
              <?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?>
            </div>
            <p class="svc-review-text">"<?= htmlspecialchars($r['review_text']) ?>"</p>
            <div class="svc-review-author"><?= htmlspecialchars($r['client_name']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>

      <!-- ══ RIGHT: Booking sidebar ══ -->
      <aside class="svc-detail-sidebar">
        <div class="svc-book-card">
          <div class="svc-book-card-header">
            <div class="price-from-label">Starting from</div>
            <div class="price-big">
              £<?= number_format((float)$minPrice, 0) ?>
              <?php if ($maxPrice && $maxPrice != $minPrice): ?>
                <span>– £<?= number_format((float)$maxPrice, 0) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="svc-book-card-body">
            <div class="svc-meta-row">
              <span>⏱ Duration</span>
              <strong><?= $duration ?></strong>
            </div>
            <?php if ($svc['category']): ?>
            <div class="svc-meta-row">
              <span>✂️ Category</span>
              <strong><?= htmlspecialchars($svc['category']) ?></strong>
            </div>
            <?php endif; ?>
            <div class="svc-meta-row">
              <span>💳 Deposit</span>
              <strong>30% to secure booking</strong>
            </div>
            <div class="svc-meta-row">
              <span>📍 Location</span>
              <strong>Farnborough, Hampshire</strong>
            </div>

            <a href="/booking/<?= htmlspecialchars($svc['slug']) ?>" class="svc-book-btn">
              Book <?= htmlspecialchars($svc['name']) ?> →
            </a>

            <p class="svc-custom-link">
              Not quite right? <a href="/custom-request">Request a custom style</a>
            </p>

            <div class="svc-trust-row">
              <div class="svc-trust-item">🔒 <span>Secure payment via Stripe</span></div>
              <div class="svc-trust-item">✓ <span>Instant email confirmation</span></div>
              <div class="svc-trust-item">⏰ <span>24hr & 2hr reminders sent</span></div>
              <div class="svc-trust-item">💬 <span>WhatsApp support available</span></div>
            </div>
          </div>
        </div>
      </aside>

    </div>
  </div>

  <!-- You might also like -->
  <?php if (!empty($others)): ?>
  <section style="background:var(--color-bg-light);padding:56px 0">
    <div class="container">
      <p class="svc-section-title" style="text-align:center;margin-bottom:6px">You Might Also Like</p>
      <h2 style="font-family:var(--font-primary);font-size:1.5rem;font-weight:900;color:var(--color-text);text-align:center;margin-bottom:32px">
        More Services
      </h2>
      <div class="svc-also-grid">
        <?php foreach ($others as $o): ?>
        <a href="/services/<?= htmlspecialchars($o['slug']) ?>" class="svc-also-card">
          <?php if ($o['image_url']): ?>
            <img src="<?= htmlspecialchars($o['image_url']) ?>" alt="<?= htmlspecialchars($o['name']) ?>" class="svc-also-img">
          <?php else: ?>
            <div class="svc-also-img-placeholder">💜</div>
          <?php endif; ?>
          <div class="svc-also-body">
            <div class="svc-also-name"><?= htmlspecialchars($o['name']) ?></div>
            <div class="svc-also-price">From £<?= number_format((float)($o['min_price'] ?? $o['price_from']), 0) ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- CTA strip -->
  <section class="cta-strip">
    <div class="container">
      <div class="cta-strip-inner">
        <h3 class="cta-strip-title">Ready to book your <?= htmlspecialchars($svc['name']) ?>?</h3>
        <a href="/booking/<?= htmlspecialchars($svc['slug']) ?>" class="btn btn-gold btn-lg">
          Book Now — 07769 064 971
        </a>
      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
</body>
</html>
