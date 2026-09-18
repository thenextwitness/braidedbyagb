<?php
// ============================================================
// BraidedbyAGB — Booking lifecycle: status → money + loyalty
// FILE: /includes/bookings.php
//
// The ONE place a booking's status change drives its side effects.
// Every path that completes, un-completes or auto-incompletes a booking
// (web admin, the Android API, the cron, the Stripe Terminal flow) calls
// onBookingStatusChanged() AFTER updating bookings.status — instead of each
// re-implementing loyalty and journalling, which is how the web admin path
// came to award loyalty but never journal revenue while the API path did both.
//
// Revenue recognition rule (owner's requirement): a booking is income ONLY
// once it is marked 'completed'. A booking left unmarked is auto-marked
// 'incomplete' after a grace period — not successful, fees not taken. A paid
// deposit on an incomplete booking is forfeited to income (owner's decision).
//
// The ledger functions are DERIVED and SELF-HEALING: how much is held / whether
// revenue is recognised is read back from the journal itself, so a booking can
// move completed → incomplete → completed any number of times and the books
// always balance, nothing is ever double-posted, and no entry is ever deleted
// (reversals are posted as mirror entries, preserving the audit trail).
//
// Assumes config/database.php + includes/helpers.php are already loaded
// (createJournalEntry, adjustLoyaltyPoints, awardLoyaltyPoints, getSetting).
// ============================================================

if (!function_exists('createJournalEntry')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

// Journal sources that make up a single booking's money lifecycle. Kept in one
// place so the "net held / recognised" queries and the reversal logic agree.
const BOOKING_LEDGER_SOURCES = ['booking_deposit', 'booking_payment', 'booking_forfeit', 'booking_reversal'];

/**
 * Cash/asset account a booking's money lands in, by how it was paid.
 * Online card → Stripe (1000); bank transfer → Bank (1020); anything taken in
 * person on the day → Cash on Hand (1010).
 */
function bookingCashAccount(string $paymentMethod): string {
    return match ($paymentMethod) {
        'stripe'        => '1000',
        'bank_transfer' => '1020',
        default         => '1010',
    };
}

/**
 * Net amount currently sitting in "Customer Deposits Held" (2000) for a booking
 * — credits (deposits received) minus debits (deposits released/forfeited).
 * Derived from the ledger so it is always the true current liability.
 */
function bookingHeldDeposit(PDO $db, int $bookingId): float {
    $in = "'" . implode("','", BOOKING_LEDGER_SOURCES) . "'";
    $s = $db->prepare("
        SELECT COALESCE(SUM(l.credit - l.debit), 0)
        FROM journal_entry_lines l
        JOIN journal_entries e ON e.id = l.journal_entry_id
        JOIN accounts a        ON a.id = l.account_id
        WHERE e.source_id = ? AND a.code = '2000' AND e.source IN ($in)
    ");
    $s->execute([$bookingId]);
    return round((float) $s->fetchColumn(), 2);
}

/** True if service revenue (net credit to 4000) is currently recognised for a booking. */
function bookingRevenueRecognised(PDO $db, int $bookingId): bool {
    $in = "'" . implode("','", BOOKING_LEDGER_SOURCES) . "'";
    $s = $db->prepare("
        SELECT COALESCE(SUM(l.credit - l.debit), 0)
        FROM journal_entry_lines l
        JOIN journal_entries e ON e.id = l.journal_entry_id
        JOIN accounts a        ON a.id = l.account_id
        WHERE e.source_id = ? AND a.code = '4000' AND e.source IN ($in)
    ");
    $s->execute([$bookingId]);
    return round((float) $s->fetchColumn(), 2) > 0.005;
}

/**
 * Record a paid deposit as a held liability: DR <cash> / CR 2000.
 * Call whenever a booking's deposit_paid flips to 1. Idempotent — does nothing
 * if a deposit is already held or revenue has already been recognised (a booking
 * that was completed before its deposit row was journalled must not re-hold).
 */
function journalBookingDeposit(PDO $db, int $bookingId): void {
    $s = $db->prepare("SELECT booking_ref, deposit_amount, deposit_paid, payment_method FROM bookings WHERE id = ?");
    $s->execute([$bookingId]);
    $bk = $s->fetch();
    if (!$bk || !(int) $bk['deposit_paid']) return;

    $deposit = round((float) $bk['deposit_amount'], 2);
    if ($deposit <= 0.005) return;
    if (bookingHeldDeposit($db, $bookingId) > 0.005) return;   // already held
    if (bookingRevenueRecognised($db, $bookingId)) return;      // already completed

    $cash = bookingCashAccount((string) $bk['payment_method']);
    try {
        createJournalEntry(date('Y-m-d'), 'Deposit received: ' . $bk['booking_ref'], 'booking_deposit', $bookingId, [
            ['account_code' => $cash,   'debit' => $deposit, 'credit' => 0,        'memo' => 'Deposit received'],
            ['account_code' => '2000',  'debit' => 0,        'credit' => $deposit, 'memo' => 'Customer deposit held'],
        ], (string) $bk['booking_ref']);
    } catch (Throwable $e) {
        error_log('journalBookingDeposit(' . $bookingId . '): ' . $e->getMessage());
    }
}

/**
 * Post the mirror of every not-yet-reversed journal entry of $source for this
 * booking, backing it out without deleting anything. A reversal is itself an
 * entry (source 'booking_reversal', reference = the reversed entry's id), so
 * "not yet reversed" = no booking_reversal references that entry — which makes
 * this safe to call repeatedly.
 */
function reverseBookingLedger(PDO $db, int $bookingId, string $source, string $ref): void {
    $orig = $db->prepare("
        SELECT e.id
        FROM journal_entries e
        WHERE e.source_id = ? AND e.source = ?
          AND NOT EXISTS (
              SELECT 1 FROM journal_entries r
              WHERE r.source = 'booking_reversal' AND r.source_id = e.source_id
                AND r.reference = CAST(e.id AS CHAR)
          )
    ");
    $orig->execute([$bookingId, $source]);
    $ids = $orig->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $eid) {
        $ls = $db->prepare("
            SELECT a.code AS account_code, l.debit, l.credit, l.memo
            FROM journal_entry_lines l
            JOIN accounts a ON a.id = l.account_id
            WHERE l.journal_entry_id = ?
        ");
        $ls->execute([$eid]);
        $mirror = [];
        foreach ($ls->fetchAll() as $line) {
            // Swap debit and credit to reverse the original line.
            $mirror[] = [
                'account_code' => $line['account_code'],
                'debit'        => (float) $line['credit'],
                'credit'       => (float) $line['debit'],
                'memo'         => 'Reversal: ' . (string) $line['memo'],
            ];
        }
        if (!$mirror) continue;
        try {
            createJournalEntry(date('Y-m-d'), 'Reversal of entry #' . $eid . ': ' . $ref,
                'booking_reversal', $bookingId, $mirror, (string) $eid);
        } catch (Throwable $e) {
            error_log('reverseBookingLedger(' . $bookingId . ', ' . $source . '): ' . $e->getMessage());
        }
    }
}

/**
 * Forfeit a paid-but-unfulfilled deposit to income (owner's decision).
 * Uses whatever is genuinely held in 2000; if a deposit was paid but never
 * journalled (an older booking), brings that cash onto the books at the same
 * time (DR <cash> / CR 4020) so the forfeit income is never missed. Idempotent.
 */
function forfeitBookingDeposit(PDO $db, int $bookingId): void {
    // Already forfeited? A live booking_forfeit entry means yes.
    $chk = $db->prepare("
        SELECT COUNT(*) FROM journal_entries e
        WHERE e.source_id = ? AND e.source = 'booking_forfeit'
          AND NOT EXISTS (
              SELECT 1 FROM journal_entries r
              WHERE r.source = 'booking_reversal' AND r.source_id = e.source_id
                AND r.reference = CAST(e.id AS CHAR)
          )
    ");
    $chk->execute([$bookingId]);
    if ((int) $chk->fetchColumn() > 0) return;

    $s = $db->prepare("SELECT booking_ref, deposit_amount, deposit_paid, payment_method FROM bookings WHERE id = ?");
    $s->execute([$bookingId]);
    $bk = $s->fetch();
    if (!$bk) return;

    $held = bookingHeldDeposit($db, $bookingId);
    $lines = [];
    if ($held > 0.005) {
        // Convert the held liability into forfeit income.
        $lines[] = ['account_code' => '2000', 'debit' => $held, 'credit' => 0,     'memo' => 'Deposit forfeited'];
        $lines[] = ['account_code' => '4020', 'debit' => 0,     'credit' => $held, 'memo' => 'Late cancellation / forfeited deposit: ' . $bk['booking_ref']];
    } elseif ((int) $bk['deposit_paid'] && round((float) $bk['deposit_amount'], 2) > 0.005) {
        // Deposit was paid but never journalled — recognise cash + income together.
        $amt  = round((float) $bk['deposit_amount'], 2);
        $cash = bookingCashAccount((string) $bk['payment_method']);
        $lines[] = ['account_code' => $cash,   'debit' => $amt, 'credit' => 0,    'memo' => 'Forfeited deposit received'];
        $lines[] = ['account_code' => '4020',  'debit' => 0,    'credit' => $amt, 'memo' => 'Late cancellation / forfeited deposit: ' . $bk['booking_ref']];
    }
    if (!$lines) return;   // no deposit → nothing to forfeit

    try {
        createJournalEntry(date('Y-m-d'), 'Deposit forfeited: ' . $bk['booking_ref'], 'booking_forfeit', $bookingId, $lines, (string) $bk['booking_ref']);
    } catch (Throwable $e) {
        error_log('forfeitBookingDeposit(' . $bookingId . '): ' . $e->getMessage());
    }
}

/** Net loyalty points currently standing for a booking (earn minus reversal). */
function bookingLoyaltyNet(PDO $db, int $bookingId): int {
    $s = $db->prepare("SELECT COALESCE(SUM(points), 0) FROM loyalty_transactions WHERE booking_id = ? AND type IN ('earn','manual_remove')");
    $s->execute([$bookingId]);
    return (int) $s->fetchColumn();
}

/** Award completion loyalty once. Idempotent — a booking already carrying points is skipped. */
function awardBookingLoyalty(PDO $db, int $bookingId): void {
    if (!getSetting('loyalty_enabled', '1')) return;
    if (bookingLoyaltyNet($db, $bookingId) > 0) return;
    try { awardLoyaltyPoints($bookingId); } catch (Throwable $e) { error_log('awardBookingLoyalty(' . $bookingId . '): ' . $e->getMessage()); }
}

/** Reverse completion loyalty when a booking is no longer completed. Idempotent. */
function reverseBookingLoyalty(PDO $db, int $bookingId): void {
    $net = bookingLoyaltyNet($db, $bookingId);
    if ($net <= 0) return;
    $s = $db->prepare("SELECT customer_id FROM bookings WHERE id = ?");
    $s->execute([$bookingId]);
    $cid = (int) $s->fetchColumn();
    if ($cid <= 0) return;
    try {
        adjustLoyaltyPoints($cid, -$net, 'manual_remove', 'Booking not completed — points reversed', $bookingId);
    } catch (Throwable $e) {
        error_log('reverseBookingLoyalty(' . $bookingId . '): ' . $e->getMessage());
    }
}

/**
 * The single hook every status-changing path calls AFTER writing bookings.status.
 *
 *  → completed : recognise revenue (release any held deposit + collect the
 *                balance as cash, credit full total to 4000) and award loyalty.
 *                Any prior forfeit is reversed first.
 *  → anything else from completed : back the revenue out (deposit returns to
 *                2000-held) and reverse the loyalty.
 *  → incomplete : forfeit any held/paid deposit to income (4020).
 *
 * Reversibility falls out of the derived state: each action checks the ledger
 * and only posts what is missing, so repeat calls and back-and-forth status
 * changes never double-count. Stylist-earnings effects hang off this same hook
 * in Phase C.
 */
function onBookingStatusChanged(PDO $db, int $bookingId, string $newStatus): void {
    $s = $db->prepare("SELECT booking_ref, total_price, deposit_amount, deposit_paid, payment_method FROM bookings WHERE id = ?");
    $s->execute([$bookingId]);
    $bk = $s->fetch();
    if (!$bk) return;

    $ref   = (string) $bk['booking_ref'];
    $total = round((float) $bk['total_price'], 2);

    // Stylist earnings recognise on the same event as revenue. One idempotent
    // call here covers every path (→ completed marks them 'earned'; any other
    // status returns them to 'pending'); locked/voided rows are left alone.
    recalcBookingAssignments($db, $bookingId, $newStatus);

    if ($newStatus === 'completed') {
        // A booking corrected from incomplete back to completed: undo the forfeit first.
        reverseBookingLedger($db, $bookingId, 'booking_forfeit', $ref);

        if (!bookingRevenueRecognised($db, $bookingId) && $total > 0.005) {
            $held = bookingHeldDeposit($db, $bookingId);
            if ($held < 0)      $held = 0.0;
            if ($held > $total) $held = $total;              // never release more than the sale
            $balance = round($total - $held, 2);

            // Balance lands in the account matching how the booking is paid:
            // card/Terminal → Stripe (1000), bank transfer → Bank (1020),
            // otherwise Cash on Hand (1010).
            $balanceAccount = bookingCashAccount((string) $bk['payment_method']);

            $lines = [];
            if ($held > 0.005)    $lines[] = ['account_code' => '2000',          'debit' => $held,    'credit' => 0, 'memo' => 'Deposit released'];
            if ($balance > 0.005) $lines[] = ['account_code' => $balanceAccount, 'debit' => $balance, 'credit' => 0, 'memo' => 'Balance collected'];
            $lines[] = ['account_code' => '4000', 'debit' => 0, 'credit' => $total, 'memo' => 'Service revenue: ' . $ref];

            try {
                createJournalEntry(date('Y-m-d'), 'Booking completed: ' . $ref, 'booking_payment', $bookingId, $lines, $ref);
            } catch (Throwable $e) {
                error_log('onBookingStatusChanged completion journal (' . $bookingId . '): ' . $e->getMessage());
            }
        }
        awardBookingLoyalty($db, $bookingId);
        return;
    }

    // Any non-completed status: back out recognised revenue and loyalty.
    if (bookingRevenueRecognised($db, $bookingId)) {
        reverseBookingLedger($db, $bookingId, 'booking_payment', $ref);
    }
    reverseBookingLoyalty($db, $bookingId);

    // Incomplete specifically forfeits the deposit to income.
    if ($newStatus === 'incomplete') {
        forfeitBookingDeposit($db, $bookingId);
    }
}

// ============================================================
//  PHASE C3 — STYLIST EARNINGS (derived, self-healing)
//
//  A booking_assignments row records what ONE stylist will earn for ONE
//  service. Earnings follow the SAME recognition rule as revenue:
//    · pending — the booking is not (yet) completed. Nothing is owed.
//    · earned  — the booking is completed; the stylist is owed this amount.
//    · void    — explicitly written off by the owner (terminal; recalc leaves
//                it alone until the owner un-voids it).
//  Owner's rule "no stylist pay on incomplete" falls straight out of this:
//  an incomplete booking never reaches 'completed', so its assignments never
//  leave 'pending'. A booking bounced completed → incomplete → completed moves
//  its earnings earned → pending → earned with no drift, exactly like the ledger.
//
//  Money is NOT journalled here — an earned amount is a figure the owner owes,
//  posted to the books (5300/5310) only when a payout actually pays it
//  (Phase C4/C5, journalStylistPayout). Rows already tied to a payout
//  (payout_id set) are LOCKED and never recomputed.
// ============================================================

/**
 * The revenue a commission is calculated against for one assignment. Each cart
 * item is its own bookings row, so the whole line total (service + its add-ons)
 * is the natural base. Defined in ONE place so the rule is easy to change later.
 */
function assignmentCommissionBase(float $bookingTotal): float {
    return round($bookingTotal, 2);
}

/**
 * Pure calculation of one assignment's base + amount from its pay model.
 * Rates fall back to the stylist's current defaults when the assignment has no
 * snapshot of its own. $a must carry the assignment columns plus the joined
 * default_commission_pct / default_hourly_rate.
 *
 * Returns ['base' => ?float, 'amount' => float]. base is the commission base
 * (NULL for hourly / none — the amount there is hours × rate, not a % of a base).
 */
function computeAssignmentEarnings(array $a, float $bookingTotal): array {
    switch ($a['pay_model']) {
        case 'hourly':
            $rate  = isset($a['hourly_rate']) && $a['hourly_rate'] !== null
                   ? (float) $a['hourly_rate'] : (float) ($a['default_hourly_rate'] ?? 0);
            // Prefer hours actually worked; fall back to the planned figure.
            $hours = isset($a['hours_worked']) && $a['hours_worked'] !== null
                   ? (float) $a['hours_worked']
                   : (isset($a['hours_planned']) && $a['hours_planned'] !== null ? (float) $a['hours_planned'] : 0.0);
            return ['base' => null, 'amount' => round($hours * $rate, 2)];

        case 'commission':
            $pct  = isset($a['commission_pct']) && $a['commission_pct'] !== null
                  ? (float) $a['commission_pct'] : (float) ($a['default_commission_pct'] ?? 0);
            $base = assignmentCommissionBase($bookingTotal);
            return ['base' => $base, 'amount' => round($base * $pct / 100, 2)];

        default: // 'none' — e.g. the owner working her own booking
            return ['base' => null, 'amount' => 0.0];
    }
}

/**
 * Recompute every non-locked assignment on a booking and align its
 * earnings_status with the booking's state. Idempotent and self-healing: safe
 * to call after any status change AND after the assignment set is edited
 * (Phase C4 calls it on save). Locked rows (attached to a payout) and rows the
 * owner has voided are left untouched.
 *
 * @param string|null $status The booking's effective status. Pass the value the
 *   caller just wrote (avoids a re-read race); when null it is read from the row.
 */
function recalcBookingAssignments(PDO $db, int $bookingId, ?string $status = null): void {
    try {
        $bk = $db->prepare("SELECT status, total_price FROM bookings WHERE id = ?");
        $bk->execute([$bookingId]);
        $row = $bk->fetch();
        if (!$row) return;

        $status = $status ?? (string) $row['status'];
        $total  = round((float) $row['total_price'], 2);
        // Earnings are recognised on exactly the same event as revenue.
        $target = $status === 'completed' ? 'earned' : 'pending';

        $sel = $db->prepare("
            SELECT ba.id, ba.pay_model, ba.commission_pct, ba.hourly_rate,
                   ba.hours_planned, ba.hours_worked,
                   s.default_commission_pct, s.default_hourly_rate
            FROM booking_assignments ba
            JOIN stylists s ON s.id = ba.stylist_id
            WHERE ba.booking_id = ? AND ba.payout_id IS NULL AND ba.earnings_status <> 'void'
        ");
        $sel->execute([$bookingId]);
        $rows = $sel->fetchAll();
        if (!$rows) return;

        $upd = $db->prepare("UPDATE booking_assignments
                             SET earnings_base = ?, earnings_amount = ?, earnings_status = ?
                             WHERE id = ?");
        foreach ($rows as $a) {
            $calc = computeAssignmentEarnings($a, $total);
            $upd->execute([$calc['base'], $calc['amount'], $target, (int) $a['id']]);
        }
    } catch (Throwable $e) {
        error_log('recalcBookingAssignments(' . $bookingId . '): ' . $e->getMessage());
    }
}
