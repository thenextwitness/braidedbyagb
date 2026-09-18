<?php
// ============================================================
// BraidedbyAGB — Public Service Gallery
// FILE: /public/gallery.php  →  /gallery
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();
try {
    $images = $db->query("
        SELECT g.id, g.image_url, g.caption, g.service_id, s.name AS service_name
        FROM gallery_images g
        LEFT JOIN services s ON s.id = g.service_id AND s.is_active = 1
        WHERE g.is_active = 1
        ORDER BY g.display_order ASC, g.id DESC
    ")->fetchAll();
} catch (Throwable $e) {
    $images = [];
}

// Services that actually have live images, for the filter row.
$filterServices = [];
foreach ($images as $img) {
    if (!empty($img['service_id']) && !empty($img['service_name'])) {
        $filterServices[(int)$img['service_id']] = $img['service_name'];
    }
}
asort($filterServices);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gallery — BraidedbyAGB · African Hair Braiding Farnborough</title>
  <meta name="description" content="Browse real work by BraidedbyAGB — box braids, knotless braids, cornrows, locs, twists and more. Farnborough, UK.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/gallery">
  <meta property="og:type" content="website">
  <meta property="og:url" content="https://braidedbyagb.co.uk/gallery">
  <meta property="og:title" content="Gallery — BraidedbyAGB">
  <meta property="og:description" content="Browse real work by BraidedbyAGB — braids, cornrows, locs, twists and more.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
  <style>
    .gal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
    .gal-item{position:relative;border-radius:14px;overflow:hidden;background:var(--color-bg-light,#f4eef7);aspect-ratio:3/4}
    .gal-item img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .4s ease}
    .gal-item:hover img{transform:scale(1.05)}
    .gal-cap{position:absolute;left:0;right:0;bottom:0;padding:26px 14px 12px;color:#fff;font-size:0.85rem;
             background:linear-gradient(to top,rgba(0,0,0,.72),rgba(0,0,0,0))}
    .gal-item[hidden]{display:none}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="page-content">

  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Our Work</span></p>
        <h1 class="page-hero-title">Gallery</h1>
        <p class="page-hero-subtitle">Real styles, crafted with precision and care.</p>
      </div>
    </div>
  </section>

  <?php if (empty($images)): ?>
    <section class="section">
      <div class="container" style="text-align:center">
        <p style="color:var(--color-text-muted,#6B5575);font-size:1rem;margin-bottom:20px">Our gallery is coming soon — check back shortly.</p>
        <a href="/booking" class="btn btn-gold btn-lg">Book an Appointment</a>
      </div>
    </section>
  <?php else: ?>

    <?php if (!empty($filterServices)): ?>
    <section class="section-sm" style="background:var(--color-bg-light)">
      <div class="container">
        <div class="filter-row">
          <button class="filter-btn active" data-filter="all">All</button>
          <?php foreach ($filterServices as $fid => $fname): ?>
            <button class="filter-btn" data-filter="s<?= (int)$fid ?>"><?= htmlspecialchars($fname) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="section">
      <div class="container">
        <div class="gal-grid">
          <?php foreach ($images as $img):
            $tag = !empty($img['service_id']) ? 's' . (int)$img['service_id'] : 'general'; ?>
            <figure class="gal-item" data-service="<?= $tag ?>">
              <img src="<?= htmlspecialchars($img['image_url']) ?>" alt="<?= htmlspecialchars($img['caption'] ?: 'BraidedbyAGB style') ?>" loading="lazy">
              <?php if (!empty($img['caption'])): ?>
                <figcaption class="gal-cap"><?= htmlspecialchars($img['caption']) ?></figcaption>
              <?php endif; ?>
            </figure>
          <?php endforeach; ?>
        </div>

        <div style="text-align:center;margin-top:40px">
          <a href="/booking" class="btn btn-gold btn-lg">Book Your Appointment</a>
        </div>
      </div>
    </section>
  <?php endif; ?>

</main>

<script>
document.querySelectorAll('.filter-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
    this.classList.add('active');
    var f = this.dataset.filter;
    document.querySelectorAll('.gal-item').forEach(function(item){
      item.hidden = !(f === 'all' || item.dataset.service === f);
    });
  });
});
</script>
</body>
</html>
