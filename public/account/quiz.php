<?php
// ============================================================
// BraidedbyAGB — Learner quiz (theory test)
// FILE: /public/account/quiz.php  →  /account/learning/{e}/quiz/{quiz}
//
// Renders a quiz (no answer data), then grades server-side. All trust decisions
// live in includes/quiz.php. This page only verifies the learner owns the
// enrolment and passes POST data straight to the graded submit.
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';
require_once __DIR__ . '/../../includes/quiz.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

$result = null;     // set after a submit
$attempt = null;

// ── Submit (POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    $token   = (string)($_POST['token'] ?? '');
    $attempt = quizResolveAttempt($db, $token, $cid);   // binds to THIS client
    if (!$attempt) { header('Location: /account/learning'); exit; }

    $answers = [];
    foreach (($_POST['q'] ?? []) as $qid => $optId) {
        if (is_scalar($qid) && is_scalar($optId)) $answers[(int)$qid] = (int)$optId;
    }
    $result  = quizSubmitAttempt($db, $attempt, $answers);
    $quizId  = (int)$attempt['quiz_id'];
    $enrolId = (int)$attempt['enrolment_id'];
    $quiz    = quizHeader($db, $quizId);
    // Fall through to render the result screen below.
} else {
    // ── Start / resume (GET) ──────────────────────────────
    $enrolId = (int)($_GET['e'] ?? 0);
    $quizId  = (int)($_GET['quiz'] ?? 0);

    // The enrolment must belong to this client and be active/completed.
    $en = $db->prepare("SELECT e.id, e.course_id, e.status FROM course_enrolments e WHERE e.id = ? AND e.customer_id = ?");
    $en->execute([$enrolId, $cid]);
    $enrol = $en->fetch();
    if (!$enrol || !in_array($enrol['status'], ['active','completed'], true)) { header('Location: /account/learning'); exit; }

    $quiz = quizHeader($db, $quizId);
    // The quiz must belong to the enrolment's course.
    if (!$quiz || (int)$quiz['course_id'] !== (int)$enrol['course_id']) { header('Location: /account/learning/' . $enrolId); exit; }

    $attempt   = quizStartAttempt($db, $quizId, $enrolId);
    $questions = quizQuestionsForLearner($db, $quizId);
    $remaining = $attempt['expires_at'] !== null ? max(0, strtotime($attempt['expires_at']) - time()) : 0;
}

$pageTitle = $quiz['title'] ?? 'Quiz';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head">
    <h1><?= htmlspecialchars($quiz['title'] ?? 'Quiz') ?></h1>
  </div>
  <?php $activeTab = '/account/learning'; require __DIR__ . '/../../includes/account-tabs.php'; ?>
  <p style="margin:-6px 0 18px"><a class="auth-link" href="/account/learning/<?= (int)$enrolId ?>">‹ Back to course</a></p>

  <?php if ($result !== null): ?>
    <!-- ── Result ── -->
    <div class="account-card" style="text-align:center;padding:30px 16px">
      <div style="font-size:2.4rem"><?= $result['passed'] ? '🎉' : '📚' ?></div>
      <h2 style="font-family:'Montserrat',sans-serif;color:#7A0050;margin:8px 0"><?= $result['passed'] ? 'Passed!' : 'Not passed yet' ?></h2>
      <p style="font-size:1.4rem;font-weight:800;color:#2A0020"><?= (int)$result['score'] ?>%</p>
      <p class="muted" style="color:#6B5575">
        Pass mark <?= (int)($quiz['pass_mark'] ?? 0) ?>%<?= !empty($result['expired']) ? ' · time ran out' : '' ?>.
        <?= !empty($result['already']) ? ' (already submitted)' : '' ?>
      </p>
      <div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="/account/learning/<?= (int)$enrolId ?>" class="btn btn-outline">Back to course</a>
        <?php if (!$result['passed']): ?>
          <a href="/account/learning/<?= (int)$enrolId ?>/quiz/<?= (int)$quizId ?>" class="btn btn-gold">Try again</a>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif (empty($questions)): ?>
    <div class="account-card account-empty">This quiz has no questions yet. Please check back soon.</div>

  <?php else: ?>
    <!-- ── Take the quiz ── -->
    <?php if (!empty($remaining)): ?>
      <div class="account-card" style="margin-bottom:16px;text-align:center">
        ⏱ Time remaining: <strong id="qtimer">--:--</strong>
      </div>
    <?php endif; ?>
    <form method="POST" action="/account/learning/<?= (int)$enrolId ?>/quiz/<?= (int)$quizId ?>" id="quiz-form">
      <input type="hidden" name="action" value="submit">
      <input type="hidden" name="token" value="<?= htmlspecialchars($attempt['attempt_token']) ?>">
      <?php foreach ($questions as $i => $q): ?>
        <div class="account-card" style="margin-bottom:12px">
          <p style="font-weight:700;color:#2A0020;margin:0 0 10px"><?= ($i+1) ?>. <?= htmlspecialchars($q['question_text']) ?></p>
          <?php foreach ($q['options'] as $opt): ?>
            <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;cursor:pointer">
              <input type="radio" name="q[<?= (int)$q['id'] ?>]" value="<?= (int)$opt['id'] ?>">
              <span style="font-size:0.95rem;color:#3a2740"><?= htmlspecialchars($opt['option_text']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-gold btn-lg" style="width:100%">Submit answers</button>
    </form>

    <?php if (!empty($remaining)): ?>
    <script>
    (function(){
      var left = <?= (int)$remaining ?>, el = document.getElementById('qtimer'), form = document.getElementById('quiz-form'), sent=false;
      function tick(){
        if(left<=0){ el.textContent='0:00'; if(!sent){ sent=true; form.submit(); } return; }
        var m=Math.floor(left/60), s=left%60; el.textContent=m+':'+(s<10?'0':'')+s; left--; setTimeout(tick,1000);
      }
      tick();
    })();
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
