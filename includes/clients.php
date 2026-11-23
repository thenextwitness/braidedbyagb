<?php
// ============================================================
// BraidedbyAGB — Deleting a client (owner-approved 23 Nov 2026,
// docs/mockups/2026-11-client-delete.png)
// FILE: /includes/clients.php  (loaded at the foot of helpers.php)
//
// The old Delete left reviews, review requests and shop-order payments behind,
// so the database refused it and the admin page went blank. Now:
//   · a client who never paid anything is deleted completely;
//   · a client with money history has their PERSONAL DETAILS removed, while
//     bookings / payments / ledger stay (accounts unchanged; HMRC records).
// Both refuse while the client has an upcoming (pending/confirmed) booking.
// ============================================================

/** Does a table / column exist? (cached; this file must work on any schema version) */
function clientsTableExists(PDO $db, string $table): bool {
    static $c = [];
    if (!array_key_exists($table, $c)) {
        $q = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $q->execute([$table]);
        $c[$table] = (int)$q->fetchColumn() > 0;
    }
    return $c[$table];
}
function clientsColumnExists(PDO $db, string $table, string $col): bool {
    static $c = [];
    $k = $table . '.' . $col;
    if (!array_key_exists($k, $c)) {
        $q = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $q->execute([$table, $col]);
        $c[$k] = (int)$q->fetchColumn() > 0;
    }
    return $c[$k];
}

/**
 * What a client has paid / done that must stay in the accounts.
 * Returns ['has_money' => bool, 'paid' => float, 'bookings' => int, 'upcoming' => int].
 */
function clientMoneyHistory(PDO $db, int $cid): array {
    $one = function (string $sql, array $p = []) use ($db) { $q = $db->prepare($sql); $q->execute($p); return $q->fetchColumn(); };
    $paidBk   = (float)$one("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.customer_id = ? AND p.status = 'succeeded'", [$cid]);
    $paidOrd  = (float)$one("SELECT COALESCE(SUM(total),0) FROM orders WHERE customer_id = ? AND payment_status = 'paid'", [$cid]);
    $done     = (int)$one("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status = 'completed'", [$cid]);
    $nBk      = (int)$one("SELECT COUNT(*) FROM bookings WHERE customer_id = ?", [$cid]);
    $upcoming = (int)$one("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status IN ('pending','confirmed')", [$cid]);
    $courses  = clientsTableExists($db, 'course_enrolments') ? (int)$one("SELECT COUNT(*) FROM course_enrolments WHERE customer_id = ?", [$cid]) : 0;
    return [
        'has_money' => ($paidBk + $paidOrd) > 0.004 || $done > 0 || $courses > 0,
        'paid'      => round($paidBk + $paidOrd, 2),
        'bookings'  => $nBk,
        'upcoming'  => $upcoming,
    ];
}

/** Delete a client with NO money history and everything linked to them. Returns null on success, else the reason. */
function deleteClientPermanently(PDO $db, int $cid): ?string {
    $h = clientMoneyHistory($db, $cid);
    if ($h['upcoming'] > 0) return 'This client has an upcoming booking. Cancel it first.';
    if ($h['has_money'])     return 'This client has paid you, so only their personal details can be removed (to keep your accounts right).';

    $email = (string)($db->query("SELECT email FROM customers WHERE id = " . (int)$cid)->fetchColumn() ?: '');
    $del = function (string $sql, array $args = []) use ($db) { $db->prepare($sql)->execute($args); };
    $opt = fn(string $t) => clientsTableExists($db, $t);

    $db->beginTransaction();
    try {
        $q = $db->prepare("SELECT id FROM bookings WHERE customer_id = ?");
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $bid) {
            $bid = (int)$bid;
            $del("DELETE FROM journal_entries WHERE source IN ('booking_payment','booking_deposit','booking_forfeit','booking_reversal','booking_travel','booking_refund') AND source_id = ?", [$bid]);
            $del("DELETE FROM booking_addons WHERE booking_id = ?", [$bid]);
            $del("DELETE FROM payments WHERE booking_id = ?", [$bid]);
            if ($opt('booking_assignments'))  $del("DELETE FROM booking_assignments WHERE booking_id = ?", [$bid]);
            if ($opt('referral_credit_uses')) $del("DELETE FROM referral_credit_uses WHERE booking_id = ?", [$bid]);
            if ($opt('refunds'))              $del("DELETE FROM refunds WHERE kind = 'booking' AND ref_id = ?", [$bid]);
            $del("DELETE FROM review_requests WHERE booking_id = ?", [$bid]);
            $del("UPDATE reviews SET booking_id = NULL WHERE booking_id = ?", [$bid]);
            $del("UPDATE orders SET booking_id = NULL WHERE booking_id = ? AND customer_id <> ?", [$bid, $cid]);
        }
        $q = $db->prepare("SELECT id FROM orders WHERE customer_id = ?");
        $q->execute([$cid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $oid = (int)$oid;
            $del("DELETE FROM journal_entries WHERE source IN ('order_payment','order_reversal','order_refund') AND source_id = ?", [$oid]);
            $del("DELETE FROM order_items WHERE order_id = ?", [$oid]);
            $del("DELETE FROM payments WHERE order_id = ?", [$oid]);
            if ($opt('refunds')) $del("DELETE FROM refunds WHERE kind = 'order' AND ref_id = ?", [$oid]);
            $del("DELETE FROM review_requests WHERE order_id = ?", [$oid]);
            $del("UPDATE reviews SET order_id = NULL WHERE order_id = ?", [$oid]);
        }
        $del("DELETE FROM orders WHERE customer_id = ?", [$cid]);
        $del("DELETE FROM bookings WHERE customer_id = ?", [$cid]);
        if ($opt('referrals')) {
            $r = $db->prepare("SELECT id FROM referrals WHERE referrer_id = ? OR referred_id = ?");
            $r->execute([$cid, $cid]);
            foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                if ($opt('referral_credits')) $del("DELETE FROM referral_credits WHERE referral_id = ?", [(int)$rid]);
            }
            $del("DELETE FROM referrals WHERE referrer_id = ? OR referred_id = ?", [$cid, $cid]);
        }
        if ($opt('referral_credits')) $del("DELETE FROM referral_credits WHERE customer_id = ?", [$cid]);
        foreach (['loyalty_transactions', 'customer_notes', 'review_requests', 'reviews', 'message_batch_recipients', 'trainee_profiles', 'discount_codes'] as $t) {
            if ($opt($t) && clientsColumnExists($db, $t, 'customer_id')) $del("DELETE FROM $t WHERE customer_id = ?", [$cid]);
        }
        if ($opt('portal_auth_codes'))      $del("DELETE FROM portal_auth_codes WHERE user_type = 'client' AND (user_id = ? OR email = ?)", [$cid, $email]);
        if ($opt('portal_remember_tokens')) $del("DELETE FROM portal_remember_tokens WHERE user_type = 'client' AND user_id = ?", [$cid]);
        $del("DELETE FROM customers WHERE id = ?", [$cid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('deleteClientPermanently #' . $cid . ': ' . $e->getMessage());
        return 'The client could not be deleted (' . $e->getMessage() . '). Nothing was changed.';
    }
    return null;
}

/**
 * Remove a paying client's personal details; bookings, payments and the ledger
 * stay (shown as "Deleted client"). Their website reviews are removed.
 * Returns null on success, else the reason.
 */
function anonymiseClient(PDO $db, int $cid): ?string {
    $h = clientMoneyHistory($db, $cid);
    if ($h['upcoming'] > 0) return 'This client has an upcoming booking. Cancel it first.';
    $email = (string)($db->query("SELECT email FROM customers WHERE id = " . (int)$cid)->fetchColumn() ?: '');
    if ($email === '' && !$db->query("SELECT COUNT(*) FROM customers WHERE id = " . (int)$cid)->fetchColumn()) return 'That client no longer exists.';
    $del = function (string $sql, array $args = []) use ($db) { $db->prepare($sql)->execute($args); };
    $opt = fn(string $t) => clientsTableExists($db, $t);

    // Personal fields on customers: set only the columns this database has.
    $set  = ['name = ?', 'email = ?', 'phone = NULL', 'notes = NULL'];
    $args = ['Deleted client', 'deleted-' . $cid . '@deleted.invalid'];
    foreach (['hair_notes' => 'NULL', 'tags' => 'NULL', 'block_reason' => 'NULL', 'password_hash' => 'NULL',
              'unsubscribe_token' => 'NULL', 'referral_code' => 'NULL', 'email_optin' => '0', 'email_verified' => '0',
              'last_login_at' => 'NULL'] as $col => $val) {
        if (clientsColumnExists($db, 'customers', $col)) $set[] = "$col = $val";
    }
    $args[] = $cid;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE customers SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        $del("UPDATE bookings SET guest_name = NULL, client_notes = NULL, service_address = NULL WHERE customer_id = ?", [$cid]);
        if (clientsColumnExists($db, 'orders', 'delivery_address')) $del("UPDATE orders SET delivery_address = NULL WHERE customer_id = ?", [$cid]);
        foreach (['customer_notes', 'review_requests', 'reviews'] as $t) {
            if ($opt($t)) $del("DELETE FROM $t WHERE customer_id = ?", [$cid]);
        }
        if ($opt('portal_auth_codes'))      $del("DELETE FROM portal_auth_codes WHERE user_type = 'client' AND (user_id = ? OR email = ?)", [$cid, $email]);
        if ($opt('portal_remember_tokens')) $del("DELETE FROM portal_remember_tokens WHERE user_type = 'client' AND user_id = ?", [$cid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('anonymiseClient #' . $cid . ': ' . $e->getMessage());
        return 'The details could not be removed (' . $e->getMessage() . '). Nothing was changed.';
    }
    return null;
}
