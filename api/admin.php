<?php
// ============================================================
// BraidedbyAGB — Protected Admin REST API
// FILE: /api/admin.php
// Used by the Android admin app (Kotlin + Retrofit2)
// Auth: Authorization: Bearer <JWT>
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Token helpers (DB-backed, no external library) ────────
define('TOKEN_TTL', 30 * 86400); // 30 days

function issueToken(int $adminId): string {
    $db    = getDB();
    $token = bin2hex(random_bytes(32)); // 64-char hex
    $exp   = date('Y-m-d H:i:s', time() + TOKEN_TTL);
    // Ensure admin_tokens table exists (safe no-op if already created)
    $db->exec("CREATE TABLE IF NOT EXISTS admin_tokens (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        admin_id   INT NOT NULL,
        token      VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Clean up expired tokens for this admin
    $db->prepare("DELETE FROM admin_tokens WHERE admin_id=? AND expires_at < NOW()")->execute([$adminId]);
    $db->prepare("INSERT INTO admin_tokens (admin_id, token, expires_at) VALUES (?,?,?)")->execute([$adminId, $token, $exp]);
    return $token;
}

function requireAuth(): int {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorised']);
        exit;
    }
    $token = trim($m[1]);
    $db    = getDB();
    $stmt  = $db->prepare("SELECT admin_id FROM admin_tokens WHERE token=? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
    return (int)$row['admin_id'];
}

// ── Global error handler ──────────────────────────────────
set_exception_handler(function(Throwable $e) {
    error_log('Admin API fatal: ' . $e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
});

$endpoint = sanitize($_GET['endpoint'] ?? '');
$id       = (int)($_GET['id']       ?? 0);
$action   = sanitize($_GET['action'] ?? '');
$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($endpoint) {

    // ── AUTH ─────────────────────────────────────────────────
    // POST /api/admin/auth  { "email": "...", "password": "..." }
    case 'auth':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
        $email    = sanitize($body['email']    ?? '');
        $password = $body['password'] ?? '';
        if (!$email || !$password) { http_response_code(400); echo json_encode(['error' => 'Missing credentials']); exit; }
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, email, password_hash, login_attempts, locked_until FROM admin_users WHERE email=? LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if (!$admin) { http_response_code(401); echo json_encode(['error' => 'Invalid credentials']); exit; }
        // Lockout check
        if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
            http_response_code(429); echo json_encode(['error' => 'Account locked. Try again later.']); exit;
        }
        if (!password_verify($password, $admin['password_hash'])) {
            $attempts = (int)$admin['login_attempts'] + 1;
            $lock     = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
            $db->prepare("UPDATE admin_users SET login_attempts=?, locked_until=? WHERE id=?")->execute([$attempts, $lock, $admin['id']]);
            http_response_code(401); echo json_encode(['error' => 'Invalid credentials']); exit;
        }
        $db->prepare("UPDATE admin_users SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?")->execute([$admin['id']]);
        echo json_encode(['token' => issueToken((int)$admin['id']), 'expires_in' => TOKEN_TTL]);
        exit;

    // ── DASHBOARD ────────────────────────────────────────────
    // GET /api/admin/dashboard
    case 'dashboard':
        requireAuth();
        $db = getDB();
        $pending   = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
        $today     = date('Y-m-d');
        $todayBks  = $db->prepare(
            "SELECT b.id, b.booking_ref, b.booked_date, b.booked_time, b.status,
                    b.total_price, b.deposit_amount, b.deposit_paid,
                    b.payment_method, b.receipt_url,
                    c.name  AS c_name,  c.email AS c_email, c.phone AS c_phone,
                    s.name  AS s_name,  sv.variant_name
             FROM bookings b
             JOIN customers c       ON c.id  = b.customer_id
             JOIN services  s       ON s.id  = b.service_id
             LEFT JOIN service_variants sv ON sv.id = b.variant_id
             WHERE b.booked_date = ? AND b.status != 'cancelled'
             ORDER BY b.booked_time"
        );
        $todayBks->execute([$today]);
        $todayBookings = $todayBks->fetchAll();
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = date('Y-m-d', strtotime('sunday this week'));
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE booked_date BETWEEN ? AND ? AND status='completed'");
        $stmt->execute([$weekStart, $weekEnd]);
        $weekRev = (float)$stmt->fetchColumn();
        $upcoming = $db->prepare("SELECT COUNT(*) FROM bookings WHERE booked_date > ? AND status='confirmed'");
        $upcoming->execute([$today]);
        echo json_encode([
            'pending_count'    => $pending,
            'today_bookings'   => $todayBookings,
            'week_revenue'     => $weekRev,
            'upcoming_count'   => (int)$upcoming->fetchColumn(),
        ]);
        exit;

    // ── BOOKINGS ─────────────────────────────────────────────
    // GET  /api/admin/bookings[?status=&q=&page=]
    // GET  /api/admin/bookings/{id}
    // POST /api/admin/bookings/{id}/status  { "status": "confirmed" }
    // POST /api/admin/bookings/{id}/deposit
    case 'bookings':
        requireAuth();
        $db = getDB();

        if ($method === 'GET' && !$id) {
            // List
            $status = sanitize($_GET['status'] ?? '');
            $q      = sanitize($_GET['q']      ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $per    = 20;
            $offset = ($page - 1) * $per;
            $where  = ['1=1']; $params = [];
            if ($status && in_array($status, ['pending','confirmed','completed','cancelled','no_show','late_cancelled'])) {
                $where[] = 'b.status=?'; $params[] = $status;
            }
            if ($q) {
                $where[] = '(c.name LIKE ? OR c.email LIKE ? OR b.booking_ref LIKE ?)';
                $like = '%' . $q . '%'; $params = array_merge($params, [$like, $like, $like]);
            }
            $wc    = implode(' AND ', $where);
            $cstmt = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id=b.customer_id WHERE $wc");
            $cstmt->execute($params);
            $total = (int)$cstmt->fetchColumn();
            $stmt  = $db->prepare("SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
                                          s.name as s_name, sv.variant_name
                                   FROM bookings b
                                   JOIN customers c ON c.id=b.customer_id
                                   JOIN services  s ON s.id=b.service_id
                                   LEFT JOIN service_variants sv ON sv.id=b.variant_id
                                   WHERE $wc ORDER BY b.booked_date DESC, b.booked_time DESC
                                   LIMIT $per OFFSET $offset");
            $stmt->execute($params);
            echo json_encode(['bookings' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $per]);
            exit;
        }

        if ($method === 'GET' && $id) {
            // Detail — include custom_style_name so mobile app can display it
            $stmt = $db->prepare("SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
                                         s.name as s_name, s.duration_mins as service_duration_mins, sv.variant_name,
                                         COALESCE(b.custom_style_name, '') as custom_style_name,
                                         COALESCE(b.custom_style_desc,  '') as custom_style_desc
                                  FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN services s ON s.id=b.service_id
                                  LEFT JOIN service_variants sv ON sv.id=b.variant_id WHERE b.id=?");
            $stmt->execute([$id]);
            $bk = $stmt->fetch();
            if (!$bk) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            $astmt = $db->prepare("SELECT ba.*, sa.name FROM booking_addons ba JOIN service_addons sa ON sa.id=ba.addon_id WHERE ba.booking_id=?");
            $astmt->execute([$id]); $bk['addons'] = $astmt->fetchAll();
            $pstmt = $db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY created_at DESC");
            $pstmt->execute([$id]); $bk['payments'] = $pstmt->fetchAll();
            echo json_encode($bk);
            exit;
        }

        if ($method === 'POST' && $id && $action === 'status') {
            $newStatus = sanitize($body['status'] ?? '');
            if (!in_array($newStatus, ['pending','confirmed','completed','cancelled','no_show','late_cancelled'])) {
                http_response_code(400); echo json_encode(['error' => 'Invalid status']); exit;
            }
            $db->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$newStatus, $id]);

            // ── COMPLETED: award loyalty points + journal entry ───────
            if ($newStatus === 'completed') {
                try { awardLoyaltyPoints($id); } catch (Throwable $e) { error_log('loyalty award: '.$e->getMessage()); }
                try {
                    $bkJ = $db->prepare("SELECT total_price, deposit_amount, deposit_paid, booking_ref FROM bookings WHERE id=?");
                    $bkJ->execute([$id]); $bkData = $bkJ->fetch();
                    if ($bkData) {
                        $total   = (float)$bkData['total_price'];
                        $deposit = (float)$bkData['deposit_amount'];
                        $balance = round($total - $deposit, 2);
                        $today   = date('Y-m-d');
                        $ref     = $bkData['booking_ref'];
                        $lines   = [];
                        if ((int)$bkData['deposit_paid'] && $deposit > 0) {
                            $lines[] = ['account_code' => '2000', 'debit' => $deposit, 'credit' => 0,     'memo' => 'Deposit released'];
                        }
                        if ($balance > 0) {
                            $lines[] = ['account_code' => '1010', 'debit' => $balance, 'credit' => 0,     'memo' => 'Balance collected (cash)'];
                        } elseif ($total > 0 && !(int)$bkData['deposit_paid']) {
                            $lines[] = ['account_code' => '1010', 'debit' => $total,   'credit' => 0,     'memo' => 'Full payment (cash)'];
                        }
                        $lines[] = ['account_code' => '4000', 'debit' => 0, 'credit' => $total, 'memo' => 'Service revenue: '.$ref];
                        if (!empty($lines)) createJournalEntry($today, 'Booking completed: '.$ref, 'booking_payment', $id, $lines, $ref);
                    }
                } catch (Throwable $e) { error_log('completion journal: '.$e->getMessage()); }
            }

            // ── CONFIRMED: send approval email ────────────────────────
            if ($newStatus === 'confirmed') {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $bk = $db->query("SELECT b.*, s.name as s_name, c.name as c_name, c.email as c_email FROM bookings b JOIN services s ON s.id=b.service_id JOIN customers c ON c.id=b.customer_id WHERE b.id=$id")->fetch();
                    if ($bk) emailBookingApproved($bk, ['name' => $bk['c_name'], 'email' => $bk['c_email']], ['name' => $bk['s_name']]);
                } catch (Throwable $e) { error_log('confirm email: ' . $e->getMessage()); }
            }

            // ── CANCELLED / NO-SHOW: auto-archive + notify client ────
            if (in_array($newStatus, ['cancelled', 'no_show', 'late_cancelled'])) {
                try { $db->prepare("UPDATE bookings SET is_archived=1 WHERE id=?")->execute([$id]); } catch (Throwable $e) {}
                // Notify the client that their booking was cancelled (only for explicit cancel — not no_show)
                if ($newStatus === 'cancelled' || $newStatus === 'late_cancelled') {
                    try {
                        require_once __DIR__ . '/../includes/mailer.php';
                        $bk = $db->query("SELECT b.*, s.name as s_name, c.name as c_name, c.email as c_email FROM bookings b JOIN services s ON s.id=b.service_id JOIN customers c ON c.id=b.customer_id WHERE b.id=$id")->fetch();
                        if ($bk && !empty($bk['c_email'])) {
                            $reason = $newStatus === 'late_cancelled'
                                ? 'Cancelled within 48 hours of the appointment — deposit forfeited per our cancellation policy.'
                                : '';
                            emailBookingRejected(
                                $bk,
                                ['name' => $bk['c_name'], 'email' => $bk['c_email']],
                                ['name' => $bk['s_name']],
                                $reason
                            );
                        }
                    } catch (Throwable $e) { error_log('Cancel email: ' . $e->getMessage()); }
                }
            }

            echo json_encode(['success' => true]);
            exit;
        }

        if ($method === 'POST' && $id && $action === 'reschedule') {
            $newDate    = sanitize($body['date'] ?? '');
            $newTime    = sanitize($body['time'] ?? '');
            $newDurMins = isset($body['duration_mins']) ? max(1, (int)$body['duration_mins']) : null;
            if (!$newDate || !$newTime || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || !preg_match('/^\d{2}:\d{2}/', $newTime)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid date or time']); exit;
            }
            $newTime = substr($newTime, 0, 5) . ':00'; // normalise to HH:MM:SS
            // Duration-aware clash check (exclude this booking itself)
            require_once __DIR__ . '/../includes/helpers.php';
            $newStart = strtotime($newDate . ' ' . $newTime);
            // Get duration for THIS booking after potential override.
            // Defensive: if duration_mins column not yet migrated, fall back without it.
            try {
                $thisBk = $db->prepare("SELECT COALESCE(NULLIF(?,0),NULLIF(b.duration_mins,0),NULLIF(sv.duration_mins,0),NULLIF(s.duration_mins,0),60) AS dur
                    FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id
                    WHERE b.id=?");
                $thisBk->execute([$newDurMins, $id]);
            } catch (Throwable $e) {
                $thisBk = $db->prepare("SELECT COALESCE(NULLIF(?,0),NULLIF(sv.duration_mins,0),NULLIF(s.duration_mins,0),60) AS dur
                    FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id
                    WHERE b.id=?");
                $thisBk->execute([$newDurMins, $id]);
            }
            $thisDur = (int)($thisBk->fetchColumn() ?: 60);
            $newEnd  = $newStart + $thisDur * 60;
            // Get all other bookings on that date for overlap check.
            try {
                $others = $db->prepare("SELECT b.booked_time, COALESCE(NULLIF(b.duration_mins,0),NULLIF(sv.duration_mins,0),NULLIF(s.duration_mins,0),60) AS dur
                    FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id
                    WHERE b.booked_date=? AND b.status IN ('pending','confirmed') AND b.id != ?");
                $others->execute([$newDate, $id]);
            } catch (Throwable $e) {
                $others = $db->prepare("SELECT b.booked_time, COALESCE(NULLIF(sv.duration_mins,0),NULLIF(s.duration_mins,0),60) AS dur
                    FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id
                    WHERE b.booked_date=? AND b.status IN ('pending','confirmed') AND b.id != ?");
                $others->execute([$newDate, $id]);
            }
            foreach ($others->fetchAll() as $row) {
                $bs = strtotime($newDate . ' ' . $row['booked_time']);
                $be = $bs + (int)$row['dur'] * 60;
                if ($newStart < $be && $newEnd > $bs) {
                    http_response_code(409);
                    echo json_encode(['error' => 'That time slot overlaps an existing booking. Choose another time.']);
                    exit;
                }
            }
            if ($newDurMins !== null) {
                $db->prepare("UPDATE bookings SET booked_date=?, booked_time=?, duration_mins=? WHERE id=?")->execute([$newDate, $newTime, $newDurMins, $id]);
            } else {
                $db->prepare("UPDATE bookings SET booked_date=?, booked_time=? WHERE id=?")->execute([$newDate, $newTime, $id]);
            }
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $bk = $db->query("SELECT b.*, s.name as s_name, c.name as c_name, c.email as c_email FROM bookings b JOIN services s ON s.id=b.service_id JOIN customers c ON c.id=b.customer_id WHERE b.id=$id")->fetch();
                if ($bk) emailBookingRescheduled($bk, ['name' => $bk['c_name'], 'email' => $bk['c_email']], ['name' => $bk['s_name']]);
            } catch (Throwable $e) { error_log('Admin API reschedule email: ' . $e->getMessage()); }
            echo json_encode(['success' => true]); exit;
        }

        if ($method === 'POST' && $id && $action === 'set_duration') {
            $mins = isset($body['duration_mins']) ? (int)$body['duration_mins'] : 0;
            if ($mins < 1) { http_response_code(400); echo json_encode(['error' => 'duration_mins must be at least 1']); exit; }
            $db->prepare("UPDATE bookings SET duration_mins=? WHERE id=?")->execute([$mins, $id]);
            // Return the computed end time for the UI to display
            $bk = $db->prepare("SELECT booked_date, booked_time FROM bookings WHERE id=?");
            $bk->execute([$id]); $bk = $bk->fetch();
            $endTime = $bk ? date('H:i', strtotime($bk['booked_date'] . ' ' . $bk['booked_time']) + $mins * 60) : null;
            echo json_encode(['success' => true, 'duration_mins' => $mins, 'end_time' => $endTime]); exit;
        }

        if ($method === 'DELETE' && $id) {
            // Only allow deleting cancelled/archived bookings with no successful payments
            $bkRow = $db->prepare("SELECT status, is_archived FROM bookings WHERE id=?");
            $bkRow->execute([$id]); $bkRow = $bkRow->fetch();
            if (!$bkRow || (!in_array($bkRow['status'], ['cancelled','rejected']) && !$bkRow['is_archived'])) {
                http_response_code(400); echo json_encode(['error' => 'Only cancelled bookings can be deleted']); exit;
            }
            $paidStmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE booking_id=? AND status='succeeded'");
            $paidStmt->execute([$id]);
            if ((int)$paidStmt->fetchColumn() > 0) {
                http_response_code(400); echo json_encode(['error' => 'Cannot delete: booking has a payment record']); exit;
            }
            $db->prepare("DELETE FROM booking_addons WHERE booking_id=?")->execute([$id]);
            $db->prepare("DELETE FROM payments WHERE booking_id=?")->execute([$id]);
            $db->prepare("DELETE FROM bookings WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]); exit;
        }

        if ($method === 'POST' && $id && $action === 'deposit') {
            $db->prepare("UPDATE bookings SET deposit_paid=1 WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE payments SET status='succeeded', confirmed_by='admin', confirmed_at=NOW() WHERE booking_id=? AND type='deposit'")->execute([$id]);
            $db->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND status='pending'")->execute([$id]);
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $bk = $db->query("SELECT b.*, s.name as s_name, c.name as c_name, c.email as c_email FROM bookings b JOIN services s ON s.id=b.service_id JOIN customers c ON c.id=b.customer_id WHERE b.id=$id")->fetch();
                if ($bk) emailBookingApproved($bk, ['name' => $bk['c_name'], 'email' => $bk['c_email']], ['name' => $bk['s_name']]);
            } catch (Throwable $e) { error_log('Admin API deposit email: ' . $e->getMessage()); }
            echo json_encode(['success' => true]);
            exit;
        }

        // ── Update admin notes ────────────────────────────────
        if ($method === 'POST' && $id && $action === 'notes') {
            $notes = trim($body['notes'] ?? '');
            $db->prepare("UPDATE bookings SET admin_notes=? WHERE id=?")->execute([$notes ?: null, $id]);
            echo json_encode(['success' => true]); exit;
        }

        // ── Regenerate payment link ───────────────────────────
        if ($method === 'POST' && $id && $action === 'payment_link') {
            $bkRow = $db->prepare("SELECT deposit_paid FROM bookings WHERE id=?");
            $bkRow->execute([$id]); $bkRow = $bkRow->fetch();
            if (!$bkRow || $bkRow['deposit_paid']) {
                http_response_code(400); echo json_encode(['error' => 'Deposit already paid — cannot generate link']); exit;
            }
            $newToken = bin2hex(random_bytes(32));
            $db->prepare("UPDATE bookings SET payment_token=? WHERE id=?")->execute([$newToken, $id]);
            echo json_encode(['success' => true, 'payment_link' => SITE_URL . '/pay?token=' . $newToken, 'token' => $newToken]); exit;
        }

        // ── Revoke payment link ───────────────────────────────
        if ($method === 'DELETE' && $id && $action === 'payment_link') {
            $db->prepare("UPDATE bookings SET payment_token=NULL WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]); exit;
        }

        // ── Stripe Terminal: create PaymentIntent ─────────────
        if ($method === 'POST' && $id && $action === 'terminal_payment') {
            if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
                http_response_code(500); echo json_encode(['error' => 'Stripe not configured']); exit;
            }
            $type = sanitize($body['type'] ?? 'balance'); // deposit | balance | full
            $bkStmt = $db->prepare("SELECT * FROM bookings WHERE id=?");
            $bkStmt->execute([$id]); $bk = $bkStmt->fetch();
            if (!$bk) { http_response_code(404); echo json_encode(['error' => 'Booking not found']); exit; }

            $total   = (float)$bk['total_price'];
            $deposit = (float)$bk['deposit_amount'];
            $balance = round($total - $deposit, 2);
            $amount  = match($type) {
                'deposit' => $deposit,
                'full'    => $total,
                default   => $balance,
            };
            if ($amount <= 0) { http_response_code(400); echo json_encode(['error' => 'Nothing to collect']); exit; }

            $pence = (int)round($amount * 100);
            $ch = curl_init('https://api.stripe.com/v1/payment_intents');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'amount'                   => $pence,
                    'currency'                 => 'gbp',
                    'payment_method_types[]'   => 'card_present',
                    'capture_method'           => 'automatic',
                    'metadata[booking_id]'     => $id,
                    'metadata[booking_ref]'    => $bk['booking_ref'] ?? '',
                    'metadata[payment_type]'   => $type,
                ]),
                CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp   = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $pi = json_decode($resp, true);
            if ($code === 200 && isset($pi['client_secret'])) {
                echo json_encode(['client_secret' => $pi['client_secret'], 'payment_intent_id' => $pi['id'], 'amount_pence' => $pence, 'amount_gbp' => $amount, 'type' => $type]);
            } else {
                http_response_code(500); echo json_encode(['error' => $pi['error']['message'] ?? 'Failed to create payment']);
            }
            exit;
        }

        // ── Stripe Terminal: confirm payment after card tap ────
        if ($method === 'POST' && $id && $action === 'terminal_confirm') {
            if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
                http_response_code(500); echo json_encode(['error' => 'Stripe not configured']); exit;
            }
            $piId        = sanitize($body['payment_intent_id'] ?? '');
            $type        = sanitize($body['type']             ?? 'balance');
            $amountPence = (int)($body['amount_pence']        ?? 0);
            if (!$piId) { http_response_code(400); echo json_encode(['error' => 'Payment intent ID required']); exit; }

            // Verify with Stripe that payment succeeded
            $ch = curl_init("https://api.stripe.com/v1/payment_intents/$piId");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => STRIPE_SECRET_KEY . ':']);
            $resp = curl_exec($ch); curl_close($ch);
            $pi = json_decode($resp, true);
            if (!isset($pi['status']) || $pi['status'] !== 'succeeded') {
                http_response_code(400); echo json_encode(['error' => 'Payment not confirmed by Stripe (status: '.($pi['status'] ?? 'unknown').')']); exit;
            }

            $amountGbp = round($amountPence / 100, 2);

            // Record payment
            $db->prepare("INSERT INTO payments (booking_id, type, amount, currency, method, status, stripe_id, confirmed_by, confirmed_at)
                          VALUES (?,?,?,'GBP','stripe_terminal','succeeded',?,?,NOW())")
               ->execute([$id, $type, $amountGbp, $piId, 'stripe_terminal']);

            // Update booking state
            if (in_array($type, ['deposit', 'full'])) {
                $db->prepare("UPDATE bookings SET deposit_paid=1, payment_method='stripe' WHERE id=?")->execute([$id]);
                $db->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND status='pending'")->execute([$id]);
            }
            if (in_array($type, ['balance', 'full'])) {
                $db->prepare("UPDATE bookings SET status='completed' WHERE id=?")->execute([$id]);
                // Award loyalty points
                try { awardLoyaltyPoints($id); } catch (Throwable $e) { error_log('terminal loyalty: '.$e->getMessage()); }
                // Journal entry — DR 1000 Stripe Account (not cash)
                try {
                    $bkJ = $db->prepare("SELECT total_price, deposit_amount, deposit_paid, booking_ref FROM bookings WHERE id=?");
                    $bkJ->execute([$id]); $bkData = $bkJ->fetch();
                    if ($bkData) {
                        $total   = (float)$bkData['total_price'];
                        $deposit = (float)$bkData['deposit_amount'];
                        $today   = date('Y-m-d'); $ref = $bkData['booking_ref'];
                        $lines   = [];
                        if ((int)$bkData['deposit_paid'] && $deposit > 0 && $type === 'balance') {
                            $lines[] = ['account_code' => '2000', 'debit' => $deposit,    'credit' => 0,     'memo' => 'Deposit released'];
                        }
                        $lines[] = ['account_code' => '1000', 'debit' => $amountGbp, 'credit' => 0,         'memo' => 'Card payment (Terminal)'];
                        $lines[] = ['account_code' => '4000', 'debit' => 0,          'credit' => $total,    'memo' => 'Service revenue: '.$ref];
                        createJournalEntry($today, 'Card payment (Terminal): '.$ref, 'booking_payment', $id, $lines, $ref);
                    }
                } catch (Throwable $e) { error_log('terminal journal: '.$e->getMessage()); }
            }
            echo json_encode(['success' => true]); exit;
        }

        // ── Create booking ────────────────────────────────────
        if ($method === 'POST' && !$id && !$action) {
            require_once __DIR__ . '/../includes/helpers.php';
            $customerMode = sanitize($body['customer_mode'] ?? 'existing');
            $customerId   = (int)($body['customer_id'] ?? 0);

            if ($customerMode === 'new') {
                $newName  = sanitize($body['new_name']  ?? '');
                $newEmail = sanitize($body['new_email'] ?? '');
                $newPhone = sanitize($body['new_phone'] ?? '');
                if (!$newName || !$newEmail) {
                    http_response_code(400); echo json_encode(['error' => 'Name and email required for new client']); exit;
                }
                $existStmt = $db->prepare("SELECT id FROM customers WHERE email=?");
                $existStmt->execute([$newEmail]);
                $existRow = $existStmt->fetch();
                if ($existRow) {
                    $customerId = (int)$existRow['id'];
                    if ($newPhone) $db->prepare("UPDATE customers SET phone=? WHERE id=?")->execute([$newPhone, $customerId]);
                } else {
                    $db->prepare("INSERT INTO customers (name, email, phone) VALUES (?,?,?)")->execute([$newName, $newEmail, $newPhone ?: null]);
                    $customerId = (int)$db->lastInsertId();
                }
            }
            if (!$customerId) { http_response_code(400); echo json_encode(['error' => 'Customer required']); exit; }

            $isCustomStyle   = !empty($body['is_custom_style']);
            $customStyleName = trim($body['custom_style_name'] ?? '');
            $customStyleDesc = trim($body['custom_style_desc'] ?? '');
            $serviceId       = (int)($body['service_id']  ?? 0);
            $variantId       = (int)($body['variant_id']  ?? 0) ?: null;
            $addonIds        = array_map('intval', (array)($body['addon_ids'] ?? []));
            $bookedDate      = sanitize($body['booked_date'] ?? '');
            $bookedTime      = sanitize($body['booked_time'] ?? '');
            $status          = sanitize($body['status'] ?? 'pending');
            $payMethod       = sanitize($body['payment_method'] ?? 'bank_transfer');
            $payAllowed      = sanitize($body['payment_method_allowed'] ?? 'both');
            $depositPaid     = !empty($body['deposit_paid']) ? 1 : 0;
            $clientNotes     = trim($body['client_notes'] ?? '');
            $adminNotes      = trim($body['admin_notes']  ?? '');
            $manualPrice     = (isset($body['manual_price']) && is_numeric($body['manual_price'])) ? (float)$body['manual_price'] : null;

            // ── Custom Style: resolve or auto-create the sentinel service row ──
            if ($isCustomStyle) {
                if (!$customStyleName) {
                    http_response_code(400); echo json_encode(['error' => 'Style name is required for a custom style booking']); exit;
                }
                $csRow = $db->query("SELECT id FROM services WHERE name='Custom Style' LIMIT 1")->fetch();
                if ($csRow) {
                    $serviceId = (int)$csRow['id'];
                } else {
                    $db->prepare("INSERT INTO services (name, slug, description, price_from, duration_mins, is_active, display_order) VALUES ('Custom Style','custom-style','Admin-created custom style booking',0,60,0,9999)")
                       ->execute([]);
                    $serviceId = (int)$db->lastInsertId();
                }
                $variantId = null;
                $addonIds  = [];
            }

            if (!$serviceId || !$bookedDate || !$bookedTime) {
                http_response_code(400); echo json_encode(['error' => 'Service, date and time are required']); exit;
            }

            // Calculate price
            if ($manualPrice !== null) {
                $totalPrice = $manualPrice;
            } elseif ($variantId) {
                $vStmt = $db->prepare("SELECT price FROM service_variants WHERE id=?");
                $vStmt->execute([$variantId]);
                $totalPrice = (float)($vStmt->fetchColumn() ?: 0);
            } else {
                $sStmt = $db->prepare("SELECT price_from FROM services WHERE id=?");
                $sStmt->execute([$serviceId]);
                $totalPrice = (float)($sStmt->fetchColumn() ?: 0);
            }

            $addonRows = [];
            if (!empty($addonIds)) {
                $ph = implode(',', array_fill(0, count($addonIds), '?'));
                $aStmt = $db->prepare("SELECT id, price FROM service_addons WHERE id IN ($ph)");
                $aStmt->execute($addonIds); $addonRows = $aStmt->fetchAll();
                foreach ($addonRows as $ar) { $totalPrice += (float)$ar['price']; }
            }

            $depositPct    = (int)getSetting('deposit_percent', '30');
            $depositAmount = round($totalPrice * $depositPct / 100, 2);
            $remaining     = round($totalPrice - $depositAmount, 2);
            $bookingRef    = generateBookingRef();
            $payToken      = $depositPaid ? null : bin2hex(random_bytes(32));

            // Normalise time to HH:MM:SS
            if (strlen($bookedTime) === 5) $bookedTime .= ':00';

            // Prepend custom style name to admin notes so it surfaces in all booking views
            $adminNotesStored = $adminNotes ?: null;
            if ($isCustomStyle && $customStyleName) {
                $prefix = "CUSTOM STYLE: {$customStyleName}" . ($customStyleDesc ? " — {$customStyleDesc}" : '');
                $adminNotesStored = $prefix . ($adminNotes ? "\n\n" . $adminNotes : '');
            }

            $db->prepare("INSERT INTO bookings
                (booking_ref, customer_id, service_id, variant_id, booked_date, booked_time,
                 status, payment_method, deposit_amount, deposit_paid, total_price,
                 remaining_balance, client_notes, admin_notes, policy_accepted,
                 payment_token, payment_method_allowed, custom_style_name, custom_style_desc)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)")
               ->execute([$bookingRef, $customerId, $serviceId, $variantId, $bookedDate, $bookedTime,
                          $status, $payMethod, $depositAmount, $depositPaid, $totalPrice,
                          $remaining, $clientNotes ?: null, $adminNotesStored, $payToken, $payAllowed,
                          $isCustomStyle ? $customStyleName : null,
                          $isCustomStyle ? ($customStyleDesc ?: null) : null]);
            $bookingId = (int)$db->lastInsertId();

            if (!empty($addonRows)) {
                $addonStmt = $db->prepare("INSERT INTO booking_addons (booking_id, addon_id, price_charged) VALUES (?,?,?)");
                foreach ($addonRows as $ar) { $addonStmt->execute([$bookingId, $ar['id'], $ar['price']]); }
            }

            if ($depositPaid) {
                $db->prepare("INSERT INTO payments (booking_id, amount, currency, type, method, status, confirmed_by, confirmed_at) VALUES (?,?,'GBP','deposit',?,'succeeded','admin',NOW())")->execute([$bookingId, $depositAmount, $payMethod]);
            }

            // Always email the client when admin creates a booking
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $freshBk  = $db->query("SELECT b.*, s.name as s_name, sv.variant_name FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id WHERE b.id=$bookingId")->fetch();
                $customer = $db->query("SELECT name, email FROM customers WHERE id=$customerId")->fetch();
                // For custom style, show the style name in emails rather than "Custom Style"
                $displaySvcName = ($isCustomStyle && $customStyleName) ? $customStyleName : ($freshBk['s_name'] ?? 'Custom Style');
                if ($freshBk && $customer && !empty($customer['email'])) {
                    if ($status === 'confirmed' && $depositPaid) {
                        emailBookingApproved($freshBk, $customer, ['name' => $displaySvcName]);
                    } else {
                        $pLink = $payToken ? (SITE_URL . '/pay?token=' . $payToken) : null;
                        emailBookingCreatedByAdmin($freshBk, $customer, ['name' => $displaySvcName], $pLink);
                    }
                }
            } catch (Throwable $e) { error_log('Admin API new booking email: ' . $e->getMessage()); }

            echo json_encode([
                'success'      => true,
                'id'           => $bookingId,
                'booking_ref'  => $bookingRef,
                'payment_link' => $payToken ? (SITE_URL . '/pay?token=' . $payToken) : null,
            ]);
            exit;
        }

        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── CALENDAR ─────────────────────────────────────────────
    // GET    /api/admin/calendar[?year=&month=]
    // POST   /api/admin/calendar/block  { "date", "time_slot"?, "reason"? }
    // DELETE /api/admin/calendar/block  { "date", "time_slot"? }
    case 'calendar':
        requireAuth();
        $db = getDB();
        if ($method === 'GET') {
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            // Bookings this month — include duration so the calendar can show the
            // true occupancy window (start → end), and group/guest info for families.
            // Defensive try-catch: duration_mins / guest_name / cart_group_ref may
            // not be migrated yet on the live DB.
            try {
                $stmt = $db->prepare("SELECT b.id, b.booked_date, b.booked_time, b.status,
                                             b.guest_name, b.cart_group_ref,
                                             COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins,
                                             c.name as c_name, s.name as s_name
                                      FROM bookings b
                                      JOIN customers c ON c.id=b.customer_id
                                      JOIN services s ON s.id=b.service_id
                                      LEFT JOIN service_variants sv ON sv.id=b.variant_id
                                      WHERE YEAR(b.booked_date)=? AND MONTH(b.booked_date)=? AND b.status != 'cancelled'
                                      ORDER BY b.booked_time");
                $stmt->execute([$year, $month]); $bookings = $stmt->fetchAll();
            } catch (Throwable $e) {
                $stmt = $db->prepare("SELECT b.id, b.booked_date, b.booked_time, b.status, c.name as c_name, s.name as s_name,
                                             COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins
                                      FROM bookings b
                                      JOIN customers c ON c.id=b.customer_id
                                      JOIN services s ON s.id=b.service_id
                                      LEFT JOIN service_variants sv ON sv.id=b.variant_id
                                      WHERE YEAR(b.booked_date)=? AND MONTH(b.booked_date)=? AND b.status != 'cancelled'
                                      ORDER BY b.booked_time");
                $stmt->execute([$year, $month]); $bookings = $stmt->fetchAll();
            }
            // Add a computed end_time (HH:MM) for each booking for the calendar UI
            foreach ($bookings as &$bk) {
                $dur = (int)($bk['duration_mins'] ?? 60);
                $bk['end_time'] = date('H:i', strtotime($bk['booked_date'] . ' ' . $bk['booked_time']) + $dur * 60);
            }
            unset($bk);
            // Blocked (full-day and slots)
            $bstmt = $db->prepare("SELECT avail_date, time_slot, block_reason FROM availability WHERE YEAR(avail_date)=? AND MONTH(avail_date)=? AND is_blocked=1");
            $bstmt->execute([$year, $month]); $blocks = $bstmt->fetchAll();
            echo json_encode(['bookings' => $bookings, 'blocks' => $blocks]);
            exit;
        }

        if ($action === 'block') {
            $date      = sanitize($body['date']      ?? '');
            $rawSlot   = sanitize($body['time_slot'] ?? 'all');
            $reason    = sanitize($body['reason']    ?? '');
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid date']); exit;
            }
            $timeSlot = ($rawSlot === '' || $rawSlot === 'all') ? null
                        : (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $rawSlot) ? $rawSlot : null);
            if ($method === 'POST') {
                if ($timeSlot === null) {
                    $db->prepare("INSERT INTO availability (avail_date, time_slot, is_blocked, block_reason) VALUES (?,NULL,1,?) ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=VALUES(block_reason)")->execute([$date, $reason]);
                } else {
                    $db->prepare("INSERT INTO availability (avail_date, time_slot, is_blocked, block_reason) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=VALUES(block_reason)")->execute([$date, $timeSlot, $reason]);
                }
                echo json_encode(['success' => true]); exit;
            }
            if ($method === 'DELETE') {
                if ($timeSlot === null) {
                    $db->prepare("DELETE FROM availability WHERE avail_date=? AND time_slot IS NULL")->execute([$date]);
                } else {
                    $db->prepare("DELETE FROM availability WHERE avail_date=? AND time_slot=?")->execute([$date, $timeSlot]);
                }
                echo json_encode(['success' => true]); exit;
            }
        }

        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── CUSTOMERS ────────────────────────────────────────────
    // GET /api/admin/customers[?q=&page=]
    // GET /api/admin/customers/{id}
    case 'customers':
        requireAuth();
        $db = getDB();
        if ($method === 'GET' && !$id) {
            $q      = sanitize($_GET['q'] ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $per    = 30; $offset = ($page - 1) * $per;
            $where  = ['1=1']; $params = [];
            if ($q) {
                $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
                $like = '%' . $q . '%'; $params = [$like, $like, $like];
            }
            $wc = implode(' AND ', $where);
            $cstmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE $wc");
            $cstmt->execute($params);
            $total = (int)$cstmt->fetchColumn();
            $stmt  = $db->prepare("SELECT id, name, email, phone, loyalty_points, tags, is_blocked, created_at FROM customers WHERE $wc ORDER BY name LIMIT $per OFFSET $offset");
            $stmt->execute($params);
            echo json_encode(['customers' => $stmt->fetchAll(), 'total' => $total]);
            exit;
        }
        if ($method === 'GET' && $id) {
            $cstmt = $db->prepare("SELECT * FROM customers WHERE id=?");
            $cstmt->execute([$id]);
            $customer = $cstmt->fetch();
            if (!$customer) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            // Bookings
            $bstmt = $db->prepare("SELECT b.id, b.booking_ref, b.booked_date, b.booked_time, b.status, b.total_price, s.name as s_name, sv.variant_name FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id WHERE b.customer_id=? ORDER BY b.booked_date DESC LIMIT 20");
            $bstmt->execute([$id]); $customer['bookings'] = $bstmt->fetchAll();
            // Admin notes
            $nstmt = $db->prepare("SELECT * FROM customer_notes WHERE customer_id=? ORDER BY created_at DESC LIMIT 20");
            $nstmt->execute([$id]); $customer['notes'] = $nstmt->fetchAll();
            // Loyalty history
            $lstmt = $db->prepare("SELECT * FROM loyalty_transactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 15");
            $lstmt->execute([$id]); $customer['loyalty_history'] = $lstmt->fetchAll();
            // LTV
            $ltvStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE customer_id=? AND status='completed'");
            $ltvStmt->execute([$id]); $customer['ltv'] = (float)$ltvStmt->fetchColumn();
            $customer['completed_count'] = count(array_filter($customer['bookings'], fn($b) => $b['status'] === 'completed'));
            echo json_encode($customer);
            exit;
        }

        // POST actions: note | loyalty | block | tags
        if ($method === 'POST' && $id) {
            require_once __DIR__ . '/../includes/helpers.php';

            if ($action === 'note') {
                $note = trim($body['note'] ?? '');
                if (!$note) { http_response_code(400); echo json_encode(['error' => 'Note required']); exit; }
                $db->prepare("INSERT INTO customer_notes (customer_id, note) VALUES (?,?)")->execute([$id, $note]);
                echo json_encode(['success' => true]); exit;
            }

            if ($action === 'loyalty') {
                $delta = (int)($body['points']      ?? 0);
                $desc  = trim($body['description']  ?? '') ?: ($delta > 0 ? 'Manual addition' : 'Manual removal');
                $type  = $delta > 0 ? 'manual_add' : 'manual_remove';
                if ($delta === 0) { http_response_code(400); echo json_encode(['error' => 'Points cannot be zero']); exit; }
                adjustLoyaltyPoints($id, $delta, $type, $desc);
                $pts = (int)$db->prepare("SELECT loyalty_points FROM customers WHERE id=?")->query([$id])?->fetchColumn();
                $pstmt = $db->prepare("SELECT loyalty_points FROM customers WHERE id=?");
                $pstmt->execute([$id]); $pts = (int)$pstmt->fetchColumn();
                echo json_encode(['success' => true, 'loyalty_points' => $pts]); exit;
            }

            if ($action === 'block') {
                $block  = (bool)($body['is_blocked']   ?? false);
                $reason = trim($body['block_reason']   ?? '');
                if ($block) {
                    $db->prepare("UPDATE customers SET is_blocked=1, block_reason=?, blocked_at=NOW() WHERE id=?")->execute([$reason ?: null, $id]);
                } else {
                    $db->prepare("UPDATE customers SET is_blocked=0, block_reason=NULL, blocked_at=NULL WHERE id=?")->execute([$id]);
                }
                echo json_encode(['success' => true]); exit;
            }

            if ($action === 'tags') {
                $allowed  = ['VIP', 'Regular', 'New Client', 'At Risk'];
                $selected = array_filter((array)($body['tags'] ?? []), fn($t) => in_array($t, $allowed));
                $db->prepare("UPDATE customers SET tags=? WHERE id=?")->execute([implode(',', $selected) ?: null, $id]);
                echo json_encode(['success' => true]); exit;
            }
        }

        // ── DELETE customer ───────────────────────────────────
        // Blocked if customer has any pending or confirmed bookings.
        // All completed/cancelled booking history, notes, loyalty records,
        // orders, and payments are permanently removed.
        if ($method === 'DELETE' && $id) {
            // Guard: refuse deletion if active bookings exist
            $activeStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id=? AND status IN ('pending','confirmed')");
            $activeStmt->execute([$id]);
            if ((int)$activeStmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'Cannot delete: client has pending or confirmed bookings. Cancel them first.']);
                exit;
            }

            // Verify customer exists
            $existStmt = $db->prepare("SELECT id FROM customers WHERE id=?");
            $existStmt->execute([$id]);
            if (!$existStmt->fetch()) {
                http_response_code(404); echo json_encode(['error' => 'Customer not found']); exit;
            }

            $db->beginTransaction();
            try {
                // 1. Booking-linked records
                $bookingIds = $db->prepare("SELECT id FROM bookings WHERE customer_id=?");
                $bookingIds->execute([$id]);
                foreach ($bookingIds->fetchAll(PDO::FETCH_COLUMN) as $bid) {
                    $db->prepare("DELETE FROM booking_addons WHERE booking_id=?")->execute([$bid]);
                    $db->prepare("DELETE FROM payments WHERE booking_id=?")->execute([$bid]);
                }
                $db->prepare("DELETE FROM bookings WHERE customer_id=?")->execute([$id]);

                // 2. Order-linked records
                $orderIds = $db->prepare("SELECT id FROM orders WHERE customer_id=?");
                $orderIds->execute([$id]);
                foreach ($orderIds->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                    $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$oid]);
                }
                $db->prepare("DELETE FROM orders WHERE customer_id=?")->execute([$id]);

                // 3. Customer-linked records
                $db->prepare("DELETE FROM loyalty_transactions WHERE customer_id=?")->execute([$id]);
                $db->prepare("DELETE FROM customer_notes WHERE customer_id=?")->execute([$id]);

                // 4. Customer row
                $db->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);

                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('Customer delete error: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete customer. Please try again.']);
            }
            exit;
        }

        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── ORDERS ───────────────────────────────────────────────
    // GET  /api/admin/orders[?status=&page=]
    // GET  /api/admin/orders/{id}
    // POST /api/admin/orders/{id}/status  { "status": "dispatched" }
    case 'orders':
        requireAuth();
        $db = getDB();
        if ($method === 'GET' && !$id) {
            $status = sanitize($_GET['status'] ?? '');
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $per    = 20; $offset = ($page - 1) * $per;
            $where  = ['1=1']; $params = [];
            if ($status) { $where[] = 'o.status=?'; $params[] = $status; }
            $wc = implode(' AND ', $where);
            $cstmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE $wc");
            $cstmt->execute($params); $total = (int)$cstmt->fetchColumn();
            $stmt  = $db->prepare("SELECT o.*, c.name as c_name, c.email as c_email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE $wc ORDER BY o.created_at DESC LIMIT $per OFFSET $offset");
            $stmt->execute($params);
            echo json_encode(['orders' => $stmt->fetchAll(), 'total' => $total]);
            exit;
        }
        if ($method === 'GET' && $id) {
            $ostmt = $db->prepare("SELECT o.*, c.name as c_name, c.email as c_email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=?");
            $ostmt->execute([$id]); $order = $ostmt->fetch();
            if (!$order) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            $istmt = $db->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
            $istmt->execute([$id]); $order['items'] = $istmt->fetchAll();
            echo json_encode($order); exit;
        }
        if ($method === 'POST' && $id && $action === 'status') {
            $newStatus = sanitize($body['status'] ?? '');
            $allowed   = ['pending','processing','dispatched','delivered','cancelled','refunded'];
            if (!in_array($newStatus, $allowed)) { http_response_code(400); echo json_encode(['error' => 'Invalid status']); exit; }
            $db->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $id]);
            if ($newStatus === 'dispatched') {
                // Optional tracking number from mobile app
                $trackingNumber = trim(sanitize($body['tracking_number'] ?? ''));
                if ($trackingNumber !== '') {
                    try {
                        $db->prepare("UPDATE orders SET tracking_number=?, dispatched_at=NOW() WHERE id=?")->execute([$trackingNumber, $id]);
                    } catch (Throwable $e) { error_log('Admin API set tracking: ' . $e->getMessage()); }
                }
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $o = $db->query("SELECT o.*, c.name as c_name, c.email as c_email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=$id")->fetch();
                    if ($o) emailOrderDispatched($o, ['name' => $o['c_name'], 'email' => $o['c_email']], $trackingNumber);
                } catch (Throwable $e) { error_log('Admin API dispatch email: ' . $e->getMessage()); }
            }
            echo json_encode(['success' => true]); exit;
        }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── SETTINGS ─────────────────────────────────────────────
    // GET  /api/admin/settings
    // POST /api/admin/settings  { "key": "...", "value": "..." }
    case 'settings':
        requireAuth();
        $db = getDB();
        if ($method === 'GET') {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
            $rows = $stmt->fetchAll();
            $out  = [];
            // Exclude sensitive values from the mobile API
            $sensitive = ['smtp_pass', 'stripe_secret_key', 'db_pass'];
            foreach ($rows as $r) {
                if (!in_array(strtolower($r['setting_key']), $sensitive)) {
                    $out[$r['setting_key']] = $r['setting_value'];
                }
            }
            echo json_encode($out); exit;
        }
        if ($method === 'POST') {
            $key   = sanitize($body['key']   ?? '');
            $value = sanitize($body['value'] ?? '');
            // Block updating sensitive keys from the mobile app
            $blocked = ['smtp_pass', 'stripe_secret_key', 'db_pass'];
            if (!$key || in_array(strtolower($key), $blocked)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid or restricted key']); exit;
            }
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$key, $value]);
            echo json_encode(['success' => true]); exit;
        }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── ACCOUNTING ───────────────────────────────────────────
    // GET  /api/admin/accounting           → today + month summary
    // POST /api/admin/accounting/expense   → log expense
    // POST /api/admin/accounting/draw      → log owner draw
    case 'accounting':
        requireAuth();
        $db = getDB();
        require_once __DIR__ . '/../includes/helpers.php';

        if ($method === 'GET') {
            $today      = date('Y-m-d');
            $monthStart = date('Y-m-01');
            $monthEnd   = date('Y-m-t');

            $todayStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed' AND booked_date=?");
            $todayStmt->execute([$today]);
            $todayTakings = (float)$todayStmt->fetchColumn();

            $revStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE status='completed' AND booked_date BETWEEN ? AND ?");
            $revStmt->execute([$monthStart, $monthEnd]);
            $monthRevenue = (float)$revStmt->fetchColumn();

            $expStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
            $expStmt->execute([$monthStart, $monthEnd]);
            $monthExpenses = (float)$expStmt->fetchColumn();

            $drawStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM owner_draws WHERE draw_date BETWEEN ? AND ?");
            $drawStmt->execute([$monthStart, $monthEnd]);
            $monthDraws = (float)$drawStmt->fetchColumn();

            $recent = $db->query("SELECT * FROM expenses ORDER BY expense_date DESC, created_at DESC LIMIT 10")->fetchAll();
            $draws  = $db->query("SELECT * FROM owner_draws ORDER BY draw_date DESC, created_at DESC LIMIT 5")->fetchAll();

            echo json_encode([
                'today_takings'   => $todayTakings,
                'month_revenue'   => $monthRevenue,
                'month_expenses'  => $monthExpenses,
                'month_draws'     => $monthDraws,
                'month_profit'    => $monthRevenue - $monthExpenses - $monthDraws,
                'recent_expenses' => $recent,
                'recent_draws'    => $draws,
            ]);
            exit;
        }

        if ($method === 'POST' && $action === 'expense') {
            $amount   = (float)($body['amount']      ?? 0);
            $desc     = trim($body['description']    ?? '');
            $category = trim($body['category']       ?? 'Business Expenses');
            $date     = trim($body['date']           ?? date('Y-m-d'));
            $notes    = trim($body['notes']          ?? '');
            if ($amount <= 0 || !$desc) { http_response_code(400); echo json_encode(['error' => 'Amount and description required']); exit; }
            $db->prepare("INSERT INTO expenses (expense_date, description, amount, category, notes) VALUES (?,?,?,?,?)")->execute([$date, $desc, $amount, $category, $notes]);
            $expId = (int)$db->lastInsertId();
            journalExpense($expId, $amount, $date, $desc);
            echo json_encode(['success' => true, 'id' => $expId]); exit;
        }

        if ($method === 'POST' && $action === 'draw') {
            $amount = (float)($body['amount'] ?? 0);
            $date   = trim($body['date']      ?? date('Y-m-d'));
            $notes  = trim($body['notes']     ?? '');
            if ($amount <= 0) { http_response_code(400); echo json_encode(['error' => 'Amount required']); exit; }
            $db->prepare("INSERT INTO owner_draws (draw_date, amount, notes) VALUES (?,?,?)")->execute([$date, $amount, $notes]);
            $drawId = (int)$db->lastInsertId();
            journalOwnerDraw($drawId, $amount, $date);
            echo json_encode(['success' => true, 'id' => $drawId]); exit;
        }

        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── SERVICES ─────────────────────────────────────────────
    case 'services':
        requireAuth();
        $db     = getDB();
        $action = $_GET['action'] ?? '';

        // ── GET — list ALL services with variants + per-service addons ────
        if ($method === 'GET' && $action === '') {
            $services  = $db->query("SELECT id, name, description, price_from, duration_mins, category, is_active, display_order FROM services ORDER BY display_order ASC, id ASC")->fetchAll();
            $variants  = $db->query("SELECT id, service_id, variant_name, price, duration_mins FROM service_variants ORDER BY display_order ASC")->fetchAll();
            // Per-service add-ons only (retired global rows have service_id = NULL and are excluded).
            $allAddons = $db->query("SELECT id, service_id, name, price, is_active FROM service_addons WHERE service_id IS NOT NULL AND is_active = 1 ORDER BY id ASC")->fetchAll();
            $varsBySvc = []; foreach ($variants  as $v) { $varsBySvc[$v['service_id']][] = $v; }
            $addBySvc  = []; foreach ($allAddons as $a) { $addBySvc[$a['service_id']][] = $a; }
            foreach ($services as &$s) {
                $s['variants'] = $varsBySvc[$s['id']] ?? [];
                $s['addons']   = $addBySvc[$s['id']] ?? [];
            }
            unset($s);
            echo json_encode(['services' => $services]); exit;
        }

        // ── POST — create service ─────────────────────────────
        if ($method === 'POST' && $action === '') {
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $name     = trim($body['name']         ?? '');
            $desc     = trim($body['description']  ?? '');
            $price    = (float)($body['price_from']    ?? 0);
            $dur      = (int)($body['duration_mins']   ?? 60);
            $cat      = trim($body['category']     ?? '');
            if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
            // Auto-generate unique slug
            $base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $slug = $base; $n = 1;
            while ($db->prepare("SELECT id FROM services WHERE slug=?")->execute([$slug]) && $db->query("SELECT id FROM services WHERE slug='$slug'")->fetchColumn()) {
                $slug = $base . '-' . $n++;
            }
            $db->prepare("INSERT INTO services (name, slug, description, price_from, duration_mins, category, is_active) VALUES (?,?,?,?,?,?,1)")
               ->execute([$name, $slug, $desc ?: null, $price, $dur, $cat ?: null]);
            $id = (int)$db->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id]); exit;
        }

        // ── POST action=update — edit service ─────────────────
        if ($method === 'POST' && $action === 'update') {
            $id   = (int)($_GET['id'] ?? 0);
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
            $db->prepare("UPDATE services SET name=?, description=?, price_from=?, duration_mins=?, category=? WHERE id=?")
               ->execute([
                   trim($body['name']          ?? ''),
                   trim($body['description']   ?? '') ?: null,
                   (float)($body['price_from']   ?? 0),
                   (int)($body['duration_mins']  ?? 60),
                   trim($body['category']      ?? '') ?: null,
                   $id
               ]);
            echo json_encode(['success' => true]); exit;
        }

        // ── POST action=toggle — toggle active ────────────────
        if ($method === 'POST' && $action === 'toggle') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
            $db->prepare("UPDATE services SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            $active = (int)$db->query("SELECT is_active FROM services WHERE id=$id")->fetchColumn();
            echo json_encode(['success' => true, 'is_active' => $active]); exit;
        }

        // ── POST action=add_variant ───────────────────────────
        if ($method === 'POST' && $action === 'add_variant') {
            $id   = (int)($_GET['id'] ?? 0);
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['variant_name'] ?? '');
            $price = (float)($body['price'] ?? 0);
            $dur   = isset($body['duration_mins']) ? (int)$body['duration_mins'] : null;
            if (!$id || !$name) { http_response_code(400); echo json_encode(['error' => 'Missing fields']); exit; }
            $db->prepare("INSERT INTO service_variants (service_id, variant_name, price, duration_mins) VALUES (?,?,?,?)")
               ->execute([$id, $name, $price, $dur]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]); exit;
        }

        // ── DELETE action=del_variant ─────────────────────────
        if ($method === 'DELETE' && $action === 'del_variant') {
            $vid = (int)($_GET['variant_id'] ?? 0);
            if (!$vid) { http_response_code(400); echo json_encode(['error' => 'variant_id required']); exit; }
            $db->prepare("DELETE FROM service_variants WHERE id=?")->execute([$vid]);
            echo json_encode(['success' => true]); exit;
        }

        // ── POST action=add_addon ─────────────────────────────
        // Add-ons are per-service: each belongs to one service and has its own price.
        if ($method === 'POST' && $action === 'add_addon') {
            $body      = json_decode(file_get_contents('php://input'), true) ?? [];
            $serviceId = (int)($body['service_id'] ?? 0);
            $name      = trim($body['name']  ?? '');
            $price     = (float)($body['price'] ?? 0);
            if (!$serviceId) { http_response_code(400); echo json_encode(['error' => 'service_id required']); exit; }
            if (!$name)      { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
            $db->prepare("INSERT INTO service_addons (service_id, name, price, is_active, is_global) VALUES (?,?,?,1,0)")
               ->execute([$serviceId, $name, $price]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]); exit;
        }

        // ── POST action=update_addon ──────────────────────────
        // Edit an add-on's name/price in place (no delete-and-recreate).
        if ($method === 'POST' && $action === 'update_addon') {
            $body  = json_decode(file_get_contents('php://input'), true) ?? [];
            $aid   = (int)($body['addon_id'] ?? 0);
            $name  = trim($body['name']  ?? '');
            $price = (float)($body['price'] ?? 0);
            if (!$aid)  { http_response_code(400); echo json_encode(['error' => 'addon_id required']); exit; }
            if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
            $db->prepare("UPDATE service_addons SET name=?, price=? WHERE id=?")->execute([$name, $price, $aid]);
            echo json_encode(['success' => true]); exit;
        }

        // ── DELETE action=del_addon ───────────────────────────
        if ($method === 'DELETE' && $action === 'del_addon') {
            $aid = (int)($_GET['addon_id'] ?? 0);
            if (!$aid) { http_response_code(400); echo json_encode(['error' => 'addon_id required']); exit; }
            try {
                $db->prepare("DELETE FROM service_addons WHERE id=?")->execute([$aid]);
            } catch (PDOException $e) {
                // Referenced by past bookings (booking_addons FK) — soft-delete instead.
                $db->prepare("UPDATE service_addons SET is_active=0 WHERE id=?")->execute([$aid]);
            }
            echo json_encode(['success' => true]); exit;
        }

        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── STRIPE TERMINAL CONNECTION TOKEN ─────────────────────
    case 'terminal_connection_token':
        requireAuth();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
        if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
            http_response_code(500); echo json_encode(['error' => 'Stripe not configured']); exit;
        }
        $ch = curl_init('https://api.stripe.com/v1/terminal/connection_tokens');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = json_decode($resp, true);
        if ($code === 200 && isset($data['secret'])) {
            $locationId = getSetting('stripe_terminal_location', '');
            echo json_encode(['secret' => $data['secret'], 'location_id' => $locationId]);
        } else {
            http_response_code(500); echo json_encode(['error' => $data['error']['message'] ?? 'Failed to get connection token']);
        }
        exit;

    // ── MAINTENANCE ──────────────────────────────────────────
    // POST endpoint=maintenance { "action": "run_all" }
    // Applies outstanding data fixes to all historical records.
    // ── REVIEWS ──────────────────────────────────────────────
    // GET  endpoint=reviews[&status=pending]
    // POST endpoint=reviews&action=approve&id=X
    // POST endpoint=reviews&action=reject&id=X
    case 'reviews':
        requireAuth();
        $db = getDB();
        if ($method === 'GET') {
            $status = sanitize($_GET['status'] ?? '');
            $where  = ['1=1']; $params = [];
            if ($status && in_array($status, ['pending','approved','rejected'])) {
                $where[] = 'r.status=?'; $params[] = $status;
            }
            $wc   = implode(' AND ', $where);
            $total = (int)$db->prepare("SELECT COUNT(*) FROM reviews r WHERE $wc")->execute($params) && 0; // placeholder
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM reviews r WHERE $wc");
            $cntStmt->execute($params); $total = (int)$cntStmt->fetchColumn();
            $stmt = $db->prepare("
                SELECT r.id, r.rating, r.review_text, r.status, r.submitted_at,
                       c.name as c_name,
                       s.name as s_name,
                       p.name as p_name
                FROM reviews r
                JOIN customers c ON c.id=r.customer_id
                LEFT JOIN services s ON s.id=r.service_id
                LEFT JOIN products p ON p.id=r.product_id
                WHERE $wc
                ORDER BY r.submitted_at DESC
                LIMIT 50
            ");
            $stmt->execute($params);
            echo json_encode(['reviews' => $stmt->fetchAll(), 'total' => $total]); exit;
        }
        if ($method === 'POST' && $id) {
            if ($action === 'approve') {
                $db->prepare("UPDATE reviews SET status='approved', approved_at=NOW() WHERE id=?")->execute([$id]);
                echo json_encode(['success' => true]); exit;
            }
            if ($action === 'reject') {
                $db->prepare("UPDATE reviews SET status='rejected' WHERE id=?")->execute([$id]);
                echo json_encode(['success' => true]); exit;
            }
        }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── CUSTOM REQUESTS ───────────────────────────────────────
    // GET  endpoint=custom_requests[&status=new]
    // POST endpoint=custom_requests&action=mark_viewed&id=X
    // POST endpoint=custom_requests&action=reply&id=X  { reply, status }
    case 'custom_requests':
        requireAuth();
        $db = getDB();
        if ($method === 'GET') {
            $status = sanitize($_GET['status'] ?? '');
            $where  = ['1=1']; $params = [];
            if ($status && in_array($status, ['new','viewed','replied','accepted','declined'])) {
                $where[] = 'status=?'; $params[] = $status;
            }
            $wc = implode(' AND ', $where);
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM custom_requests WHERE $wc");
            $cntStmt->execute($params); $total = (int)$cntStmt->fetchColumn();
            $stmt = $db->prepare("
                SELECT id, ref, name, email, phone, style_desc, hair_length,
                       preferred_date, budget_range, status, admin_reply, replied_at, created_at
                FROM custom_requests
                WHERE $wc
                ORDER BY FIELD(status,'new','viewed','replied','accepted','declined'), created_at DESC
                LIMIT 60
            ");
            $stmt->execute($params);
            echo json_encode(['requests' => $stmt->fetchAll(), 'total' => $total]); exit;
        }
        if ($method === 'POST' && $id) {
            if ($action === 'mark_viewed') {
                $db->prepare("UPDATE custom_requests SET status='viewed' WHERE id=? AND status='new'")->execute([$id]);
                echo json_encode(['success' => true]); exit;
            }
            if ($action === 'reply') {
                $reply      = trim($body['reply']  ?? '');
                $newStatus  = sanitize($body['status'] ?? 'replied');
                if (!in_array($newStatus, ['replied','accepted','declined'])) $newStatus = 'replied';
                if (!$reply) { http_response_code(400); echo json_encode(['error' => 'Reply text required']); exit; }
                $db->prepare("UPDATE custom_requests SET admin_reply=?, status=?, replied_at=NOW() WHERE id=?")->execute([$reply, $newStatus, $id]);
                // Send email reply to client
                try {
                    $req = $db->query("SELECT * FROM custom_requests WHERE id=$id")->fetch();
                    if ($req && !empty($req['email'])) {
                        require_once __DIR__ . '/../includes/mailer.php';
                        emailCustomRequestReply($req, $reply, $newStatus);
                    }
                } catch (Throwable $e) { error_log('custom_request reply email: '.$e->getMessage()); }
                echo json_encode(['success' => true]); exit;
            }
        }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    // ── DISCOUNTS ─────────────────────────────────────────────
    // GET  endpoint=discounts
    // POST endpoint=discounts&action=create  { code, type, value, uses_limit?, expiry_date? }
    // POST endpoint=discounts&action=toggle&id=X
    case 'discounts':
        requireAuth();
        $db = getDB();
        if ($method === 'GET') {
            $rows = $db->query("SELECT id, code, type, value, uses_limit, uses_count, expiry_date, is_active, created_at FROM discount_codes ORDER BY created_at DESC LIMIT 100")->fetchAll();
            echo json_encode(['discounts' => $rows]); exit;
        }
        if ($method === 'POST' && $action === 'create') {
            $code    = strtoupper(trim(sanitize($body['code'] ?? '')));
            $type    = in_array($body['type'] ?? '', ['percent','fixed']) ? $body['type'] : 'percent';
            $value   = (float)($body['value'] ?? 0);
            $limit   = isset($body['uses_limit']) && (int)$body['uses_limit'] > 0 ? (int)$body['uses_limit'] : null;
            $expiry  = isset($body['expiry_date']) && $body['expiry_date'] ? sanitize($body['expiry_date']) : null;
            if (!$code || $value <= 0) { http_response_code(400); echo json_encode(['error' => 'Code and value are required']); exit; }
            // Check uniqueness
            $exists = $db->prepare("SELECT id FROM discount_codes WHERE code=?");
            $exists->execute([$code]);
            if ($exists->fetch()) { http_response_code(409); echo json_encode(['error' => 'Code already exists']); exit; }
            $db->prepare("INSERT INTO discount_codes (code, type, value, uses_limit, expiry_date, is_active) VALUES (?,?,?,?,?,1)")->execute([$code, $type, $value, $limit, $expiry]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]); exit;
        }
        if ($method === 'POST' && $action === 'toggle' && $id) {
            $db->prepare("UPDATE discount_codes SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            $row = $db->prepare("SELECT is_active FROM discount_codes WHERE id=?");
            $row->execute([$id]); $isActive = (int)($row->fetchColumn() ?? 0);
            echo json_encode(['success' => true, 'is_active' => $isActive]); exit;
        }
        http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;

    case 'maintenance':
        requireAuth();
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }
        $db  = getDB();
        $act = sanitize($body['action'] ?? '');

        if ($act === 'run_all') {
            // 1. Auto-complete all past bookings that were never manually completed
            $stmt = $db->prepare("
                UPDATE bookings
                SET status = 'completed'
                WHERE status IN ('confirmed', 'pending')
                  AND booked_date < CURDATE()
            ");
            $stmt->execute();
            $completedCount = $stmt->rowCount();

            // NOTE: add-ons are per-service. Maintenance must NOT touch service_addons —
            // a previous version globalised them here on every run, which broke
            // per-service pricing. Left intentionally as a no-op for add-ons.

            echo json_encode([
                'success'          => true,
                'completed'        => $completedCount,
                'addons_converted' => 0
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown maintenance action']);
        }
        exit;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown endpoint']);
        exit;
}
