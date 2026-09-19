<?php
// ============================================================
// BraidedbyAGB — Client learning area: my courses
// FILE: /public/account/learning.php  →  /account/learning
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

try {
    $enrolments = $db->prepare("
        SELECT e.id, e.status, e.payment_status, e.enrolled_at,
               c.title AS course_title, c.slug,
               co.name AS cohort_name, co.start_date,
               (SELECT COUNT(*) FROM course_certificates cc WHERE cc.enrolment_id = e.id) AS cert_count
        FROM course_enrolments e
        JOIN courses c ON c.id = e.course_id
        LEFT JOIN course_cohorts co ON co.id = e.cohort_id
        WHERE e.customer_id = ?
        ORDER BY FIELD(e.status,'active','pending','waitlisted','completed','withdrawn'), e.enrolled_at DESC
    ");
    $enrolments->execute([$cid]);
    $enrolments = $enrolments->fetchAll();
} catch (Throwable $e) { $enrolments = []; }

function enrolPill(string $status): string {
    $known = ['completed','active','pending','waitlisted','withdrawn'];
    $map = ['active'=>'confirmed','completed'=>'completed','pending'=>'pending','waitlisted'=>'pending','withdrawn'=>'cancelled'];
    return 'account-pill pill-' . ($map[$status] ?? 'pending');
}

$pageTitle = 'My Learning';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head">
    <h1>My Learning</h1>
    <p>Your courses, curriculum and progress.</p>
  </div>

  <?php $activeTab = '/account/learning'; require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <?php if (!$enrolments): ?>
    <div class="account-card account-empty">
      You're not enrolled on any courses yet.
      <a class="auth-link" href="/academy">Browse the Academy »</a>
    </div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($enrolments as $e):
        $canOpen = in_array($e['status'], ['active','completed'], true); ?>
        <?php if ($canOpen): ?>
        <a class="account-row" style="text-decoration:none" href="/account/learning/<?= (int)$e['id'] ?>">
        <?php else: ?>
        <div class="account-row">
        <?php endif; ?>
          <div>
            <div class="title"><?= htmlspecialchars($e['course_title']) ?></div>
            <div class="meta">
              <?= $e['cohort_name'] ? htmlspecialchars($e['cohort_name']) : 'No cohort yet' ?>
              <?= $e['start_date'] ? ' · starts ' . date('j M Y', strtotime($e['start_date'])) : '' ?>
              <?php if ((int)$e['cert_count'] > 0): ?> · 🎓 certificate issued<?php endif; ?>
              <?php if (!$canOpen && $e['payment_status'] !== 'paid' && $e['payment_status'] !== 'waived'): ?> · payment pending<?php endif; ?>
            </div>
          </div>
          <span class="<?= enrolPill($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></span>
        <?php if ($canOpen): ?></a><?php else: ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
