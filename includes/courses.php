<?php
// ============================================================
// BraidedbyAGB — Training academy helpers (enrolment + payment)
// FILE: /includes/courses.php
//
// Shared logic for course seats, recording course payments to the ledger, and
// keeping an enrolment's payment_status derived from what has actually been
// paid. Used by admin/enrolments.php (manual/waived) and the Stripe finalizer
// (Phase D3, online payment). Assumes config + helpers (createJournalEntry).
// ============================================================

if (!function_exists('createJournalEntry')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

/** Cash/asset account course money lands in, by method. */
function courseCashAccount(string $method): string {
    return match ($method) {
        'stripe'        => '1000',
        'cash'          => '1010',
        'bank_transfer' => '1020',
        default         => '1020',
    };
}

/** Seats left in a cohort (never negative). Non-withdrawn enrolments occupy a seat. */
function cohortSeatsLeft(PDO $db, int $cohortId): int {
    $c = $db->prepare("SELECT seats FROM course_cohorts WHERE id=?");
    $c->execute([$cohortId]);
    $seats = (int)($c->fetchColumn() ?: 0);
    $t = $db->prepare("SELECT COUNT(*) FROM course_enrolments WHERE cohort_id=? AND status<>'withdrawn'");
    $t->execute([$cohortId]);
    return max(0, $seats - (int)$t->fetchColumn());
}

/**
 * Post course income to the ledger: DR <cash/bank> / CR 4030. Idempotent per
 * payment row — skips if this course_payments row already carries a journal id.
 * Returns the journal entry id (or the existing one).
 */
function journalCoursePayment(PDO $db, int $paymentId): ?int {
    $p = $db->prepare("SELECT cp.*, e.course_id, c.title
                       FROM course_payments cp
                       JOIN course_enrolments e ON e.id = cp.enrolment_id
                       JOIN courses c ON c.id = e.course_id
                       WHERE cp.id=?");
    $p->execute([$paymentId]);
    $pay = $p->fetch();
    if (!$pay) return null;
    if (!empty($pay['journal_entry_id'])) return (int)$pay['journal_entry_id'];
    $amount = round((float)$pay['amount'], 2);
    if ($amount <= 0.005) return null;   // waived / zero — nothing to post

    $cash = courseCashAccount((string)$pay['method']);
    try {
        $jid = createJournalEntry(date('Y-m-d'), 'Course fee: ' . $pay['title'], 'course_payment', $paymentId, [
            ['account_code' => $cash,   'debit' => $amount, 'credit' => 0,       'memo' => 'Course fee received'],
            ['account_code' => '4030',  'debit' => 0,       'credit' => $amount, 'memo' => 'Course revenue: ' . $pay['title']],
        ], 'ENR-' . (int)$pay['enrolment_id']);
        $db->prepare("UPDATE course_payments SET journal_entry_id=? WHERE id=?")->execute([$jid, $paymentId]);
        return $jid;
    } catch (Throwable $e) {
        error_log('journalCoursePayment(' . $paymentId . '): ' . $e->getMessage());
        return null;
    }
}

/**
 * Recompute an enrolment's amount_paid + payment_status from its succeeded
 * payments. Called after any payment is recorded (manual or Stripe).
 */
function refreshEnrolmentPayment(PDO $db, int $enrolmentId): void {
    $e = $db->prepare("SELECT amount_due, payment_status FROM course_enrolments WHERE id=?");
    $e->execute([$enrolmentId]);
    $enr = $e->fetch();
    if (!$enr) return;
    // 'waived' is a deliberate admin state — don't let a £0 recompute flip it.
    if ($enr['payment_status'] === 'waived') return;

    $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM course_payments WHERE enrolment_id=? AND status='succeeded'");
    $s->execute([$enrolmentId]);
    $paid = round((float)$s->fetchColumn(), 2);
    $due  = round((float)$enr['amount_due'], 2);

    $status = 'unpaid';
    if ($paid > 0.005 && $due > 0.005 && $paid + 0.005 < $due) $status = 'deposit_paid';
    elseif ($paid > 0.005 && ($due <= 0.005 || $paid + 0.005 >= $due)) $status = 'paid';

    $db->prepare("UPDATE course_enrolments SET amount_paid=?, payment_status=? WHERE id=?")
       ->execute([$paid, $status, $enrolmentId]);
}
