<?php
// ============================================================
// BraidedbyAGB — Core Helper Functions
// FILE: /includes/helpers.php
// ============================================================

// ── Session ──────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Security ──────────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function sanitizeEmail(string $email): string {
    return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function verifyRecaptcha(string $token): bool {
    if (empty($token)) return false;
    $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?' . http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]));
    if (!$response) return false;
    $data = json_decode($response, true);
    if (empty($data['success'])) return false;
    // v3 returns a score (0.0–1.0); require 0.5+ to block bots
    if (isset($data['score'])) return $data['score'] >= 0.5;
    return true;
}

function generateToken(int $length = 64): string {
    return bin2hex(random_bytes($length / 2));
}

function generateBookingRef(): string {
    $year = date('Y');
    $db   = getDB();
    // Use MAX(id) not COUNT(*) to avoid collisions after deletions
    $stmt = $db->query("SELECT COALESCE(MAX(id),0) FROM bookings");
    $next = (int)$stmt->fetchColumn() + 1;
    // Add microsecond suffix to guarantee uniqueness under concurrency
    $suffix = substr(str_replace('.', '', microtime(true)), -3);
    return 'AGB' . $year . str_pad($next, 5, '0', STR_PAD_LEFT) . $suffix;
}

function generateOrderRef(): string {
    $db   = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM orders");
    $count = (int)$stmt->fetchColumn() + 1;
    return 'AGB-ORD-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(32);
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

// ── Formatting ────────────────────────────────────────────
function formatPrice(float $amount, string $currency = '£'): string {
    return $currency . number_format($amount, 2);
}

function formatDate(string $date, string $format = 'l, j F Y'): string {
    // Use createFromFormat so we never rely on strtotime's timezone-ambiguous
    // handling of bare ISO date strings.  MySQL DATE columns always return
    // 'YYYY-MM-DD'; DATETIME columns return 'YYYY-MM-DD HH:MM:SS' — handle both.
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date)
       ?: DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
    return $dt ? $dt->format($format) : $date;
}

function formatTime(string $time): string {
    return date('g:i A', strtotime($time));
}

function formatDuration(int $minutes): string {
    if ($minutes <= 0) return '0m';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) return $m . 'm';
    if ($m === 0) return $h . 'h';
    return $h . 'h ' . $m . 'm';
}

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

// ── Response helpers ──────────────────────────────────────
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// ── Settings ──────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        $cache[$key] = $row ? (string)$row['setting_value'] : $default;
    }
    return $cache[$key];
}

function setSetting(string $key, string $value): void {
    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

// ── Booking helpers ───────────────────────────────────────
function calculateDeposit(float $total): float {
    $pct = (float)getSetting('deposit_percent', '30');
    return round($total * ($pct / 100), 2);
}

function calculateRemainingBalance(float $total, float $deposit): float {
    return round($total - $deposit, 2);
}

/**
 * salonAddress — the public salon address, shown across the site.
 * Editable via the `business_address` setting; falls back to the studio address.
 */
function salonAddress(): string {
    $a = trim(getSetting('business_address', ''));
    return $a !== '' ? $a : 'Unit 4, Selnews Business Centre, Peabody Road, Farnborough, GU14 6GX';
}

/**
 * decrementProductStock — reduce stock for a purchased line item.
 *
 * Stock lives only on product_variants.stock_qty, so it must be decremented by
 * the specific variant id. Passing a variant id targets exactly that variant.
 * When no variant is recorded (variant_id NULL) we fall back to the product,
 * which is only unambiguous for single-variant products — a customer always
 * picks a variant for a multi-variant product, so NULL never occurs there.
 *
 * @param int      $productId Product id (fallback target when $variantId is null)
 * @param int|null $variantId Chosen variant id, or null
 * @param int      $qty       Quantity purchased
 */
function decrementProductStock(PDO $db, int $productId, ?int $variantId, int $qty): void {
    if ($qty < 1) return;
    if ($variantId) {
        $db->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?")
           ->execute([$qty, $variantId]);
    } else {
        $db->prepare("UPDATE product_variants SET stock_qty = GREATEST(0, stock_qty - ?) WHERE product_id = ?")
           ->execute([$qty, $productId]);
    }
}

/**
 * isSlotAvailable — duration-aware overlap check.
 *
 * Returns false if:
 *  - The date is fully blocked in availability
 *  - The specific time_slot is blocked in availability
 *  - Any existing booking's window overlaps [date+time, date+time+newDurMins)
 *
 * @param string $date        'Y-m-d'
 * @param string $time        'H:i' or 'H:i:s'
 * @param int    $newDurMins  Duration of the NEW booking (defaults to 60 if 0)
 */
function isSlotAvailable(string $date, string $time, int $newDurMins = 60): bool {
    $db = getDB();
    if ($newDurMins < 1) $newDurMins = 60;

    // 1. Full-day block
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM availability
        WHERE avail_date = ? AND is_blocked = 1 AND time_slot IS NULL
    ");
    $stmt->execute([$date]);
    if ((int)$stmt->fetchColumn() > 0) return false;

    // 2. Specific time-slot block
    $timeH = substr($time, 0, 5); // normalise to HH:MM
    $stmt  = $db->prepare("
        SELECT COUNT(*) FROM availability
        WHERE avail_date = ? AND is_blocked = 1
          AND TIME_FORMAT(time_slot,'%H:%i') = ?
    ");
    $stmt->execute([$date, $timeH]);
    if ((int)$stmt->fetchColumn() > 0) return false;

    // 3. Duration-aware overlap against existing bookings
    //    New window: [newStart, newStart + newDurMins)
    //    Existing:   [bs,       bs + existDurMins)
    //    Overlap if: newStart < bs + existDurMins  AND  newStart + newDurMins > bs
    $newStart    = strtotime($date . ' ' . $time);
    $newEnd      = $newStart + $newDurMins * 60;

    // Defensive try-catch: if duration_mins column hasn't been migrated yet on live DB,
    // fall back to variant/service duration so existing bookings still block slots.
    try {
        $stmt = $db->prepare("
            SELECT b.booked_time,
                   COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
            FROM bookings b
            JOIN services s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // duration_mins column not yet added — fall back to variant/service duration only
        $stmt = $db->prepare("
            SELECT b.booked_time,
                   COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
            FROM bookings b
            JOIN services s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
    }
    foreach ($rows as $row) {
        $bs  = strtotime($date . ' ' . $row['booked_time']);
        $be  = $bs + (int)$row['dur'] * 60;
        if ($newStart < $be && $newEnd > $bs) return false;
    }

    return true;
}

// ── Pipeline helpers ──────────────────────────────────────
function getLinkedProducts(int $serviceId): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT p.*, spl.display_label, spl.display_order,
               pv.colour, pv.stock_qty, pv.id as variant_id
        FROM service_product_links spl
        JOIN products p ON p.id = spl.product_id
        LEFT JOIN product_variants pv ON pv.product_id = p.id
        WHERE spl.service_id = ?
          AND spl.is_active = 1
          AND p.is_active = 1
        ORDER BY spl.display_order ASC
    ");
    $stmt->execute([$serviceId]);
    return $stmt->fetchAll();
}

// ── Review helpers ────────────────────────────────────────
function getAverageRating(?int $serviceId = null, ?int $productId = null): array {
    $db   = getDB();
    $where = "status = 'approved'";
    $params = [];
    if ($serviceId)  { $where .= " AND service_id = ?"; $params[] = $serviceId; }
    if ($productId)  { $where .= " AND product_id = ?"; $params[] = $productId; }
    $stmt = $db->prepare("
        SELECT AVG(rating) as avg, COUNT(*) as total
        FROM reviews WHERE $where
    ");
    $stmt->execute($params);
    $row = $stmt->fetch();
    return [
        'average' => round((float)($row['avg'] ?? 0), 1),
        'total'   => (int)($row['total'] ?? 0),
    ];
}

function renderStars(float $rating, bool $showNumber = true): string {
    $html = '<span class="star-rating" aria-label="' . $rating . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $class = $i <= round($rating) ? 'star' : 'star empty';
        $html .= '<span class="' . $class . '">★</span>';
    }
    $html .= '</span>';
    if ($showNumber) {
        $html .= ' <span class="rating-number">' . number_format($rating, 1) . '</span>';
    }
    return $html;
}

// ── Loyalty helpers ───────────────────────────────────────

/**
 * Adjust a customer's loyalty points and log the transaction.
 * $delta: positive = add, negative = remove.
 */
function adjustLoyaltyPoints(int $customerId, int $delta, string $type, string $description, ?int $bookingId = null): void {
    $db = getDB();
    // Update balance (floor at 0)
    $db->prepare("
        UPDATE customers
        SET loyalty_points = GREATEST(0, loyalty_points + ?)
        WHERE id = ?
    ")->execute([$delta, $customerId]);
    // Log transaction
    $db->prepare("
        INSERT INTO loyalty_transactions (customer_id, booking_id, type, points, description)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$customerId, $bookingId, $type, $delta, $description]);
}

/**
 * Award loyalty points when a booking is marked completed.
 * Rate: 1 point per £1 (configurable via loyalty_earn_rate setting).
 */
function awardLoyaltyPoints(int $bookingId): void {
    if (!getSetting('loyalty_enabled', '1')) return;

    $db   = getDB();
    $stmt = $db->prepare("SELECT customer_id, total_price FROM bookings WHERE id=?");
    $stmt->execute([$bookingId]);
    $bk = $stmt->fetch();
    if (!$bk) return;

    $rate   = max(1, (int)getSetting('loyalty_earn_rate', '1'));
    $points = (int)floor((float)$bk['total_price'] * $rate);
    if ($points <= 0) return;

    adjustLoyaltyPoints(
        (int)$bk['customer_id'],
        $points,
        'earn',
        'Booking completed',
        $bookingId
    );
}

// ── Accounting helpers ────────────────────────────────────

/**
 * Create a balanced journal entry with one or more debit/credit lines.
 * $lines: [['account_code' => '5100', 'debit' => 50.00, 'credit' => 0, 'memo' => ''], ...]
 */
function createJournalEntry(string $date, string $description, string $source, ?int $sourceId, array $lines, string $reference = ''): int {
    $db = getDB();
    $db->prepare("
        INSERT INTO journal_entries (entry_date, description, reference, source, source_id)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$date, $description, $reference, $source, $sourceId]);
    $entryId = (int)$db->lastInsertId();

    $acctStmt = $db->prepare("SELECT id FROM accounts WHERE code=? LIMIT 1");
    $lineStmt  = $db->prepare("
        INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, memo)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($lines as $line) {
        $acctStmt->execute([$line['account_code']]);
        $accountId = (int)($acctStmt->fetchColumn() ?: 0);
        if (!$accountId) continue;
        $lineStmt->execute([
            $entryId,
            $accountId,
            (float)($line['debit']  ?? 0),
            (float)($line['credit'] ?? 0),
            $line['memo'] ?? '',
        ]);
    }
    return $entryId;
}

/**
 * Create the journal entry for a logged expense.
 * DR 5100 Business Expenses / CR 1020 Bank Account
 */
function journalExpense(int $expenseId, float $amount, string $date, string $description): void {
    createJournalEntry($date, $description, 'expense', $expenseId, [
        ['account_code' => '5100', 'debit' => $amount, 'credit' => 0,       'memo' => $description],
        ['account_code' => '1020', 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash/Bank'],
    ]);
}

/**
 * Create the journal entry for an owner draw.
 * DR 5200 Owner's Draw / CR 1020 Bank Account
 */
function journalOwnerDraw(int $drawId, float $amount, string $date): void {
    createJournalEntry($date, "Owner's draw", 'owner_draw', $drawId, [
        ['account_code' => '5200', 'debit' => $amount, 'credit' => 0,       'memo' => "Owner's draw"],
        ['account_code' => '1020', 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash/Bank'],
    ]);
}

// ── Admin session helpers live in includes/auth.php ───────
// requireAdmin() and isAdmin() are defined there to avoid
// redeclaration when auth.php is included on admin pages.

// ── Image helper ──────────────────────────────────────────
function uploadImage(array $file, string $folder): string|false {
    $allowed = ['image/jpeg','image/png','image/webp'];
    if (!in_array($file['type'], $allowed)) return false;
    if ($file['size'] > 5 * 1024 * 1024) return false;  // 5MB limit

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = generateToken(16) . '.' . strtolower($ext);
    $dir      = __DIR__ . '/../uploads/' . $folder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $dest = $dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return '/uploads/' . $folder . '/' . $filename;
    }
    return false;
}
