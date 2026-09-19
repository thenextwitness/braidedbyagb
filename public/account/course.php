<?php
// ============================================================
// BraidedbyAGB — Client learning area: one course
// FILE: /public/account/course.php  →  /account/learning/{enrolmentId}
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';
require_once __DIR__ . '/../../includes/quiz.php';

requireClient();
$db  = getDB();
$cid = currentClientId();
$eid = (int)($_GET['e'] ?? 0);

// Load the enrolment and confirm it belongs to this signed-in client.
$stmt = $db->prepare("
    SELECT e.*, c.title AS course_title, c.id AS course_id, co.name AS cohort_name
    FROM course_enrolments e
    JOIN courses c ON c.id = e.course_id
    LEFT JOIN course_cohorts co ON co.id = e.cohort_id
    WHERE e.id = ? AND e.customer_id = ?
");
$stmt->execute([$eid, $cid]);
$enrol = $stmt->fetch();
if (!$enrol || !in_array($enrol['status'], ['active','completed'], true)) {
    header('Location: /account/learning'); exit;
}
$courseId = (int)$enrol['course_id'];

// Toggle a lesson's completion (POST before any output).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_lesson') {
    $lid = (int)($_POST['lesson_id'] ?? 0);
    // Verify the lesson belongs to THIS course before touching progress.
    $ok = $db->prepare("SELECT COUNT(*) FROM course_lessons l JOIN course_modules m ON m.id=l.module_id WHERE l.id=? AND m.course_id=?");
    $ok->execute([$lid, $courseId]);
    if ($lid && (int)$ok->fetchColumn() > 0) {
        $has = $db->prepare("SELECT id FROM course_lesson_progress WHERE enrolment_id=? AND lesson_id=?");
        $has->execute([$eid, $lid]);
        if ($has->fetch()) {
            $db->prepare("DELETE FROM course_lesson_progress WHERE enrolment_id=? AND lesson_id=?")->execute([$eid, $lid]);
        } else {
            $db->prepare("INSERT IGNORE INTO course_lesson_progress (enrolment_id,lesson_id) VALUES (?,?)")->execute([$eid, $lid]);
        }
    }
    header('Location: /account/learning/' . $eid); exit;
}

// Curriculum.
$modules = $db->prepare("SELECT * FROM course_modules WHERE course_id=? ORDER BY display_order, id");
$modules->execute([$courseId]);
$modules = $modules->fetchAll();
$lessonsByModule = []; $materialsByLesson = []; $lessonIds = [];
if ($modules) {
    $mids = implode(',', array_map(fn($m)=>(int)$m['id'], $modules));
    foreach ($db->query("SELECT * FROM course_lessons WHERE module_id IN ($mids) ORDER BY display_order, id")->fetchAll() as $l) {
        $lessonsByModule[$l['module_id']][] = $l; $lessonIds[] = (int)$l['id'];
    }
    if ($lessonIds) {
        $lids = implode(',', $lessonIds);
        foreach ($db->query("SELECT * FROM course_lesson_materials WHERE lesson_id IN ($lids) ORDER BY display_order, id")->fetchAll() as $mat) {
            $materialsByLesson[$mat['lesson_id']][] = $mat;
        }
    }
}
// Progress set.
$done = [];
$pr = $db->prepare("SELECT lesson_id FROM course_lesson_progress WHERE enrolment_id=?");
$pr->execute([$eid]);
foreach ($pr->fetchAll(PDO::FETCH_COLUMN) as $lid) $done[(int)$lid] = true;
$totalLessons = count($lessonIds);
$doneCount = count(array_filter($lessonIds, fn($l) => isset($done[$l])));
$pct = $totalLessons > 0 ? (int)round($doneCount / $totalLessons * 100) : 0;

// Attendance + certificate.
$attendance = $db->prepare("SELECT * FROM course_attendance WHERE enrolment_id=? ORDER BY session_date DESC");
$attendance->execute([$eid]); $attendance = $attendance->fetchAll();
$cert = $db->prepare("SELECT * FROM course_certificates WHERE enrolment_id=? ORDER BY id DESC LIMIT 1");
$cert->execute([$eid]); $cert = $cert->fetch();

$pageTitle = $enrol['course_title'];
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head">
    <h1><?= htmlspecialchars($enrol['course_title']) ?></h1>
    <p><?= $enrol['cohort_name'] ? htmlspecialchars($enrol['cohort_name']) . ' · ' : '' ?><?= ucfirst($enrol['status']) ?></p>
  </div>
  <?php $activeTab = '/account/learning'; require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <p style="margin:-6px 0 18px"><a class="auth-link" href="/account/learning">‹ All my courses</a></p>

  <?php if ($cert): ?>
    <div class="account-card" style="background:#F9EEF9;border:1px solid #E8D8EE;margin-bottom:20px">
      🎓 <strong>Certificate issued</strong> — <?= htmlspecialchars($cert['name_on_certificate']) ?> · ref <?= htmlspecialchars($cert['certificate_ref']) ?>
    </div>
  <?php endif; ?>

  <?php if ($totalLessons > 0): ?>
  <div class="account-card" style="margin-bottom:20px">
    <div style="display:flex;justify-content:space-between;font-size:0.85rem;color:#6B5575;margin-bottom:6px">
      <span>Progress</span><span><?= $doneCount ?>/<?= $totalLessons ?> lessons</span>
    </div>
    <div style="height:10px;background:#EEE3F2;border-radius:6px;overflow:hidden">
      <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#7A0050,#C21A8A)"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$modules): ?>
    <div class="account-card account-empty">The curriculum for this course will appear here soon.</div>
  <?php else: foreach ($modules as $m): ?>
    <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.05rem;margin:20px 0 10px"><?= htmlspecialchars($m['title']) ?></h2>
    <?php foreach (($lessonsByModule[$m['id']] ?? []) as $l): $isDone = isset($done[(int)$l['id']]); ?>
      <div class="account-card" style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
          <div style="flex:1">
            <strong style="color:#2A0020"><?= htmlspecialchars($l['title']) ?></strong>
            <?php if (!empty($l['content'])): ?>
              <div style="margin-top:6px;color:#3a2740;line-height:1.6;font-size:0.92rem"><?= nl2br(htmlspecialchars($l['content'])) ?></div>
            <?php endif; ?>
            <?php foreach (($materialsByLesson[(int)$l['id']] ?? []) as $mat): ?>
              <div style="margin-top:6px"><a class="auth-link" href="<?= htmlspecialchars($mat['url']) ?>" target="_blank" rel="noopener">
                <?= $mat['material_type']==='video' ? '▶' : '📄' ?> <?= htmlspecialchars($mat['title']) ?>
              </a></div>
            <?php endforeach; ?>
          </div>
          <form method="POST" action="/account/learning/<?= $eid ?>" style="margin:0">
            <input type="hidden" name="action" value="toggle_lesson">
            <input type="hidden" name="lesson_id" value="<?= (int)$l['id'] ?>">
            <button type="submit" class="<?= $isDone ? 'btn btn-primary' : 'btn btn-outline' ?>" style="white-space:nowrap;padding:6px 12px;font-size:0.8rem">
              <?= $isDone ? '✓ Done' : 'Mark done' ?>
            </button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; endif; ?>

  <?php $quizzes = quizzesForCourse($db, $courseId); if ($quizzes): ?>
    <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.05rem;margin:24px 0 10px">Theory tests</h2>
    <div class="account-list">
      <?php foreach ($quizzes as $qz): $best = quizBestAttempt($db, (int)$qz['id'], $eid); ?>
        <a class="account-row" style="text-decoration:none" href="/account/learning/<?= $eid ?>/quiz/<?= (int)$qz['id'] ?>">
          <div>
            <div class="title"><?= htmlspecialchars($qz['title']) ?></div>
            <div class="meta">
              <?= (int)$qz['question_count'] ?> question<?= (int)$qz['question_count'] === 1 ? '' : 's' ?> · pass <?= (int)$qz['pass_mark'] ?>%
              <?= $qz['time_limit_mins'] ? ' · ' . (int)$qz['time_limit_mins'] . ' min' : '' ?>
            </div>
          </div>
          <?php if ($best): ?>
            <span class="<?= (int)$best['passed'] ? 'account-pill pill-completed' : 'account-pill pill-pending' ?>"><?= (int)$best['score'] ?>%<?= (int)$best['passed'] ? ' ✓' : '' ?></span>
          <?php else: ?>
            <span class="account-pill pill-pending">Start</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($attendance): ?>
    <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;font-size:1.05rem;margin:24px 0 10px">Attendance</h2>
    <div class="account-list">
      <?php foreach ($attendance as $a): ?>
        <div class="account-row">
          <div class="title"><?= date('D j M Y', strtotime($a['session_date'])) ?></div>
          <span class="<?= (int)$a['present'] ? 'account-pill pill-completed' : 'account-pill pill-cancelled' ?>"><?= (int)$a['present'] ? 'Present' : 'Absent' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
