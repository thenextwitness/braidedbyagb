<?php
// ============================================================
// BraidedbyAGB — "AGB bring your bestie" referral programme
// FILE: /includes/referrals.php  (loaded at the foot of helpers.php)
//
// How it works:
//   · Every client has a share link: /booking?ref=CODE (customers.referral_code).
//   · A NEW client (no paid/confirmed/completed booking before) who books through
//     it gets £X off. It comes off the BALANCE DUE ON THE DAY of their first
//     appointment(s) — the online deposit/charge never changes, so Stripe amounts
//     and the server-side price check stay exactly as they were. If they pay in 3
//     (Klarna/Clearpay: nothing due on the day) the £X becomes credit instead.
//   · The referrer earns £Y credit once the friend's appointment is COMPLETED.
//   · Credit comes off the balance on the day of the client's next booking,
//     automatically.
//
// Self-healing: syncReferrals() runs on every booking status change and on
// every scheduler run. It earns, voids and returns rewards from the booking
// statuses alone, so a cancelled booking can never keep a reward or use
// credit, whatever path cancelled it.
//
// Tables: referrals (one per referred first booking), referral_credits
// (money a client can spend), referral_credit_uses (credit spent on a booking).
// ============================================================

const REFERRAL_DEAD_STATUSES = ['cancelled', 'rejected', 'late_cancelled', 'no_show'];

function referralEnabled(): bool {
    return getSetting('referral_enabled', '1') === '1';
}

/** ['friend' => £ off for the new client, 'referrer' => £ credit for the referrer] */
function referralAmounts(): array {
    $f = round(max(0, min(100, (float)getSetting('referral_friend_amount', '10'))), 2);
    $r = round(max(0, min(100, (float)getSetting('referral_referrer_amount', '10'))), 2);
    return ['friend' => $f, 'referrer' => $r];
}

/** The client's share code, created the first time it's needed. */
function referralCodeFor(PDO $db, int $customerId): string {
    $q = $db->prepare("SELECT name, referral_code FROM customers WHERE id = ?");
    $q->execute([$customerId]);
    $c = $q->fetch();
    if (!$c) return '';
    if (!empty($c['referral_code'])) return $c['referral_code'];
    $stem = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', trim((string)$c['name']))[0] ?? ''));
    $stem = substr($stem !== '' ? $stem : 'AGB', 0, 6);
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0/O/1/I
    $set = $db->prepare("UPDATE customers SET referral_code = ? WHERE id = ? AND referral_code IS NULL");
    for ($try = 0; $try < 8; $try++) {
        $code = $stem;
        for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        try {
            $set->execute([$code, $customerId]);
            if ($set->rowCount() === 1) return $code;
            // Someone set it meanwhile: use theirs.
            $q->execute([$customerId]);
            $now = $q->fetch();
            if (!empty($now['referral_code'])) return $now['referral_code'];
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') throw $e;   // duplicate: try another
        }
    }
    return '';
}

function referralLink(PDO $db, int $customerId): string {
    $code = referralCodeFor($db, $customerId);
    return $code === '' ? '' : rtrim(SITE_URL, '/') . '/booking?ref=' . rawurlencode($code);
}

/** Spendable credit (earned, not yet used) for a client. */
function customerCreditBalance(PDO $db, int $customerId): float {
    $q = $db->prepare("SELECT COALESCE(SUM(remaining), 0) FROM referral_credits WHERE customer_id = ? AND status = 'earned'");
    $q->execute([$customerId]);
    return round((float)$q->fetchColumn(), 2);
}

/** Has this client ever had a real booking (paid, confirmed or done), ignoring $exclude? */
function customerHasRealBooking(PDO $db, int $customerId, array $exclude = []): bool {
    $sql = "SELECT COUNT(*) FROM bookings WHERE customer_id = ?
            AND (status IN ('confirmed','completed','incomplete') OR deposit_paid = 1)";
    $args = [$customerId];
    if ($exclude) {
        $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        $args = array_merge($args, array_map('intval', $exclude));
    }
    $q = $db->prepare($sql);
    $q->execute($args);
    return (int)$q->fetchColumn() > 0;
}

/** Take up to $amount off the balance of the given bookings, in order. Returns what was taken. */
function referralTakeOffBalance(PDO $db, array $bookingIds, float $amount): float {
    $taken = 0.0;
    $get = $db->prepare("SELECT total_price, deposit_amount, remaining_balance FROM bookings WHERE id = ?");
    $upd = $db->prepare("UPDATE bookings SET total_price = total_price - ?, remaining_balance = remaining_balance - ?,
                                referral_discount = referral_discount + ? WHERE id = ?");
    foreach ($bookingIds as $bid) {
        $left = round($amount - $taken, 2);
        if ($left <= 0) break;
        $get->execute([(int)$bid]);
        $b = $get->fetch();
        if (!$b) continue;
        $room = round((float)$b['remaining_balance'], 2);   // never below what's already paid
        $cut  = round(min($left, max(0, $room)), 2);
        if ($cut <= 0) continue;
        $upd->execute([$cut, $cut, $cut, (int)$bid]);
        $taken = round($taken + $cut, 2);
    }
    return $taken;
}

/**
 * Called by cart-intent INSIDE its transaction, after the bookings are written
 * and before commit. Applies the bestie discount (new clients with a valid
 * code) and any credit the client already has. Returns a summary for logging.
 */
function applyReferralAtBooking(PDO $db, int $customerId, array $bookingIds, string $code, bool $payFull): array {
    $out = ['friend_discount' => 0.0, 'credit_used' => 0.0, 'referral_id' => null];
    if (!referralEnabled() || !$bookingIds) return $out;
    $amt = referralAmounts();

    // A card checkout that was never paid (declined, closed, retried) must not
    // keep this client's referral or credit tied up: release them before the
    // new attempt. Bank-transfer bookings are left alone (they're awaiting payment).
    $ids = implode(',', array_map('intval', $bookingIds));
    $stale = $db->prepare("SELECT id FROM bookings WHERE customer_id = ? AND id NOT IN ($ids)
                           AND status = 'pending' AND deposit_paid = 0 AND payment_method = 'stripe'");
    $stale->execute([$customerId]);
    foreach ($stale->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $sid = (int)$sid;
        foreach ($db->query("SELECT id FROM referrals WHERE booking_id = {$sid} AND status = 'pending'")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $db->prepare("UPDATE referrals SET status = 'void' WHERE id = ?")->execute([(int)$rid]);
            $db->prepare("UPDATE referral_credits SET status = 'void' WHERE referral_id = ? AND status = 'pending'")->execute([(int)$rid]);
        }
        foreach ($db->query("SELECT id, credit_id, amount FROM referral_credit_uses WHERE booking_id = {$sid} AND status = 'active'")->fetchAll() as $u) {
            $db->prepare("UPDATE referral_credit_uses SET status = 'reversed' WHERE id = ?")->execute([(int)$u['id']]);
            $db->prepare("UPDATE referral_credits SET remaining = LEAST(amount, remaining + ?) WHERE id = ?")->execute([(float)$u['amount'], (int)$u['credit_id']]);
        }
    }

    // ── 1. New client arriving through a share link ──
    $code = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    if ($code !== '') {
        $r = $db->prepare("SELECT id, email, phone FROM customers WHERE referral_code = ? LIMIT 1");
        $r->execute([$code]);
        $referrer = $r->fetch();
        $me = $db->prepare("SELECT email, phone FROM customers WHERE id = ?");
        $me->execute([$customerId]);
        $self = $me->fetch() ?: [];
        $digits = fn($p) => substr(preg_replace('/\D+/', '', (string)$p), -10);
        $already = $db->prepare("SELECT COUNT(*) FROM referrals WHERE referred_id = ? AND status <> 'void'");
        $already->execute([$customerId]);
        $eligible = $referrer
            && (int)$referrer['id'] !== $customerId
            && strcasecmp((string)$referrer['email'], (string)($self['email'] ?? '')) !== 0
            && ($digits($referrer['phone']) === '' || $digits($referrer['phone']) !== $digits($self['phone'] ?? ''))
            && !customerHasRealBooking($db, $customerId, $bookingIds)
            && (int)$already->fetchColumn() === 0;
        if ($eligible) {
            $db->prepare("INSERT INTO referrals (referrer_id, referred_id, booking_id, code, status) VALUES (?,?,?,?, 'pending')")
               ->execute([(int)$referrer['id'], $customerId, (int)$bookingIds[0], $code]);
            $refId = (int)$db->lastInsertId();
            $out['referral_id'] = $refId;
            // Referrer's reward: earned when this booking is completed (syncReferrals).
            if ($amt['referrer'] > 0) {
                $db->prepare("INSERT INTO referral_credits (customer_id, referral_id, role, amount, remaining, status) VALUES (?,?, 'referrer', ?, 0, 'pending')")
                   ->execute([(int)$referrer['id'], $refId, $amt['referrer']]);
            }
            if ($amt['friend'] > 0) {
                if ($payFull) {
                    // Nothing is due on the day with pay-in-3, so it becomes credit.
                    $db->prepare("INSERT INTO referral_credits (customer_id, referral_id, role, amount, remaining, status) VALUES (?,?, 'friend', ?, 0, 'pending')")
                       ->execute([$customerId, $refId, $amt['friend']]);
                } else {
                    $out['friend_discount'] = referralTakeOffBalance($db, $bookingIds, $amt['friend']);
                }
            }
        }
    }

    // ── 2. Credit the client already earned ──
    if (!$payFull) {
        $credits = $db->prepare("SELECT id, remaining FROM referral_credits WHERE customer_id = ? AND status = 'earned' AND remaining > 0 ORDER BY earned_at, id");
        $credits->execute([$customerId]);
        $use = $db->prepare("INSERT INTO referral_credit_uses (credit_id, booking_id, amount, status) VALUES (?,?,?, 'active')");
        $dec = $db->prepare("UPDATE referral_credits SET remaining = remaining - ? WHERE id = ?");
        foreach ($credits->fetchAll() as $cr) {
            $want  = round((float)$cr['remaining'], 2);
            $taken = 0.0;
            foreach ($bookingIds as $bid) {
                if ($taken >= $want) break;
                $got = referralTakeOffBalance($db, [(int)$bid], round($want - $taken, 2));
                if ($got > 0) { $use->execute([(int)$cr['id'], (int)$bid, $got]); $taken = round($taken + $got, 2); }
            }
            if ($taken > 0) { $dec->execute([$taken, (int)$cr['id']]); $out['credit_used'] = round($out['credit_used'] + $taken, 2); }
            if ($taken < $want) break;   // no balance left to take credit off
        }
    }
    return $out;
}

/**
 * Bring rewards in line with booking statuses. Idempotent; safe to run any time.
 * $bookingId limits the work to one booking (status-change hook); null = full sweep (cron).
 */
function syncReferrals(PDO $db, ?int $bookingId = null): void {
    $dead = "'" . implode("','", REFERRAL_DEAD_STATUSES) . "'";
    $only = $bookingId ? ' AND b.id = ' . (int)$bookingId : '';

    // Friend's first appointment completed → referral complete, rewards earned.
    $done = $db->query("SELECT r.id, r.referrer_id, r.referred_id FROM referrals r JOIN bookings b ON b.id = r.booking_id
                        WHERE r.status = 'pending' AND b.status = 'completed'{$only}")->fetchAll();
    foreach ($done as $r) {
        $mark = $db->prepare("UPDATE referrals SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'pending'");
        $mark->execute([(int)$r['id']]);
        if ($mark->rowCount() !== 1) continue;   // another run got there first: no second email
        $db->prepare("UPDATE referral_credits SET status = 'earned', remaining = amount, earned_at = NOW() WHERE referral_id = ? AND status = 'pending'")->execute([(int)$r['id']]);
        try {
            if (!function_exists('emailReferralEarned')) require_once __DIR__ . '/mailer.php';
            $q = $db->prepare("SELECT c.name, c.email, rc.amount FROM referral_credits rc JOIN customers c ON c.id = rc.customer_id
                               WHERE rc.referral_id = ? AND rc.role = 'referrer'");
            $q->execute([(int)$r['id']]);
            $rc = $q->fetch();
            $f = $db->prepare("SELECT name FROM customers WHERE id = ?"); $f->execute([(int)$r['referred_id']]);
            $friend = trim(explode(' ', (string)$f->fetchColumn())[0]);
            if ($rc && !empty($rc['email'])) emailReferralEarned(['name' => $rc['name'], 'email' => $rc['email']], (float)$rc['amount'], $friend, (int)$r['referrer_id']);
        } catch (Throwable $e) { error_log('referral earned email: ' . $e->getMessage()); }
    }

    // A completed first appointment later undone (moved off "completed"): take
    // back rewards not yet spent — void if it fell through, else wait again.
    $undone = $db->query("SELECT r.id, b.status FROM referrals r JOIN bookings b ON b.id = r.booking_id
                          WHERE r.status = 'completed' AND b.status <> 'completed'{$only}")->fetchAll();
    foreach ($undone as $r) {
        $isDead = in_array($r['status'], REFERRAL_DEAD_STATUSES, true);
        $db->prepare("UPDATE referrals SET status = ?, completed_at = NULL WHERE id = ?")->execute([$isDead ? 'void' : 'pending', (int)$r['id']]);
        $db->prepare("UPDATE referral_credits SET status = ?, remaining = 0, earned_at = NULL
                      WHERE referral_id = ? AND status = 'earned' AND remaining >= amount")
           ->execute([$isDead ? 'void' : 'pending', (int)$r['id']]);
    }

    // Friend's first appointment fell through → referral and its rewards void.
    $void = $db->query("SELECT r.id FROM referrals r JOIN bookings b ON b.id = r.booking_id
                        WHERE r.status = 'pending' AND b.status IN ({$dead}){$only}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($void as $rid) {
        $db->prepare("UPDATE referrals SET status = 'void' WHERE id = ?")->execute([(int)$rid]);
        $db->prepare("UPDATE referral_credits SET status = 'void' WHERE referral_id = ? AND status = 'pending'")->execute([(int)$rid]);
    }

    // Credit spent on a booking that fell through → give it back.
    $back = $db->query("SELECT u.id, u.credit_id, u.amount FROM referral_credit_uses u JOIN bookings b ON b.id = u.booking_id
                        WHERE u.status = 'active' AND b.status IN ({$dead}){$only}")->fetchAll();
    foreach ($back as $u) {
        $db->prepare("UPDATE referral_credit_uses SET status = 'reversed' WHERE id = ? AND status = 'active'")->execute([(int)$u['id']]);
        $db->prepare("UPDATE referral_credits SET remaining = LEAST(amount, remaining + ?) WHERE id = ?")->execute([(float)$u['amount'], (int)$u['credit_id']]);
    }
}

/** "Bring your bestie" block for client emails (win-back, aftercare). '' when switched off. */
function referralEmailBlock(int $customerId): string {
    if (!referralEnabled()) return '';
    try {
        $db   = getDB();
        $link = referralLink($db, $customerId);
        if ($link === '') return '';
        $a = referralAmounts();
        $url = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $f = '£' . rtrim(rtrim(number_format($a['friend'], 2), '0'), '.');
        $r = '£' . rtrim(rtrim(number_format($a['referrer'], 2), '0'), '.');
        return '<div style="background:#FBF3FA;border-radius:10px;padding:16px 18px;margin:22px 0">'
             . '<p style="margin:0 0 6px;font-weight:700;color:#7A0050">💕 AGB bring your bestie</p>'
             . "<p style=\"margin:0 0 10px\">Share your link: your bestie gets <strong>{$f} off</strong> their first appointment, and you get <strong>{$r} off</strong> your next one once they've been in.</p>"
             . "<p style=\"margin:0;word-break:break-all\"><a href=\"{$url}\">{$url}</a></p></div>";
    } catch (Throwable $e) {
        error_log('referralEmailBlock: ' . $e->getMessage());
        return '';
    }
}
