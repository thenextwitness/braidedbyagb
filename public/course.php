<?php
// ============================================================
// BraidedbyAGB — Course detail (public)
// FILE: /public/course.php  →  /academy/{slug}
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/courses.php';

$db   = getDB();
$slug = sanitize($_GET['slug'] ?? '');
$c = $db->prepare("SELECT * FROM courses WHERE slug=? AND is_active=1");
$c->execute([$slug]);
$course = $c->fetch();
if (!$course) { header('Location: /academy'); exit; }
$courseId = (int)$course['id'];

// Open cohorts (active) with seats remaining.
$cohorts = $db->prepare("SELECT * FROM course_cohorts WHERE course_id=? AND is_active=1 ORDER BY start_date IS NULL, start_date ASC");
$cohorts->execute([$courseId]);
$cohorts = $cohorts->fetchAll();

// Curriculum outline (modules + preview lessons).
$modules = $db->prepare("SELECT * FROM course_modules WHERE course_id=? ORDER BY display_order, id");
$modules->execute([$courseId]);
$modules = $modules->fetchAll();
$lessonsByModule = [];
if ($modules) {
    $ls = $db->query("SELECT * FROM course_lessons WHERE module_id IN (" . implode(',', array_map(fn($m)=>(int)$m['id'], $modules)) . ") ORDER BY display_order, id");
    foreach ($ls->fetchAll() as $l) $lessonsByModule[$l['module_id']][] = $l;
}
$price = (float)$course['price'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($course['title']) ?> — BraidedbyAGB Academy</title>
  <meta name="description" content="<?= htmlspecialchars($course['summary'] ?: ('Learn ' . $course['title'] . ' with BraidedbyAGB.')) ?>">
  <link rel="canonical" href="https://braidedbyagb.co.uk/academy/<?= htmlspecialchars($course['slug']) ?>">
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
        <p class="section-label" style="justify-content:center;color:var(--color-gold)"><span><?= htmlspecialchars(ucfirst($course['level'])) ?> Course</span></p>
        <h1 class="page-hero-title"><?= htmlspecialchars($course['title']) ?></h1>
        <?php if (!empty($course['summary'])): ?><p class="page-hero-subtitle"><?= htmlspecialchars($course['summary']) ?></p><?php endif; ?>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container" style="max-width:820px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px">
        <div style="font-weight:800;color:#7A0050;font-size:1.6rem"><?= $price > 0 ? '£'.number_format($price,0) : 'Free' ?></div>
        <a href="/academy/<?= htmlspecialchars($course['slug']) ?>/enrol" class="btn btn-gold btn-lg">Enrol now</a>
      </div>

      <?php if (!empty($course['description'])): ?>
        <div style="margin-bottom:28px;line-height:1.7;color:#3a2740"><?= nl2br(htmlspecialchars($course['description'])) ?></div>
      <?php endif; ?>

      <?php if (!empty($course['syllabus'])): ?>
        <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.2rem;margin:0 0 10px">What you'll learn</h2>
        <div style="margin-bottom:28px;line-height:1.7;color:#3a2740"><?= nl2br(htmlspecialchars($course['syllabus'])) ?></div>
      <?php endif; ?>

      <?php if ($modules): ?>
        <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.2rem;margin:0 0 10px">Curriculum</h2>
        <div style="margin-bottom:28px">
          <?php foreach ($modules as $m): ?>
            <div style="border:1px solid #e8d8ee;border-radius:10px;padding:12px 16px;margin-bottom:8px">
              <strong style="color:#2A0020"><?= htmlspecialchars($m['title']) ?></strong>
              <?php $lessons = $lessonsByModule[$m['id']] ?? []; if ($lessons): ?>
                <ul style="margin:8px 0 0;padding-left:18px;color:#6B5575;font-size:0.9rem">
                  <?php foreach ($lessons as $l): ?>
                    <li><?= htmlspecialchars($l['title']) ?><?= (int)$l['is_preview'] ? ' <span style="color:#7A0050">· preview</span>' : '' ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.2rem;margin:0 0 10px">Upcoming intakes</h2>
      <?php if (empty($cohorts)): ?>
        <p style="color:#6B5575">Dates announced soon — enrol now to reserve your place and we'll be in touch.</p>
      <?php else: foreach ($cohorts as $co): $left = cohortSeatsLeft($db, (int)$co['id']); ?>
        <div style="display:flex;justify-content:space-between;align-items:center;border:1px solid #e8d8ee;border-radius:10px;padding:12px 16px;margin-bottom:8px">
          <div>
            <strong style="color:#2A0020"><?= htmlspecialchars($co['name']) ?></strong>
            <div style="color:#6B5575;font-size:0.85rem">
              <?= $co['start_date'] ? date('j M Y', strtotime($co['start_date'])) : 'Date TBC' ?>
              <?= $co['location'] ? ' · ' . htmlspecialchars($co['location']) : '' ?>
            </div>
          </div>
          <span style="font-size:0.85rem;color:<?= $left>0 ? '#166534' : '#991b1b' ?>;font-weight:700"><?= $left>0 ? $left.' seat'.($left===1?'':'s').' left' : 'Full' ?></span>
        </div>
      <?php endforeach; endif; ?>

      <div style="text-align:center;margin-top:32px">
        <a href="/academy/<?= htmlspecialchars($course['slug']) ?>/enrol" class="btn btn-gold btn-lg">Enrol now</a>
      </div>
    </div>
  </section>
</main>
</body>
</html>
