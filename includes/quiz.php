<?php
// ============================================================
// BraidedbyAGB — Quiz engine (Phase D5)
// FILE: /includes/quiz.php
//
// SECURITY MODEL (this file is the whole trust boundary):
//   • The correct answer (course_question_options.is_correct) is NEVER selected
//     into anything the learner receives. quizQuestionsForLearner() omits it.
//   • An attempt is bound to ONE enrolment by a random 64-char token. Every
//     read/submit re-checks the attempt belongs to THIS signed-in client, so a
//     tampered/guessed token or another learner's attempt id is rejected.
//   • Grading is done here from the DB, per question, ignoring anything the
//     client claims: unknown question ids are ignored, an option that doesn't
//     belong to its question is treated as wrong, double-submit is a no-op, and
//     an expired timed attempt is auto-submitted with only what was valid.
//
// Assumes config + helpers are loaded.
// ============================================================

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

/** Active quizzes for a course (no answer data). */
function quizzesForCourse(PDO $db, int $courseId): array {
    $s = $db->prepare("SELECT id, title, pass_mark, time_limit_mins,
                              (SELECT COUNT(*) FROM course_questions q WHERE q.quiz_id = z.id) AS question_count
                       FROM course_quizzes z WHERE z.course_id = ? AND z.is_active = 1 ORDER BY z.id");
    $s->execute([$courseId]);
    return $s->fetchAll();
}

/** A quiz header (no answer data). */
function quizHeader(PDO $db, int $quizId): ?array {
    $s = $db->prepare("SELECT * FROM course_quizzes WHERE id = ? AND is_active = 1");
    $s->execute([$quizId]);
    return $s->fetch() ?: null;
}

/**
 * Questions + options for the learner — deliberately WITHOUT is_correct.
 * Returns [ ['id','question_text','options'=>[['id','option_text'],...]], ... ].
 */
function quizQuestionsForLearner(PDO $db, int $quizId): array {
    $qs = $db->prepare("SELECT id, question_text FROM course_questions WHERE quiz_id = ? ORDER BY display_order, id");
    $qs->execute([$quizId]);
    $questions = $qs->fetchAll();
    if (!$questions) return [];
    $ids = implode(',', array_map(fn($q) => (int)$q['id'], $questions));
    // NOTE: is_correct is intentionally excluded from this SELECT.
    $opts = $db->query("SELECT id, question_id, option_text FROM course_question_options
                        WHERE question_id IN ($ids) ORDER BY display_order, id")->fetchAll();
    $byQ = [];
    foreach ($opts as $o) $byQ[(int)$o['question_id']][] = ['id' => (int)$o['id'], 'option_text' => $o['option_text']];
    foreach ($questions as &$q) $q['options'] = $byQ[(int)$q['id']] ?? [];
    return $questions;
}

/**
 * Start or resume an attempt for this enrolment. Resumes an existing unsubmitted,
 * unexpired attempt (so a refresh doesn't spawn duplicates); otherwise creates a
 * new one with a token and, for timed quizzes, an expiry.
 */
function quizStartAttempt(PDO $db, int $quizId, int $enrolmentId): array {
    $ex = $db->prepare("SELECT * FROM course_quiz_attempts
                        WHERE quiz_id = ? AND enrolment_id = ? AND submitted_at IS NULL
                        ORDER BY id DESC LIMIT 1");
    $ex->execute([$quizId, $enrolmentId]);
    $open = $ex->fetch();
    if ($open && ($open['expires_at'] === null || strtotime($open['expires_at']) > time())) {
        return $open;
    }
    $quiz = quizHeader($db, $quizId);
    $mins = $quiz && $quiz['time_limit_mins'] !== null ? (int)$quiz['time_limit_mins'] : 0;
    $token   = bin2hex(random_bytes(32));
    $expires = $mins > 0 ? date('Y-m-d H:i:s', time() + $mins * 60) : null;
    $db->prepare("INSERT INTO course_quiz_attempts (quiz_id, enrolment_id, attempt_token, expires_at) VALUES (?,?,?,?)")
       ->execute([$quizId, $enrolmentId, $token, $expires]);
    $id = (int)$db->lastInsertId();
    $g = $db->prepare("SELECT * FROM course_quiz_attempts WHERE id = ?");
    $g->execute([$id]);
    return $g->fetch();
}

/**
 * Resolve an attempt by its token AND confirm it belongs to the signed-in
 * client (via the enrolment). Returns the attempt row (with quiz_id,
 * enrolment_id) or null. This is the guard against token tampering and opening
 * another learner's attempt.
 */
function quizResolveAttempt(PDO $db, string $token, int $clientId): ?array {
    if (strlen($token) < 32) return null;
    $s = $db->prepare("SELECT a.* FROM course_quiz_attempts a
                       JOIN course_enrolments e ON e.id = a.enrolment_id
                       WHERE a.attempt_token = ? AND e.customer_id = ? LIMIT 1");
    $s->execute([$token, $clientId]);
    return $s->fetch() ?: null;
}

/**
 * Grade + persist a submission. $posted maps question_id => chosen option_id
 * (both as sent by the client — fully re-validated here). Idempotent: a second
 * submit is a no-op that returns the stored result.
 *
 * @return array ['ok'=>bool, 'score'=>int, 'passed'=>bool, 'total'=>int, 'correct'=>int, 'already'=>bool, 'expired'=>bool]
 */
function quizSubmitAttempt(PDO $db, array $attempt, array $posted): array {
    $attemptId = (int)$attempt['id'];
    $quizId    = (int)$attempt['quiz_id'];

    if ($attempt['submitted_at'] !== null) {
        return ['ok' => true, 'already' => true, 'score' => (int)$attempt['score'],
                'passed' => (bool)$attempt['passed'], 'total' => 0, 'correct' => 0, 'expired' => false];
    }
    // Grace: the client auto-submits at 0, whose POST lands a second or two later.
    // Within grace we still grade; well past it (JS disabled, submitting late) the
    // attempt is expired and scores 0 so the time limit can't be bypassed.
    $expired = $attempt['expires_at'] !== null && strtotime($attempt['expires_at']) < time();
    $hardExpired = $attempt['expires_at'] !== null && strtotime($attempt['expires_at']) + 60 < time();

    $quiz = quizHeader($db, $quizId);
    if (!$quiz) return ['ok' => false];
    $passMark = (int)$quiz['pass_mark'];

    // Ran out of time (beyond grace): record a submitted, zero-score attempt.
    if ($hardExpired) {
        $upd = $db->prepare("UPDATE course_quiz_attempts SET submitted_at = NOW(), score = 0, passed = 0
                             WHERE id = ? AND submitted_at IS NULL");
        $upd->execute([$attemptId]);
        return ['ok' => true, 'already' => $upd->rowCount() === 0, 'score' => 0, 'passed' => false,
                'total' => 0, 'correct' => 0, 'expired' => true];
    }

    // Authoritative data — questions of this quiz + the correct option per
    // question + a map of which question each option legitimately belongs to.
    $questions = $db->prepare("SELECT id FROM course_questions WHERE quiz_id = ?");
    $questions->execute([$quizId]);
    $qIds = array_map(fn($r) => (int)$r['id'], $questions->fetchAll());
    $total = count($qIds);

    $correctByQ = []; $questionOfOption = [];
    if ($qIds) {
        $in = implode(',', $qIds);
        foreach ($db->query("SELECT id, question_id, is_correct FROM course_question_options WHERE question_id IN ($in)")->fetchAll() as $o) {
            $questionOfOption[(int)$o['id']] = (int)$o['question_id'];
            if ((int)$o['is_correct'] === 1) $correctByQ[(int)$o['question_id']] = (int)$o['id'];
        }
    }

    $correct = 0;
    $ins = $db->prepare("INSERT INTO course_quiz_answers (attempt_id, question_id, option_id, is_correct)
                         VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE option_id = VALUES(option_id), is_correct = VALUES(is_correct)");
    foreach ($qIds as $qid) {
        $chosenRaw = $posted[$qid] ?? $posted[(string)$qid] ?? null;
        $chosen = ($chosenRaw !== null && $chosenRaw !== '') ? (int)$chosenRaw : null;
        // Reject an option that doesn't belong to THIS question (cross-question tamper).
        if ($chosen !== null && (($questionOfOption[$chosen] ?? 0) !== $qid)) $chosen = null;
        $isRight = ($chosen !== null && isset($correctByQ[$qid]) && $chosen === $correctByQ[$qid]) ? 1 : 0;
        if ($isRight) $correct++;
        $ins->execute([$attemptId, $qid, $chosen, $isRight]);
    }

    $score  = $total > 0 ? (int)round($correct / $total * 100) : 0;
    $passed = $score >= $passMark ? 1 : 0;

    // Double-submit guard: only the first submit (submitted_at IS NULL) writes.
    $upd = $db->prepare("UPDATE course_quiz_attempts SET submitted_at = NOW(), score = ?, passed = ?
                         WHERE id = ? AND submitted_at IS NULL");
    $upd->execute([$score, $passed, $attemptId]);
    if ($upd->rowCount() === 0) {
        // Someone/something submitted concurrently — return the stored result.
        $g = $db->prepare("SELECT score, passed FROM course_quiz_attempts WHERE id = ?");
        $g->execute([$attemptId]);
        $row = $g->fetch() ?: ['score' => 0, 'passed' => 0];
        return ['ok' => true, 'already' => true, 'score' => (int)$row['score'],
                'passed' => (bool)$row['passed'], 'total' => $total, 'correct' => $correct, 'expired' => $expired];
    }

    return ['ok' => true, 'already' => false, 'score' => $score, 'passed' => (bool)$passed,
            'total' => $total, 'correct' => $correct, 'expired' => $expired];
}

/** Best (highest-scoring) submitted attempt for an enrolment on a quiz, or null. */
function quizBestAttempt(PDO $db, int $quizId, int $enrolmentId): ?array {
    $s = $db->prepare("SELECT score, passed, submitted_at FROM course_quiz_attempts
                       WHERE quiz_id = ? AND enrolment_id = ? AND submitted_at IS NOT NULL
                       ORDER BY passed DESC, score DESC LIMIT 1");
    $s->execute([$quizId, $enrolmentId]);
    return $s->fetch() ?: null;
}
