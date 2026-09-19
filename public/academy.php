<?php
// ============================================================
// BraidedbyAGB — Training Academy (public course listing)
// FILE: /public/academy.php  →  /academy
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db = getDB();
try {
    $courses = $db->query("SELECT * FROM courses WHERE is_active=1 ORDER BY display_order ASC, id ASC")->fetchAll();
} catch (Throwable $e) { $courses = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Training Academy — BraidedbyAGB · Learn Braiding in Farnborough</title>
  <meta name="description" content="Learn professional African hair braiding with BraidedbyAGB. Beginner to advanced courses, small cohorts, hands-on training in Farnborough, UK.">
  <link rel="canonical" href="https://braidedbyagb.co.uk/academy">
  <meta property="og:title" content="Training Academy — BraidedbyAGB">
  <meta property="og:description" content="Learn professional African hair braiding — beginner to advanced, small hands-on cohorts.">
  <meta property="og:image" content="https://braidedbyagb.co.uk/assets/images/braidedbyagblogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
  <style>
    .ac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px}
    .ac-card{border:1px solid var(--color-border,#e8d8ee);border-radius:14px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
    .ac-card img{width:100%;height:170px;object-fit:cover;display:block}
    .ac-body{padding:16px;display:flex;flex-direction:column;gap:8px;flex:1}
    .ac-level{font-size:0.7rem;text-transform:uppercase;letter-spacing:0.1em;color:#7A0050;font-weight:700}
    .ac-price{font-weight:800;color:#7A0050;font-size:1.1rem}
    .ac-card .btn{margin-top:auto}
  </style>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content">
  <section class="page-hero">
    <div class="page-hero-bg"></div>
    <div class="container">
      <div class="page-hero-content" data-animate="fadeUp">
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span>Learn With Us</span></p>
        <h1 class="page-hero-title">Training Academy</h1>
        <p class="page-hero-subtitle">Hands-on braiding courses — from your first cornrow to advanced, salon-ready technique.</p>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <?php if (empty($courses)): ?>
        <p style="text-align:center;color:var(--color-text-muted,#6B5575)">Courses are coming soon — check back shortly, or <a class="auth-link" href="/contact">get in touch</a> to register your interest.</p>
      <?php else: ?>
      <div class="ac-grid">
        <?php foreach ($courses as $c): ?>
          <div class="ac-card">
            <?php if (!empty($c['image_url'])): ?><img src="<?= htmlspecialchars($c['image_url']) ?>" alt="<?= htmlspecialchars($c['title']) ?>"><?php endif; ?>
            <div class="ac-body">
              <span class="ac-level"><?= htmlspecialchars(ucfirst($c['level'])) ?></span>
              <h3 style="margin:0;color:#2A0020"><?= htmlspecialchars($c['title']) ?></h3>
              <?php if (!empty($c['summary'])): ?><p style="margin:0;color:#6B5575;font-size:0.9rem"><?= htmlspecialchars($c['summary']) ?></p><?php endif; ?>
              <span class="ac-price"><?= (float)$c['price'] > 0 ? '£'.number_format((float)$c['price'],0) : 'Free' ?></span>
              <a href="/academy/<?= htmlspecialchars($c['slug']) ?>" class="btn btn-primary">View course</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
