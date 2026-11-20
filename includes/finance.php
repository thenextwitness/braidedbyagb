<?php
// ============================================================
// BraidedbyAGB — Finance: complete money tracking, reports, expenses,
// refunds and money owed.
// FILE: /includes/finance.php  (loaded at the foot of helpers.php)
//
// Same principle as includes/bookings.php: the ledger is DERIVED and
// SELF-HEALING. Each sync function works out what the books SHOULD show for one
// order/booking from its current state, compares it with what the journal DOES
// show, and posts only the difference (never deletes). So every path can call
// it, calling it twice changes nothing, and the scheduler's sweep (job 14)
// fixes anything a path missed — including history from before this existed.
//
//   Shop order paid      → DR cash / CR 4010 Product Sales (+ 4012 Delivery)
//   … cancelled/refunded → reversed (less any refund already recorded)
//   Travel fee paid      → DR cash / CR 2000 held, → 4011 Travel Fees when the
//                          appointment is completed (or forfeited if incomplete)
//   Products bought with a booking: the money is part of the booking's price
//   (4000 on completion), so they're NOT posted again here — but their order is
//   marked paid and stock comes off/goes back with the booking.
//
// Accounts used: 1000 Stripe, 1010 Cash, 1020 Bank, 2000 Deposits Held,
// 4000 Service, 4010 Product Sales, 4011 Travel Fees, 4012 Delivery Charges,
// 4020 Forfeits, 4030 Courses, 5100 Business Expenses, 5200 Owner's Draw.
// ============================================================

const FINANCE_DEAD_BOOKING = ['cancelled', 'rejected', 'late_cancelled', 'no_show'];

/** Expense categories (roughly HMRC Self Assessment boxes). */
const EXPENSE_CATEGORIES = [
    'Hair & supplies (stock)', 'Equipment & tools', 'Rent & premises', 'Travel & mileage',
    'Marketing & advertising', 'Phone & internet', 'Software & subscriptions', 'Training & courses',
    'Insurance', 'Bank & card fees', 'Accountancy & legal', 'Other',
];

/** Where money for an expense/refund comes from → cash account code. */
function financeCashAccount(string $from): string {
    return match ($from) { 'stripe', 'card_stripe' => '1000', 'cash' => '1010', default => '1020' };
}

/** A date for back-filled history vs live events: old events keep their own date. */
function financeEventDate(?string $when): string {
    $d = $when ? substr($when, 0, 10) : '';
    return ($d !== '' && $d < date('Y-m-d', strtotime('-3 days'))) ? $d : date('Y-m-d');
}

/** Date the finance tracking went live. History before it is only back-filled for real, kept money. */
const FINANCE_TRACKING_SINCE = '2026-11-20';

/**
 * Run $fn holding a named database lock, so two syncs (an admin action and the
 * scheduler) can never post the same difference twice. Skips if the lock is busy;
 * the next sweep catches up.
 */
function financeWithLock(PDO $db, string $name, callable $fn): void {
    $got = $db->prepare("SELECT GET_LOCK(?, 5)");
    $got->execute(['agb_fin_' . $name]);
    if ((int)$got->fetchColumn() !== 1) { error_log("finance lock busy: $name"); return; }
    try { $fn(); }
    finally { $db->prepare("SELECT RELEASE_LOCK(?)")->execute(['agb_fin_' . $name]); }
}

/** Net (credit − debit) on the given account codes for one source_id across sources. */
function financeNet(PDO $db, int $sourceId, array $sources, array $codes): float {
    $in  = implode(',', array_fill(0, count($sources), '?'));
    $cin = implode(',', array_fill(0, count($codes), '?'));
    $q = $db->prepare("SELECT COALESCE(SUM(l.credit - l.debit), 0)
                       FROM journal_entry_lines l
                       JOIN journal_entries e ON e.id = l.journal_entry_id
                       JOIN accounts a ON a.id = l.account_id
                       WHERE e.source_id = ? AND e.source IN ($in) AND a.code IN ($cin)");
    $q->execute(array_merge([$sourceId], $sources, $codes));
    return round((float)$q->fetchColumn(), 2);
}

// ─────────────────────────────────────────────────────────
// Stock
// ─────────────────────────────────────────────────────────
/** Which variant row holds stock for an item: its own, else the product's only variant, else none. */
function stockVariantFor(PDO $db, int $productId, ?int $variantId): ?int {
    if ($variantId) return $variantId;
    $q = $db->prepare("SELECT id FROM product_variants WHERE product_id = ? LIMIT 2");
    $q->execute([$productId]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;   // several colours/sizes: can't tell which
}

function adjustOrderStock(PDO $db, int $orderId, int $sign): void {
    $items = $db->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?");
    $items->execute([$orderId]);
    foreach ($items->fetchAll() as $it) {
        $vid = stockVariantFor($db, (int)$it['product_id'], $it['variant_id'] !== null ? (int)$it['variant_id'] : null);
        if (!$vid || (int)$it['quantity'] < 1) continue;
        if ($sign < 0) $db->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?")->execute([(int)$it['quantity'], $vid]);
        else           $db->prepare("UPDATE product_variants SET stock_qty = stock_qty + ? WHERE id = ?")->execute([(int)$it['quantity'], $vid]);
    }
}

/** Shop order: cancelled/refunded with stock taken → put it back. Idempotent via orders.stock_deducted. */
function syncOrderStock(PDO $db, int $orderId): void {
    $q = $db->prepare("SELECT status, stock_deducted, from_pipeline FROM orders WHERE id = ?");
    $q->execute([$orderId]);
    $o = $q->fetch();
    if (!$o || (int)$o['from_pipeline'] === 1) return;   // pipeline orders follow their booking
    $dead = in_array($o['status'], ['cancelled', 'refunded'], true);
    if ($dead && (int)$o['stock_deducted'] === 1) {
        adjustOrderStock($db, $orderId, +1);
        // 3 = "we put it back": lets a reinstated order take it again (0 = never taken).
        $db->prepare("UPDATE orders SET stock_deducted = 3 WHERE id = ?")->execute([$orderId]);
    } elseif (!$dead && (int)$o['stock_deducted'] === 3) {
        adjustOrderStock($db, $orderId, -1);
        $db->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = ?")->execute([$orderId]);
    }
}

/** Products bought with a booking follow it: paid + stock out while it's live; cancelled + stock back if it falls through. */
function syncPipelineOrders(PDO $db, int $bookingId): void {
    $b = $db->prepare("SELECT status, deposit_paid FROM bookings WHERE id = ?");
    $b->execute([$bookingId]);
    $bk = $b->fetch();
    if (!$bk) return;
    $dead = in_array($bk['status'], FINANCE_DEAD_BOOKING, true);
    $live = !$dead && ((int)$bk['deposit_paid'] === 1 || $bk['status'] === 'completed');
    $os = $db->prepare("SELECT id, status, payment_status, stock_deducted FROM orders WHERE booking_id = ? AND from_pipeline = 1");
    $os->execute([$bookingId]);
    foreach ($os->fetchAll() as $o) {
        $oid = (int)$o['id'];
        if ($dead && $o['status'] !== 'cancelled') $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$oid]);
        if ($live && $o['status'] === 'cancelled') $db->prepare("UPDATE orders SET status = 'pending' WHERE id = ?")->execute([$oid]);   // booking reinstated
        if ($live && ($o['payment_status'] ?? '') !== 'paid') $db->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = ?")->execute([$oid]);
        // stock_deducted 2 = from before stock tracking existed: never touch its stock.
        if ((int)$o['stock_deducted'] === 2) continue;
        if ($live && (int)$o['stock_deducted'] === 0) {
            adjustOrderStock($db, $oid, -1);
            $db->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = ?")->execute([$oid]);
        } elseif (!$live && (int)$o['stock_deducted'] === 1) {
            adjustOrderStock($db, $oid, +1);
            $db->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = ?")->execute([$oid]);
        }
    }
}

// ─────────────────────────────────────────────────────────
// Ledger syncs
// ─────────────────────────────────────────────────────────
const ORDER_LEDGER_SOURCES = ['order_payment', 'order_reversal', 'order_refund'];

/** Shop order income: should equal (total − refunds) while paid and live, else 0. */
function syncOrderLedger(PDO $db, int $orderId): void {
    financeWithLock($db, 'order_' . $orderId, fn() => syncOrderLedgerLocked($db, $orderId));
}

function syncOrderLedgerLocked(PDO $db, int $orderId): void {
    $q = $db->prepare("SELECT order_ref, total, shipping_cost, payment_status, status, payment_method, from_pipeline, created_at FROM orders WHERE id = ?");
    $q->execute([$orderId]);
    $o = $q->fetch();
    if (!$o || (int)$o['from_pipeline'] === 1) return;   // money is inside the booking's price

    $r = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE kind = 'order' AND ref_id = ?");
    $r->execute([$orderId]);
    $refunded = round((float)$r->fetchColumn(), 2);

    $live   = ($o['payment_status'] ?? '') === 'paid' && !in_array($o['status'], ['cancelled', 'refunded'], true);
    $want   = $live ? max(0, round((float)$o['total'] - $refunded, 2)) : 0.0;
    $have   = financeNet($db, $orderId, ORDER_LEDGER_SOURCES, ['4010', '4012']);
    $diff   = round($want - $have, 2);
    if (abs($diff) < 0.005) return;

    $cash = financeCashAccount((string)$o['payment_method'] === 'stripe' ? 'stripe' : 'bank');
    $ref  = (string)$o['order_ref'];
    if ($diff > 0) {
        // Split delivery out only on the first full posting.
        $ship  = ($have < 0.005 && $refunded < 0.005) ? min($diff, round((float)$o['shipping_cost'], 2)) : 0.0;
        $lines = [['account_code' => $cash, 'debit' => $diff, 'credit' => 0, 'memo' => 'Order payment ' . $ref]];
        if ($diff - $ship > 0.005) $lines[] = ['account_code' => '4010', 'debit' => 0, 'credit' => round($diff - $ship, 2), 'memo' => 'Product sales ' . $ref];
        if ($ship > 0.005)         $lines[] = ['account_code' => '4012', 'debit' => 0, 'credit' => $ship, 'memo' => 'Delivery ' . $ref];
        createJournalEntry(financeEventDate($o['created_at']), 'Shop order paid: ' . $ref, 'order_payment', $orderId, $lines, $ref);
    } else {
        $amt   = -$diff;
        // Fully reversed: take delivery back out of 4012 first, the rest from 4010.
        $ship  = $want < 0.005 ? min($amt, max(0, financeNet($db, $orderId, ORDER_LEDGER_SOURCES, ['4012']))) : 0.0;
        $lines = [];
        if ($amt - $ship > 0.005) $lines[] = ['account_code' => '4010', 'debit' => round($amt - $ship, 2), 'credit' => 0, 'memo' => 'Order reversed ' . $ref];
        if ($ship > 0.005)        $lines[] = ['account_code' => '4012', 'debit' => $ship, 'credit' => 0, 'memo' => 'Delivery reversed ' . $ref];
        $lines[] = ['account_code' => $cash, 'debit' => 0, 'credit' => $amt, 'memo' => 'Money returned ' . $ref];
        createJournalEntry(date('Y-m-d'), 'Shop order cancelled/refunded: ' . $ref, 'order_reversal', $orderId, $lines, $ref);
    }
}

/** Travel fee (paid up front with the deposit): held until the appointment, then income. */
function syncBookingTravelLedger(PDO $db, int $bookingId): void {
    financeWithLock($db, 'travel_' . $bookingId, fn() => syncBookingTravelLedgerLocked($db, $bookingId));
}

function syncBookingTravelLedgerLocked(PDO $db, int $bookingId): void {
    $q = $db->prepare("SELECT booking_ref, travel_fee, deposit_paid, status, payment_method, created_at, booked_date FROM bookings WHERE id = ?");
    $q->execute([$bookingId]);
    $b = $q->fetch();
    if (!$b) return;
    $fee = round((float)($b['travel_fee'] ?? 0), 2);
    if ($fee <= 0.005 || (int)$b['deposit_paid'] !== 1) return;
    $ref  = (string)$b['booking_ref'];
    $cash = bookingCashAccount((string)$b['payment_method']);

    // Cancelled/rejected before tracking began: the fee was almost certainly
    // refunded back then, so it doesn't belong on the books. Undo anything the
    // first back-fill posted, on the same date it was posted.
    if (in_array($b['status'], ['cancelled', 'rejected'], true) && substr((string)$b['created_at'], 0, 10) < FINANCE_TRACKING_SINCE) {
        $q = $db->prepare("SELECT e.entry_date, a.code, SUM(l.debit) AS dr, SUM(l.credit) AS cr
                           FROM journal_entries e JOIN journal_entry_lines l ON l.journal_entry_id = e.id JOIN accounts a ON a.id = l.account_id
                           WHERE e.source = 'booking_travel' AND e.source_id = ? GROUP BY e.entry_date, a.code");
        $q->execute([$bookingId]);
        $net = []; $firstDate = null;
        foreach ($q->fetchAll() as $r) {
            $net[$r['code']] = round(($net[$r['code']] ?? 0) + (float)$r['dr'] - (float)$r['cr'], 2);
            $firstDate = $firstDate === null ? $r['entry_date'] : min($firstDate, $r['entry_date']);
        }
        $lines = [];
        foreach ($net as $code => $v) {
            if ($v > 0.005)      $lines[] = ['account_code' => $code, 'debit' => 0,   'credit' => $v,  'memo' => 'Back-fill undone: cancelled before tracking'];
            elseif ($v < -0.005) $lines[] = ['account_code' => $code, 'debit' => -$v, 'credit' => 0,   'memo' => 'Back-fill undone: cancelled before tracking'];
        }
        if ($lines) createJournalEntry($firstDate ?? date('Y-m-d'), 'Travel fee back-fill undone: ' . $ref, 'booking_travel', $bookingId, $lines, $ref);
        return;
    }

    $cashIn = -financeNet($db, $bookingId, ['booking_travel'], ['1000', '1010', '1020']);   // debits are cash in
    if ($cashIn < 0.005) {
        createJournalEntry(financeEventDate($b['created_at']), 'Travel fee received: ' . $ref, 'booking_travel', $bookingId, [
            ['account_code' => $cash,  'debit' => $fee, 'credit' => 0,    'memo' => 'Home-service travel fee'],
            ['account_code' => '2000', 'debit' => 0,    'credit' => $fee, 'memo' => 'Travel fee held until the appointment'],
        ], $ref);
    }
    $held   = financeNet($db, $bookingId, ['booking_travel'], ['2000']);
    $income = financeNet($db, $bookingId, ['booking_travel'], ['4011']);
    $earn   = in_array($b['status'], ['completed', 'incomplete'], true);
    if ($earn && $held > 0.005) {
        createJournalEntry(financeEventDate($b['booked_date']), 'Travel fee earned: ' . $ref, 'booking_travel', $bookingId, [
            ['account_code' => '2000', 'debit' => $held, 'credit' => 0,     'memo' => 'Travel fee released'],
            ['account_code' => '4011', 'debit' => 0,     'credit' => $held, 'memo' => 'Travel fee income ' . $ref],
        ], $ref);
    } elseif (!$earn && $income > 0.005) {
        createJournalEntry(date('Y-m-d'), 'Travel fee back to held: ' . $ref, 'booking_travel', $bookingId, [
            ['account_code' => '4011', 'debit' => $income, 'credit' => 0,       'memo' => 'Travel fee not earned (status ' . $b['status'] . ')'],
            ['account_code' => '2000', 'debit' => 0,       'credit' => $income, 'memo' => 'Travel fee held again'],
        ], $ref);
    }
}

/** Everything money-related for one booking beyond its own deposit/revenue (called from onBookingStatusChanged + finalizers). */
function syncBookingExtras(PDO $db, int $bookingId): void {
    try { syncBookingTravelLedger($db, $bookingId); } catch (Throwable $e) { error_log("syncBookingTravelLedger($bookingId): " . $e->getMessage()); }
    try { syncPipelineOrders($db, $bookingId); }      catch (Throwable $e) { error_log("syncPipelineOrders($bookingId): " . $e->getMessage()); }
}

/** Everything for one shop order (called when its status or payment changes). */
function syncOrderExtras(PDO $db, int $orderId): void {
    try { syncOrderLedger($db, $orderId); } catch (Throwable $e) { error_log("syncOrderLedger($orderId): " . $e->getMessage()); }
    try { syncOrderStock($db, $orderId); }  catch (Throwable $e) { error_log("syncOrderStock($orderId): " . $e->getMessage()); }
}

/** Scheduler job 14: bring the whole history in line + post recurring expenses. Returns counts. */
function financeSweep(PDO $db): array {
    $n = ['orders' => 0, 'bookings' => 0, 'recurring' => 0];
    foreach ($db->query("SELECT id FROM orders WHERE from_pipeline = 0 AND (payment_status = 'paid' OR status IN ('cancelled','refunded'))")->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        syncOrderExtras($db, (int)$oid); $n['orders']++;
    }
    foreach ($db->query("SELECT DISTINCT b.id FROM bookings b LEFT JOIN orders o ON o.booking_id = b.id AND o.from_pipeline = 1
                         WHERE b.travel_fee > 0 OR o.id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        syncBookingExtras($db, (int)$bid); $n['bookings']++;
    }
    $n['recurring'] = postRecurringExpenses($db);
    return $n;
}

// ─────────────────────────────────────────────────────────
// Refunds
// ─────────────────────────────────────────────────────────
/**
 * Record money given back to a client (the refund itself is done in Stripe / your bank).
 * $kind 'booking' (by booking ref) or 'order' (by order ref). Returns null on success or the reason.
 */
function recordRefund(PDO $db, string $kind, string $reference, float $amount, string $method, string $reason, string $date): ?string {
    $amount = round($amount, 2);
    if ($amount <= 0) return 'Enter an amount above £0.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $cash = financeCashAccount($method);
    $reference = strtoupper(trim($reference));

    if ($kind === 'order') {
        $q = $db->prepare("SELECT id, order_ref, total, payment_status, status, from_pipeline FROM orders WHERE order_ref = ?");
        $q->execute([$reference]);
        $o = $q->fetch();
        if (!$o) return 'No order with that reference.';
        if ((int)$o['from_pipeline'] === 1) return 'That order was paid as part of a booking: record the refund against the booking instead.';
        if (($o['payment_status'] ?? '') !== 'paid') return 'That order was never paid.';
        if (in_array($o['status'], ['cancelled', 'refunded'], true)) return 'That order is already cancelled/refunded, which reverses it in full.';
        $r = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE kind='order' AND ref_id=?"); $r->execute([(int)$o['id']]);
        if ($amount > round((float)$o['total'] - (float)$r->fetchColumn(), 2) + 0.005) return 'That is more than is left to refund on this order.';
        $db->prepare("INSERT INTO refunds (kind, ref_id, reference, amount, method, reason, refund_date) VALUES ('order',?,?,?,?,?,?)")
           ->execute([(int)$o['id'], $o['order_ref'], $amount, $method, $reason, $date]);
        createJournalEntry($date, 'Refund: ' . $o['order_ref'], 'order_refund', (int)$o['id'], [
            ['account_code' => '4010', 'debit' => $amount, 'credit' => 0,       'memo' => 'Refund: ' . $reason],
            ['account_code' => $cash,  'debit' => 0,       'credit' => $amount, 'memo' => 'Refunded ' . $o['order_ref']],
        ], (string)$o['order_ref']);
        return null;
    }

    $q = $db->prepare("SELECT id, booking_ref, status FROM bookings WHERE booking_ref = ?");
    $q->execute([$reference]);
    $b = $q->fetch();
    if (!$b) return 'No booking with that reference.';
    $bid = (int)$b['id'];
    if ($b['status'] === 'completed') {
        $acct = '4000'; $max = financeNet($db, $bid, BOOKING_LEDGER_SOURCES, ['4000']) + financeNet($db, $bid, ['booking_refund'], ['4000']);
    } elseif ($b['status'] === 'incomplete') {
        $acct = '4020'; $max = financeNet($db, $bid, array_merge(BOOKING_LEDGER_SOURCES, ['booking_refund']), ['4020']);
    } else {
        $acct = '2000'; $max = bookingHeldDeposit($db, $bid) + financeNet($db, $bid, ['booking_travel'], ['2000']);
    }
    if ($max <= 0.005) return 'Nothing has been paid on that booking to refund.';
    if ($amount > $max + 0.005) return 'That is more than was paid (up to ' . formatPrice($max) . ').';
    $db->prepare("INSERT INTO refunds (kind, ref_id, reference, amount, method, reason, refund_date) VALUES ('booking',?,?,?,?,?,?)")
       ->execute([$bid, $b['booking_ref'], $amount, $method, $reason, $date]);
    createJournalEntry($date, 'Refund: ' . $b['booking_ref'], 'booking_refund', $bid, [
        ['account_code' => $acct, 'debit' => $amount, 'credit' => 0,       'memo' => 'Refund: ' . $reason],
        ['account_code' => $cash, 'debit' => 0,       'credit' => $amount, 'memo' => 'Refunded ' . $b['booking_ref']],
    ], (string)$b['booking_ref']);
    return null;
}

// ─────────────────────────────────────────────────────────
// Expenses
// ─────────────────────────────────────────────────────────
/** Receipt photo or PDF → stored path, or false. Images go through uploadImage(); PDFs are checked by content. */
function uploadReceipt(array $file): string|false {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return false;
    if ($file['size'] > 8 * 1024 * 1024) return false;
    $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 5);
    if ($head === '%PDF-') {
        $dir = __DIR__ . '/../uploads/receipts/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = generateToken(16) . '.pdf';
        return move_uploaded_file($file['tmp_name'], $dir . $name) ? '/uploads/receipts/' . $name : false;
    }
    return uploadImage($file, 'receipts');
}

/** Log an expense and post it: DR 5100 / CR the account it was paid from. Returns the new id. */
function logExpense(PDO $db, string $date, string $desc, float $amount, string $category, string $paidFrom, string $notes = '', ?string $receipt = null, ?int $recurringId = null): int {
    $db->prepare("INSERT INTO expenses (expense_date, description, amount, category, notes, paid_from, receipt_url, recurring_id) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$date, $desc, round($amount, 2), $category, $notes, $paidFrom, $receipt, $recurringId]);
    $id = (int)$db->lastInsertId();
    journalExpense($id, round($amount, 2), $date, $desc . ' (' . $category . ')', financeCashAccount($paidFrom));
    return $id;
}

/** Undo an expense logged by mistake: mirror entry, row kept and marked void. */
function voidExpense(PDO $db, int $expenseId): ?string {
    $q = $db->prepare("SELECT * FROM expenses WHERE id = ?"); $q->execute([$expenseId]);
    $e = $q->fetch();
    if (!$e) return 'Expense not found.';
    if ((int)($e['is_void'] ?? 0) === 1) return 'Already removed.';
    $lines = $db->prepare("SELECT a.code, l.debit, l.credit FROM journal_entry_lines l JOIN journal_entries j ON j.id = l.journal_entry_id
                           JOIN accounts a ON a.id = l.account_id WHERE j.source = 'expense' AND j.source_id = ?");
    $lines->execute([$expenseId]);
    $mirror = [];
    foreach ($lines->fetchAll() as $l) $mirror[] = ['account_code' => $l['code'], 'debit' => (float)$l['credit'], 'credit' => (float)$l['debit'], 'memo' => 'Removed expense'];
    if ($mirror) createJournalEntry(date('Y-m-d'), 'Expense removed: ' . $e['description'], 'expense_reversal', $expenseId, $mirror, 'EXP' . $expenseId);
    $db->prepare("UPDATE expenses SET is_void = 1 WHERE id = ?")->execute([$expenseId]);
    return null;
}

/** Post any repeating expenses that have come due this month (once per month each). */
function postRecurringExpenses(PDO $db): int {
    $posted = 0;
    $ym  = date('Y-m');
    $day = (int)date('j');
    $rows = $db->query("SELECT * FROM recurring_expenses WHERE is_active = 1 AND (last_posted_month IS NULL OR last_posted_month < '{$ym}')")->fetchAll();
    foreach ($rows as $r) {
        $due = min(max(1, (int)$r['day_of_month']), (int)date('t'));
        if ($day < $due) continue;
        $db->beginTransaction();
        try {
            $claim = $db->prepare("UPDATE recurring_expenses SET last_posted_month = ? WHERE id = ? AND (last_posted_month IS NULL OR last_posted_month < ?)");
            $claim->execute([$ym, (int)$r['id'], $ym]);
            if ($claim->rowCount() === 1) {
                logExpense($db, date('Y-m-') . sprintf('%02d', $due), $r['description'], (float)$r['amount'], $r['category'], $r['paid_from'], 'Repeating monthly expense', null, (int)$r['id']);
                $posted++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('postRecurringExpenses #' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    return $posted;
}

// ─────────────────────────────────────────────────────────
// Reports
// ─────────────────────────────────────────────────────────
/** UK tax year (6 April – 5 April) containing $date: [start, end, label]. */
function ukTaxYear(string $date): array {
    $y = (int)substr($date, 0, 4);
    $startYear = substr($date, 5) >= '04-06' ? $y : $y - 1;
    return ["{$startYear}-04-06", ($startYear + 1) . '-04-05', "Tax year {$startYear}/" . substr((string)($startYear + 1), 2)];
}

/** Resolve a period preset to [from, to, label]. */
function financePeriod(string $preset, ?string $from = null, ?string $to = null): array {
    $t = date('Y-m-d');
    $q = fn(int $offset) => (function () use ($offset) {
        $m = (int)date('n'); $y = (int)date('Y');
        $qs = intdiv($m - 1, 3) * 3 + 1 + $offset * 3;
        while ($qs < 1) { $qs += 12; $y--; }
        $start = sprintf('%04d-%02d-01', $y, $qs);
        return [$start, date('Y-m-t', strtotime($start . ' +2 months')), 'Q' . (intdiv($qs - 1, 3) + 1) . ' ' . $y];
    })();
    switch ($preset) {
        case 'last_month':    $s = date('Y-m-01', strtotime('first day of last month')); return [$s, date('Y-m-t', strtotime($s)), date('F Y', strtotime($s))];
        case 'this_quarter':  return $q(0);
        case 'last_quarter':  return $q(-1);
        case 'this_tax_year': return ukTaxYear($t);
        case 'last_tax_year': return ukTaxYear(date('Y-m-d', strtotime(ukTaxYear($t)[0] . ' -1 day')));
        case 'custom':
            $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            if ($ok($from) && $ok($to) && $from <= $to) return [$from, $to, date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to))];
            // fall through to this month
        default:              return [date('Y-m-01'), date('Y-m-t'), date('F Y')];
    }
}

/** Profit & loss from the ledger for [from, to]. */
function financeProfitAndLoss(PDO $db, string $from, string $to): array {
    $q = $db->prepare("SELECT a.code, a.name, a.type, COALESCE(SUM(l.credit - l.debit), 0) AS net
                       FROM journal_entry_lines l
                       JOIN journal_entries e ON e.id = l.journal_entry_id
                       JOIN accounts a ON a.id = l.account_id
                       WHERE e.entry_date BETWEEN ? AND ? AND a.type IN ('income','expense')
                       GROUP BY a.code, a.name, a.type ORDER BY a.code");
    $q->execute([$from, $to]);
    $income = []; $expense = []; $draws = 0.0;
    foreach ($q->fetchAll() as $r) {
        $net = round((float)$r['net'], 2);
        if ($r['type'] === 'income') { if (abs($net) > 0.005) $income[] = ['code' => $r['code'], 'name' => $r['name'], 'amount' => $net]; }
        elseif ($r['code'] === '5200') $draws = -$net;
        elseif (abs($net) > 0.005)     $expense[] = ['code' => $r['code'], 'name' => $r['name'], 'amount' => -$net];
    }
    $cat = $db->prepare("SELECT category, COUNT(*) AS n, SUM(amount) AS total FROM expenses
                         WHERE expense_date BETWEEN ? AND ? AND COALESCE(is_void,0) = 0 GROUP BY category ORDER BY total DESC");
    $cat->execute([$from, $to]);
    $ti = array_sum(array_column($income, 'amount'));
    $te = array_sum(array_column($expense, 'amount'));
    return ['income' => $income, 'expenses' => $expense, 'categories' => $cat->fetchAll(),
            'total_income' => round($ti, 2), 'total_expenses' => round($te, 2),
            'profit' => round($ti - $te, 2), 'draws' => round($draws, 2)];
}

/** What's owed to the business and what's coming in. */
function financeMoneyOwed(PDO $db): array {
    $today = date('Y-m-d');
    $weeks = [];
    $w = $db->prepare("SELECT YEARWEEK(booked_date, 1) AS wk, MIN(booked_date) AS first_day, COUNT(*) AS n,
                              SUM(remaining_balance) AS balance, SUM(CASE WHEN deposit_paid = 1 THEN deposit_amount ELSE 0 END) AS deposits
                       FROM bookings WHERE status IN ('pending','confirmed') AND booked_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 WEEK)
                       GROUP BY wk ORDER BY wk");
    $w->execute([$today, $today]);
    foreach ($w->fetchAll() as $r) $weeks[] = $r;

    $unpaidDep = $db->query("SELECT b.id, b.booking_ref, b.booked_date, b.deposit_amount, b.created_at, c.name
                             FROM bookings b JOIN customers c ON c.id = b.customer_id
                             WHERE b.status IN ('pending','confirmed') AND b.deposit_paid = 0 AND b.deposit_amount > 0
                               AND b.booked_date >= CURDATE() ORDER BY b.booked_date")->fetchAll();
    $unpaidOrders = $db->query("SELECT o.id, o.order_ref, o.total, o.created_at, o.payment_method, c.name
                                FROM orders o JOIN customers c ON c.id = o.customer_id
                                WHERE o.from_pipeline = 0 AND COALESCE(o.payment_status,'pending') <> 'paid'
                                  AND o.status NOT IN ('cancelled','refunded') ORDER BY o.created_at")->fetchAll();
    $held = $db->query("SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM journal_entry_lines l JOIN accounts a ON a.id = l.account_id WHERE a.code = '2000'")->fetchColumn();
    $credit = 0.0;
    try { $credit = (float)$db->query("SELECT COALESCE(SUM(remaining),0) FROM referral_credits WHERE status = 'earned'")->fetchColumn(); } catch (Throwable $e) {}
    return [
        'weeks'          => $weeks,
        'balance_due'    => round(array_sum(array_column($weeks, 'balance')), 2),
        'deposits_held'  => round((float)$held, 2),
        'unpaid_deposits'=> $unpaidDep,
        'unpaid_orders'  => $unpaidOrders,
        'referral_credit'=> round($credit, 2),
    ];
}
