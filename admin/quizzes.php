<?php
// ============================================================
// BraidedbyAGB — Admin Quiz Builder (Phase D5)
// FILE: /admin/quizzes.php?course=ID
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Build theory tests for a course: quizzes → questions → options, with exactly
// one correct option per question. Admin is trusted, so this page may show/set
// the correct answer; the LEARNER side (includes/quiz.php) never exposes it.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$courseId = (int)($_GET['course'] ?? ($_POST['course_id'] ?? 0));
$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'create_quiz') {
            $db->prepare("INSERT INTO course_quizzes (course_id,title,pass_mark,time_limit_mins,is_active) VALUES (?,?,?,?,1)")
               ->execute([$courseId, sanitize($_POST['title'] ?? 'Theory test'),
                          max(0,min(100,(int)($_POST['pass_mark'] ?? 70))),
                          ($_POST['time_limit_mins'] ?? '') === '' ? null : max(1,(int)$_POST['time_limit_mins'])]);
            $msg = 'Quiz created.';
        } elseif ($action === 'update_quiz') {
            $db->prepare("UPDATE course_quizzes SET title=?, pass_mark=?, time_limit_mins=?, is_active=? WHERE id=? AND course_id=?")
               ->execute([sanitize($_POST['title'] ?? ''), max(0,min(100,(int)($_POST['pass_mark'] ?? 70))),
                          ($_POST['time_limit_mins'] ?? '') === '' ? null : max(1,(int)$_POST['time_limit_mins']),
                          isset($_POST['is_active'])?1:0, (int)$_POST['id'], $courseId]);
            $msg = 'Quiz updated.';
        } elseif ($action === 'delete_quiz') {
            $db->prepare("DELETE FROM course_quizzes WHERE id=? AND course_id=?")->execute([(int)$_POST['id'], $courseId]);
            $msg = 'Quiz deleted.';
        } elseif ($action === 'add_question') {
            $qz = (int)$_POST['quiz_id'];
            $max = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM course_questions WHERE quiz_id=$qz")->fetchColumn();
            $db->prepare("INSERT INTO course_questions (quiz_id,question_text,display_order) VALUES (?,?,?)")
               ->execute([$qz, sanitize($_POST['question_text'] ?? ''), $max+1]);
            $msg = 'Question added.';
        } elseif ($action === 'delete_question') {
            $db->prepare("DELETE FROM course_questions WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Question deleted.';
        } elseif ($action === 'add_option') {
            $qid = (int)$_POST['question_id'];
            $correct = isset($_POST['is_correct']) ? 1 : 0;
            $max = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM course_question_options WHERE question_id=$qid")->fetchColumn();
            if ($correct) $db->prepare("UPDATE course_question_options SET is_correct=0 WHERE question_id=?")->execute([$qid]);
            $db->prepare("INSERT INTO course_question_options (question_id,option_text,is_correct,display_order) VALUES (?,?,?,?)")
               ->execute([$qid, sanitize($_POST['option_text'] ?? ''), $correct, $max+1]);
            $msg = 'Option added.';
        } elseif ($action === 'set_correct') {
            $oid = (int)$_POST['id']; $qid = (int)$_POST['question_id'];
            $db->prepare("UPDATE course_question_options SET is_correct=0 WHERE question_id=?")->execute([$qid]);
            $db->prepare("UPDATE course_question_options SET is_correct=1 WHERE id=? AND question_id=?")->execute([$oid, $qid]);
            $msg = 'Correct answer set.';
        } elseif ($action === 'delete_option') {
            $db->prepare("DELETE FROM course_question_options WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Option deleted.';
        }
    } catch (Exception $e) {
        error_log('Quiz admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
    header("Location: /admin/quizzes?course=$courseId&msg=" . urlencode($msg ?: $error)); exit;
}

$pageTitle = 'Quiz Builder';
require_once __DIR__ . '/includes/layout.php';
$flash = sanitize($_GET['msg'] ?? '');

$course = null;
if ($courseId) { $c = $db->prepare("SELECT id,title FROM courses WHERE id=?"); $c->execute([$courseId]); $course = $c->fetch(); }
if (!$course) { echo '<div class="alert-error">Course not found. <a href="/admin/academy">Back to Academy</a></div>'; require_once __DIR__ . '/includes/layout-end.php'; return; }

$quizzes = $db->prepare("SELECT * FROM course_quizzes WHERE course_id=? ORDER BY id"); $quizzes->execute([$courseId]); $quizzes = $quizzes->fetchAll();
$questionsByQuiz = []; $optionsByQuestion = [];
if ($quizzes) {
    $qzIds = implode(',', array_map(fn($q)=>(int)$q['id'], $quizzes));
    foreach ($db->query("SELECT * FROM course_questions WHERE quiz_id IN ($qzIds) ORDER BY display_order,id")->fetchAll() as $q) {
        $questionsByQuiz[$q['quiz_id']][] = $q;
    }
    $allQ = [];
    foreach ($questionsByQuiz as $arr) foreach ($arr as $q) $allQ[] = (int)$q['id'];
    if ($allQ) {
        $qin = implode(',', $allQ);
        foreach ($db->query("SELECT * FROM course_question_options WHERE question_id IN ($qin) ORDER BY display_order,id")->fetchAll() as $o) {
            $optionsByQuestion[$o['question_id']][] = $o;
        }
    }
}
?>
<?php if ($flash): ?><div class="alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div><h2 class="section-heading">Quizzes — <?= htmlspecialchars($course['title']) ?></h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><a href="/admin/academy">‹ Back to Academy</a></p></div>
</div>

<!-- Create quiz -->
<div class="admin-card" style="margin-bottom:20px"><div class="admin-card-body">
  <form method="POST" action="/admin/quizzes" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="action" value="create_quiz"><input type="hidden" name="course_id" value="<?= $courseId ?>">
    <div class="admin-form-group" style="margin:0;flex:1;min-width:180px"><label class="admin-label">New quiz title</label><input class="admin-input" name="title" value="Theory test" required></div>
    <div class="admin-form-group" style="margin:0"><label class="admin-label">Pass mark %</label><input class="admin-input" type="number" name="pass_mark" value="70" min="0" max="100" style="width:90px"></div>
    <div class="admin-form-group" style="margin:0"><label class="admin-label">Time limit (min, blank=none)</label><input class="admin-input" type="number" name="time_limit_mins" min="1" style="width:120px"></div>
    <button class="btn-admin btn-admin-primary" type="submit">+ Add quiz</button>
  </form>
</div></div>

<?php if (!$quizzes): ?>
  <div class="table-empty"><div class="table-empty-icon">❓</div><p>No quizzes yet. Create one above.</p></div>
<?php else: foreach ($quizzes as $qz): $qzid=(int)$qz['id']; ?>
  <div class="admin-card" style="margin-bottom:14px">
    <div class="admin-card-header"><span class="admin-card-title"><?= htmlspecialchars($qz['title']) ?> <?php if(!$qz['is_active']):?><span class="admin-badge" style="background:#6b7280">Hidden</span><?php endif;?></span>
      <span style="font-size:0.78rem;color:var(--admin-muted)">pass <?= (int)$qz['pass_mark'] ?>%<?= $qz['time_limit_mins']?(' · '.(int)$qz['time_limit_mins'].' min'):'' ?></span></div>
    <div class="admin-card-body">
      <form method="POST" action="/admin/quizzes" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
        <input type="hidden" name="action" value="update_quiz"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="id" value="<?= $qzid ?>">
        <div class="admin-form-group" style="margin:0;flex:1;min-width:160px"><label class="admin-label" style="font-size:0.7rem">Title</label><input class="admin-input" name="title" value="<?= htmlspecialchars($qz['title']) ?>"></div>
        <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.7rem">Pass %</label><input class="admin-input" type="number" name="pass_mark" value="<?= (int)$qz['pass_mark'] ?>" min="0" max="100" style="width:80px"></div>
        <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.7rem">Time (min)</label><input class="admin-input" type="number" name="time_limit_mins" value="<?= $qz['time_limit_mins']!==null?(int)$qz['time_limit_mins']:'' ?>" min="1" style="width:90px"></div>
        <label style="display:flex;gap:4px;align-items:center;font-size:0.78rem"><input type="checkbox" name="is_active" <?= $qz['is_active']?'checked':'' ?>> Live</label>
        <button class="btn-admin btn-admin-sm btn-admin-primary" type="submit">Save</button>
        <button type="submit" form="delqz-<?= $qzid ?>" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Delete quiz</button>
      </form>
      <form id="delqz-<?= $qzid ?>" method="POST" action="/admin/quizzes" style="display:none" onsubmit="return confirm('Delete this quiz and all its questions?')"><input type="hidden" name="action" value="delete_quiz"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="id" value="<?= $qzid ?>"></form>

      <!-- Questions -->
      <?php foreach (($questionsByQuiz[$qzid] ?? []) as $qi => $q): $qid=(int)$q['id']; $opts=$optionsByQuestion[$qid] ?? []; $hasCorrect = array_filter($opts, fn($o)=>(int)$o['is_correct']===1); ?>
        <div style="border:1px solid var(--admin-border);border-radius:8px;padding:10px;margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">
            <strong style="font-size:0.9rem"><?= ($qi+1) ?>. <?= htmlspecialchars($q['question_text']) ?></strong>
            <form method="POST" action="/admin/quizzes" onsubmit="return confirm('Delete question?')" style="margin:0"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="id" value="<?= $qid ?>"><button class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" type="submit">✕</button></form>
          </div>
          <?php if (!$hasCorrect && $opts): ?><p style="font-size:0.72rem;color:#b45309;margin:0 0 6px">⚠ No correct answer marked — set one below.</p><?php endif; ?>
          <?php foreach ($opts as $o): $correct=(int)$o['is_correct']===1; ?>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
              <span style="flex:1;font-size:0.85rem;<?= $correct?'font-weight:700;color:#065f46':'' ?>"><?= $correct?'✓ ':'' ?><?= htmlspecialchars($o['option_text']) ?></span>
              <?php if (!$correct): ?>
                <form method="POST" action="/admin/quizzes" style="margin:0"><input type="hidden" name="action" value="set_correct"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="question_id" value="<?= $qid ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">Mark correct</button></form>
              <?php endif; ?>
              <form method="POST" action="/admin/quizzes" style="margin:0"><input type="hidden" name="action" value="delete_option"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" type="submit">✕</button></form>
            </div>
          <?php endforeach; ?>
          <form method="POST" action="/admin/quizzes" style="display:flex;gap:6px;align-items:center;margin-top:6px">
            <input type="hidden" name="action" value="add_option"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="question_id" value="<?= $qid ?>">
            <input class="admin-input" name="option_text" placeholder="New answer option" style="flex:1" required>
            <label style="display:flex;gap:3px;align-items:center;font-size:0.72rem;white-space:nowrap"><input type="checkbox" name="is_correct"> correct</label>
            <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">+ Option</button>
          </form>
        </div>
      <?php endforeach; ?>
      <form method="POST" action="/admin/quizzes" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="action" value="add_question"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="quiz_id" value="<?= $qzid ?>">
        <input class="admin-input" name="question_text" placeholder="New question" style="flex:1" required>
        <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">+ Question</button>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
