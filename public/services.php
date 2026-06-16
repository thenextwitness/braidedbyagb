<?php
// ============================================================
// BraidedbyAGB — Services Overview Page
// FILE: /public/services.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();
$services = $db->query("
    SELECT s.*,
           MIN(sv.price) as min_price,
           MAX(sv.price) as max_price,
           GROUP_CONCAT(sv.variant_name ORDER BY sv.display_order SEPARATOR ', ') as variant_names
    FROM services s
    LEFT JOIN service_variants sv ON sv.service_id = s.id
    WHERE s.is_active = 1
    GROUP BY s.id
    ORDER BY s.display_order ASC
")->fetchAll();

$categories = array_unique(array_column($services, 'category'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Services & Prices — BraidedbyAGB · African Hair Braiding Farnborough</title>
  <meta name="description" content="All BraidedbyAGB services and prices. Box braids, knotless braids, cornrows, starter locs, twists and more. Farnborough, UK.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/services">
  <!-- Open Graph -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/services">
  <meta property="og:title" content="Services & Prices — BraidedbyAGB · African Hair Braiding Farnborough">
  <meta property="og:description" content="All BraidedbyAGB services and prices. Box braids, knotless braids, cornrows, starter locs, twists and more. Farnborough, UK.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <meta name="twitter:card" content="summary_large_image">
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

  <!-- Page Hero -->
  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)">
          <span>Farnborough, UK</span>
        </p>
        <h1 class="page-hero-title">Services & Prices</h1>
        <p class="page-hero-subtitle">Every style crafted with precision, care and authentic expertise.</p>
      </div>
    </div>
  </section>

  <!-- Category filters -->
  <section class="section-sm" style="background:var(--color-bg-light)">
    <div class="container">
      <div class="filter-row">
        <button class="filter-btn active" data-filter="all">All Services</button>
        <?php foreach ($categories as $cat): if (!$cat) continue; ?>
          <button class="filter-btn" data-filter="<?= htmlspecialchars(strtolower($cat)) ?>">
            <?= htmlspecialchars($cat) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Services List -->
  <section class="section" style="background:var(--color-white)">
    <div class="container">
      <div class="services-list" data-animate="stagger">
        <?php foreach ($services as $i => $svc):
          $minPrice  = $svc['min_price'] ?? $svc['price_from'];
          $maxPrice  = $svc['max_price'] ?? null;
          $slug      = $svc['slug'];
          $category  = strtolower($svc['category'] ?? '');
          $duration  = formatDuration((int)$svc['duration_mins']);
          $isNew     = (bool)$svc['is_new'];
        ?>
        <article class="service-list-item" data-category="<?= htmlspecialchars($category) ?>" data-delay="<?= $i * 60 ?>">
          <div class="service-list-img">
            <?php if ($svc['image_url']): ?>
              <img src="<?= htmlspecialchars($svc['image_url']) ?>"
                   alt="<?= htmlspecialchars($svc['name']) ?>"
                   loading="lazy">
            <?php else: ?>
              <div class="service-list-img-placeholder">💜</div>
            <?php endif; ?>
            <?php if ($isNew): ?>
              <span class="badge-new">New</span>
            <?php endif; ?>
          </div>
          <div class="service-list-body">
            <div class="service-list-info">
              <h2 class="service-list-name"><?= htmlspecialchars($svc['name']) ?></h2>
              <?php if ($svc['description']): ?>
                <p class="service-list-desc"><?= htmlspecialchars($svc['description']) ?></p>
              <?php endif; ?>
              <div class="service-list-meta">
                <span class="meta-item">⏱ <?= $duration ?></span>
                <?php if ($svc['variant_names']): ?>
                  <span class="meta-item">Options: <?= htmlspecialchars($svc['variant_names']) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($svc['prep_notes']): ?>
                <div class="service-prep-note">
                  <strong>📋 Prep:</strong> <?= htmlspecialchars($svc['prep_notes']) ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="service-list-action">
              <div class="service-list-price">
                <span class="price-from">From</span>
                <span class="price-amount">£<?= number_format((float)$minPrice, 0) ?></span>
                <?php if ($maxPrice && $maxPrice != $minPrice): ?>
                  <span class="price-to">– £<?= number_format((float)$maxPrice, 0) ?></span>
                <?php endif; ?>
              </div>
              <a href="/booking/<?= htmlspecialchars($slug) ?>" class="btn btn-primary">Book Now</a>
              <a href="/services/<?= htmlspecialchars($slug) ?>" class="btn btn-outline-primary">Full Details</a>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Policies reminder -->
  <section class="section-sm" style="background:var(--color-bg-light)">
    <div class="container">
      <div class="policy-reminder-grid">
        <div class="policy-reminder-item">
          <span class="policy-icon">💳</span>
          <div>
            <h5>30% Deposit Required</h5>
            <p>A non-refundable deposit is required to secure all appointments.</p>
          </div>
        </div>
        <div class="policy-reminder-item">
          <span class="policy-icon">⏰</span>
          <div>
            <h5>20-Minute Late Arrival</h5>
            <p>Please arrive within 20 minutes of your appointment time.</p>
          </div>
        </div>
        <div class="policy-reminder-item">
          <span class="policy-icon">📅</span>
          <div>
            <h5>48-Hour Cancellation</h5>
            <p>Cancellations must be made at least 48 hours in advance.</p>
          </div>
        </div>
        <div class="policy-reminder-item">
          <span class="policy-icon">💬</span>
          <div>
            <h5>Running Late?</h5>
            <p>WhatsApp us immediately on <a href="https://wa.me/447769064971">07769 064 971</a>.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="cta-strip">
    <div class="container">
      <div class="cta-strip-inner">
        <h3 class="cta-strip-title">Ready to Book Your Appointment?</h3>
        <a href="/booking" class="btn btn-gold btn-lg">Book Now — 07769 064 971</a>
      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script>
// Category filter
document.querySelectorAll('.filter-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const filter = this.dataset.filter;
    document.querySelectorAll('.service-list-item').forEach(item => {
      const show = filter === 'all' || item.dataset.category === filter;
      item.style.display = show ? '' : 'none';
    });
  });
});
</script>
</body>
</html>
