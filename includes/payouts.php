<?php
// ============================================================
// BraidedbyAGB — Stylist payouts (settlement of earned wages)
// FILE: /includes/payouts.php
//
// A payout PAYS a stylist the assignments they have EARNED but not yet been
// paid for (Phase C3 marks assignments 'earned' when their booking completes).
// Creating a payout:
//   1. sums the stylist's earned + unpaid assignments (commission vs hourly),
//   2. writes a stylist_payouts row (commission_total + hourly_total +
//      adjustment = amount — the table's invariant),
//   3. LOCKS those assignments to the payout (payout_id set) so they can never
//      be edited or paid twice, and
//   4. posts the double-entry: DR 5300 commission / 5310 wages (+/- adjustment),
//      CR the cash/bank account the money left.
//
// Voiding reverses all four (mirror journal, unlock assignments, delete the row)
// so an owner mistake is fully recoverable and the ledger still balances.
//
// Assumes config/database.php + includes/helpers.php (createJournalEntry).
// ============================================================

if (!function_exists('createJournalEntry')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

/** Cash/asset account a payout leaves from, by method. */
function payoutCashAccount(string $method): string {
    return match ($method) {
        'cash'  => '1010',   // Cash on Hand
        default => '1020',   // Bank Account (bank_transfer / other)
    };
}

/**
 * What a stylist is currently owed: earned assignments not yet in a payout,
 * split by pay model. Returns ['commission','hourly','total','count'].
 */
function stylistOwed(PDO $db, int $stylistId): array {
    $s = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN pay_model = 'hourly' THEN earnings_amount ELSE 0 END), 0) AS hourly,
            COALESCE(SUM(CASE WHEN pay_model <> 'hourly' THEN earnings_amount ELSE 0 END), 0) AS commission,
            COUNT(*) AS cnt
        FROM booking_assignments
        WHERE stylist_id = ? AND earnings_status = 'earned' AND payout_id IS NULL
    ");
    $s->execute([$stylistId]);
    $r = $s->fetch() ?: ['hourly' => 0, 'commission' => 0, 'cnt' => 0];
    $commission = round((float)$r['commission'], 2);
    $hourly     = round((float)$r['hourly'], 2);
    return [
        'commission' => $commission,
        'hourly'     => $hourly,
        'total'      => round($commission + $hourly, 2),
        'count'      => (int)$r['cnt'],
    ];
}

/**
 * Post the double-entry for a payout. DR the expense accounts, CR the cash it
 * left. A positive adjustment adds to the commission expense; a negative one
 * credits it back — either way debits equal the net CR amount, so the entry
 * balances (createJournalEntry refuses it otherwise). Returns the entry id.
 */
function journalStylistPayout(
    PDO $db, int $payoutId, string $stylistName,
    float $commission, float $hourly, float $adjustment, float $amount,
    string $method, string $reference = ''
): int {
    $cash  = payoutCashAccount($method);
    $lines = [];
    if ($commission > 0.005) $lines[] = ['account_code' => '5300', 'debit' => round($commission, 2), 'credit' => 0, 'memo' => 'Commission'];
    if ($hourly     > 0.005) $lines[] = ['account_code' => '5310', 'debit' => round($hourly, 2),     'credit' => 0, 'memo' => 'Wages'];
    if ($adjustment > 0.005) {
        $lines[] = ['account_code' => '5300', 'debit' => round($adjustment, 2), 'credit' => 0, 'memo' => 'Adjustment'];
    } elseif ($adjustment < -0.005) {
        $lines[] = ['account_code' => '5300', 'debit' => 0, 'credit' => round(-$adjustment, 2), 'memo' => 'Adjustment'];
    }
    $lines[] = ['account_code' => $cash, 'debit' => 0, 'credit' => round($amount, 2), 'memo' => 'Stylist payout: ' . $stylistName];

    return createJournalEntry(date('Y-m-d'), 'Stylist payout: ' . $stylistName, 'stylist_payout', $payoutId, $lines, $reference);
}

/**
 * Create and record a payout for everything a stylist is currently owed.
 * $opts: adjustment, method, reference, notes, payout_date, period_start,
 * period_end, created_by. Atomic — all-or-nothing.
 *
 * @return array ['ok'=>bool, 'error'?=>string, 'payout_id'?=>int, 'amount'?=>float]
 */
function createStylistPayout(PDO $db, int $stylistId, array $opts = []): array {
    $st = $db->prepare("SELECT name FROM stylists WHERE id = ?");
    $st->execute([$stylistId]);
    $name = (string)($st->fetchColumn() ?: '');
    if ($name === '') return ['ok' => false, 'error' => 'Stylist not found.'];

    $adjustment = round((float)($opts['adjustment'] ?? 0), 2);
    $method    = in_array($opts['method'] ?? '', ['bank_transfer','cash','other'], true) ? $opts['method'] : 'bank_transfer';
    $date      = $opts['payout_date'] ?? date('Y-m-d');
    $ps        = $opts['period_start'] ?: null;
    $pe        = $opts['period_end']   ?: null;
    $reference = (string)($opts['reference'] ?? '');
    $notes     = (string)($opts['notes'] ?? '');
    $createdBy = (int)($opts['created_by'] ?? 0) ?: null;

    $db->beginTransaction();
    try {
        // Lock the exact rows we will settle for the duration of the transaction,
        // so a concurrent payout can't grab any between the read and the update —
        // the journal total then always matches the rows actually locked.
        $sel = $db->prepare("SELECT id, pay_model, earnings_amount FROM booking_assignments
                             WHERE stylist_id = ? AND earnings_status = 'earned' AND payout_id IS NULL
                             FOR UPDATE");
        $sel->execute([$stylistId]);
        $rows = $sel->fetchAll();

        $commission = 0.0; $hourly = 0.0;
        foreach ($rows as $r) {
            if ($r['pay_model'] === 'hourly') $hourly     += (float)$r['earnings_amount'];
            else                              $commission += (float)$r['earnings_amount'];
        }
        $commission = round($commission, 2);
        $hourly     = round($hourly, 2);
        $amount     = round($commission + $hourly + $adjustment, 2);

        if (!$rows && abs($adjustment) < 0.005) { $db->rollBack(); return ['ok' => false, 'error' => 'Nothing to pay out.']; }
        if ($amount <= 0.005)                    { $db->rollBack(); return ['ok' => false, 'error' => 'Payout total must be greater than zero.']; }

        $ins = $db->prepare("INSERT INTO stylist_payouts
            (stylist_id, payout_date, period_start, period_end, commission_total, hourly_total, adjustment, amount, method, reference, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([$stylistId, $date, $ps, $pe, $commission, $hourly, $adjustment, $amount, $method, $reference, $notes, $createdBy]);
        $payoutId = (int)$db->lastInsertId();

        if ($rows) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
            $db->exec("UPDATE booking_assignments SET payout_id = $payoutId WHERE id IN ($ids)");
        }

        $jid = journalStylistPayout($db, $payoutId, $name, $commission, $hourly, $adjustment, $amount, $method, $reference);
        $db->prepare("UPDATE stylist_payouts SET journal_entry_id = ? WHERE id = ?")->execute([$jid, $payoutId]);

        $db->commit();
        return ['ok' => true, 'payout_id' => $payoutId, 'amount' => $amount];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('createStylistPayout(' . $stylistId . '): ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not record the payout.'];
    }
}

/**
 * Void a payout: post the mirror of its journal entry (books balance, audit
 * preserved), unlock its assignments back to owed, and remove the payout row.
 * Atomic. Returns ['ok'=>bool, 'error'?=>string].
 */
function voidStylistPayout(PDO $db, int $payoutId): array {
    $s = $db->prepare("SELECT * FROM stylist_payouts WHERE id = ?");
    $s->execute([$payoutId]);
    $p = $s->fetch();
    if (!$p) return ['ok' => false, 'error' => 'Payout not found.'];

    $db->beginTransaction();
    try {
        // Mirror the original journal entry, if one was posted.
        if (!empty($p['journal_entry_id'])) {
            $ls = $db->prepare("SELECT a.code AS account_code, l.debit, l.credit, l.memo
                                FROM journal_entry_lines l JOIN accounts a ON a.id = l.account_id
                                WHERE l.journal_entry_id = ?");
            $ls->execute([(int)$p['journal_entry_id']]);
            $mirror = [];
            foreach ($ls->fetchAll() as $line) {
                $mirror[] = [
                    'account_code' => $line['account_code'],
                    'debit'        => (float)$line['credit'],
                    'credit'       => (float)$line['debit'],
                    'memo'         => 'Reversal: ' . (string)$line['memo'],
                ];
            }
            if ($mirror) {
                createJournalEntry(date('Y-m-d'), 'Void of payout #' . $payoutId,
                    'stylist_payout', $payoutId, $mirror, 'void:' . (int)$p['journal_entry_id']);
            }
        }

        // Unlock the assignments — they are owed again.
        $db->prepare("UPDATE booking_assignments SET payout_id = NULL WHERE payout_id = ?")->execute([$payoutId]);
        // Remove the payout row (the two journal entries net to zero and remain as audit).
        $db->prepare("DELETE FROM stylist_payouts WHERE id = ?")->execute([$payoutId]);

        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('voidStylistPayout(' . $payoutId . '): ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not void the payout.'];
    }
}
