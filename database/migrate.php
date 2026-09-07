<?php
// ============================================================
// BraidedbyAGB — Database Migration Runner
// FILE: /database/migrate.php
//
// Replaces hand-pasting SQL into phpMyAdmin. Runs all pending schema
// changes; each step checks whether it has already been applied, so the
// script is SAFE TO RE-RUN as many times as you like.
//
// USAGE
//   Web: https://braidedbyagb.co.uk/database/migrate.php?key=YOUR_SECRET
//   CLI: php database/migrate.php
//
// SETUP (once): change MIGRATE_KEY below to your own secret. The script
//   refuses to run over the web until you do.
//
// After a successful run you may delete this file (optional — it's harmless
// to leave because it never re-applies a migration that's already in place).
// ============================================================

require_once __DIR__ . '/../config/database.php';

// ── Access guard ──────────────────────────────────────────
// Preferred: define MIGRATE_KEY in config/database.php (server-only, NOT in git,
// so it survives every deploy). The fallback below is only used if config does
// not define it — and the script refuses to run while it's still the placeholder.
if (!defined('MIGRATE_KEY')) {
    define('MIGRATE_KEY', 'CHANGE-ME-to-a-long-random-secret');
}

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (MIGRATE_KEY === 'CHANGE-ME-to-a-long-random-secret') {
        http_response_code(403);
        exit("Refusing to run: open database/migrate.php and set a unique MIGRATE_KEY first.\n");
    }
    if (!hash_equals(MIGRATE_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden — missing or wrong ?key=\n");
    }
}

$db     = getDB();
$dbName = DB_NAME;

// ── Idempotency helpers (work on both MySQL and MariaDB) ──
function columnExists(PDO $db, string $schema, string $table, string $col): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$schema, $table, $col]);
    return (int)$s->fetchColumn() > 0;
}
function indexExists(PDO $db, string $schema, string $table, string $idx): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$schema, $table, $idx]);
    return (int)$s->fetchColumn() > 0;
}
function tableExists(PDO $db, string $schema, string $table): bool {
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $s->execute([$schema, $table]);
    return (int)$s->fetchColumn() > 0;
}
function enumHasValue(PDO $db, string $schema, string $table, string $col, string $value): bool {
    $s = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$schema, $table, $col]);
    $type = (string)$s->fetchColumn();
    return stripos($type, "'" . $value . "'") !== false;
}
function columnType(PDO $db, string $schema, string $table, string $col): string {
    $s = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$schema, $table, $col]);
    return strtolower((string)$s->fetchColumn());
}
function isNullable(PDO $db, string $schema, string $table, string $col): bool {
    $s = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$schema, $table, $col]);
    return strtoupper((string)$s->fetchColumn()) === 'YES';
}

$report = [];
function step(string $label, callable $alreadyApplied, callable $apply, array &$report): void {
    try {
        if ($alreadyApplied()) { $report[] = "SKIP   {$label}  (already applied)"; return; }
        $apply();
        $report[] = "OK     {$label}";
    } catch (Throwable $e) {
        $report[] = "ERROR  {$label}  —  " . $e->getMessage();
    }
}

// ============================================================
// MIGRATIONS  (add new steps to the bottom over time)
//
// This runner is the SINGLE SOURCE OF TRUTH for schema changes. Every step
// is guarded so it is safe to re-run; on an up-to-date database each one
// reports SKIP. The historical section below was ported from the old
// hand-run migrations.sql so this file alone can bring a fresh database
// (built from schema.sql) fully up to date.
// ============================================================

// ── Historical schema (ported from migrations.sql) ────────
// Cron/reminder tracking flags.
step('bookings.reminder_24_sent column',
    fn() => columnExists($db, $dbName, 'bookings', 'reminder_24_sent'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN reminder_24_sent TINYINT(1) DEFAULT 0"),
    $report);
step('bookings.reminder_2_sent column',
    fn() => columnExists($db, $dbName, 'bookings', 'reminder_2_sent'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN reminder_2_sent TINYINT(1) DEFAULT 0"),
    $report);
step('bookings.review_request_sent column',
    fn() => columnExists($db, $dbName, 'bookings', 'review_request_sent'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN review_request_sent TINYINT(1) DEFAULT 0"),
    $report);
step('bookings.admin_reminder_30_sent column',
    fn() => columnExists($db, $dbName, 'bookings', 'admin_reminder_30_sent'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN admin_reminder_30_sent TINYINT(1) DEFAULT 0"),
    $report);
step('orders.review_request_sent column',
    fn() => columnExists($db, $dbName, 'orders', 'review_request_sent'),
    fn() => $db->exec("ALTER TABLE orders ADD COLUMN review_request_sent TINYINT(1) DEFAULT 0"),
    $report);
step('orders.from_pipeline column',
    fn() => columnExists($db, $dbName, 'orders', 'from_pipeline'),
    fn() => $db->exec("ALTER TABLE orders ADD COLUMN from_pipeline TINYINT(1) DEFAULT 0"),
    $report);
step('product_variants.low_stock_alerted_at column',
    fn() => columnExists($db, $dbName, 'product_variants', 'low_stock_alerted_at'),
    fn() => $db->exec("ALTER TABLE product_variants ADD COLUMN low_stock_alerted_at DATETIME DEFAULT NULL"),
    $report);

// Receipt + admin payment-link columns.
step('bookings.receipt_url column',
    fn() => columnExists($db, $dbName, 'bookings', 'receipt_url'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN receipt_url VARCHAR(255) DEFAULT NULL"),
    $report);
step('bookings.payment_token column',
    fn() => columnExists($db, $dbName, 'bookings', 'payment_token'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN payment_token VARCHAR(64) DEFAULT NULL"),
    $report);
step('bookings.payment_method_allowed column',
    fn() => columnExists($db, $dbName, 'bookings', 'payment_method_allowed'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN payment_method_allowed ENUM('stripe','bank_transfer','both') DEFAULT 'both'"),
    $report);

// booking_ref widened to VARCHAR(20); availability.time_slot made nullable.
step('bookings.booking_ref widened to VARCHAR(20)',
    fn() => str_starts_with(columnType($db, $dbName, 'bookings', 'booking_ref'), 'varchar(20)'),
    fn() => $db->exec("ALTER TABLE bookings MODIFY COLUMN booking_ref VARCHAR(20) NOT NULL"),
    $report);
step('availability.time_slot nullable',
    fn() => isNullable($db, $dbName, 'availability', 'time_slot'),
    fn() => $db->exec("ALTER TABLE availability MODIFY COLUMN time_slot TIME DEFAULT NULL"),
    $report);

// Booking duration override + custom-style bookings.
step('bookings.duration_mins column',
    fn() => columnExists($db, $dbName, 'bookings', 'duration_mins'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN duration_mins INT DEFAULT NULL"),
    $report);
step('bookings.custom_style_name column',
    fn() => columnExists($db, $dbName, 'bookings', 'custom_style_name'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN custom_style_name VARCHAR(255) DEFAULT NULL"),
    $report);
step('bookings.custom_style_desc column',
    fn() => columnExists($db, $dbName, 'bookings', 'custom_style_desc'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN custom_style_desc TEXT DEFAULT NULL"),
    $report);
step('services "Custom Style" sentinel row',
    fn() => (int)$db->query("SELECT COUNT(*) FROM services WHERE name='Custom Style'")->fetchColumn() > 0,
    fn() => $db->exec("INSERT INTO services (name, slug, description, price_from, duration_mins, is_active, display_order)
                       VALUES ('Custom Style','custom-style','Admin-created custom style booking',0,60,0,9999)"),
    $report);

// CRM columns on customers.
step('customers.loyalty_points column',
    fn() => columnExists($db, $dbName, 'customers', 'loyalty_points'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN loyalty_points INT DEFAULT 0"),
    $report);
step('customers.tags column',
    fn() => columnExists($db, $dbName, 'customers', 'tags'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN tags VARCHAR(255) DEFAULT NULL"),
    $report);
step('customers.is_blocked column',
    fn() => columnExists($db, $dbName, 'customers', 'is_blocked'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN is_blocked TINYINT(1) DEFAULT 0"),
    $report);
step('customers.block_reason column',
    fn() => columnExists($db, $dbName, 'customers', 'block_reason'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN block_reason TEXT DEFAULT NULL"),
    $report);
step('customers.blocked_at column',
    fn() => columnExists($db, $dbName, 'customers', 'blocked_at'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN blocked_at DATETIME DEFAULT NULL"),
    $report);
step('customers.hair_notes column',
    fn() => columnExists($db, $dbName, 'customers', 'hair_notes'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN hair_notes TEXT DEFAULT NULL"),
    $report);

// Loyalty / archive columns on bookings.
step('bookings.loyalty_points_redeemed column',
    fn() => columnExists($db, $dbName, 'bookings', 'loyalty_points_redeemed'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN loyalty_points_redeemed INT DEFAULT 0"),
    $report);
step('bookings.loyalty_discount column',
    fn() => columnExists($db, $dbName, 'bookings', 'loyalty_discount'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN loyalty_discount DECIMAL(10,2) DEFAULT 0.00"),
    $report);
step('bookings.is_archived column',
    fn() => columnExists($db, $dbName, 'bookings', 'is_archived'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN is_archived TINYINT(1) DEFAULT 0"),
    $report);

// Auth + CRM + accounting tables (order matters for foreign keys).
step('admin_tokens table',
    fn() => tableExists($db, $dbName, 'admin_tokens'),
    fn() => $db->exec("CREATE TABLE admin_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"),
    $report);
step('loyalty_transactions table',
    fn() => tableExists($db, $dbName, 'loyalty_transactions'),
    fn() => $db->exec("CREATE TABLE loyalty_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        booking_id INT DEFAULT NULL,
        type ENUM('earn','redeem','manual_add','manual_remove','expire') NOT NULL,
        points INT NOT NULL,
        description VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
    )"),
    $report);
step('customer_notes table',
    fn() => tableExists($db, $dbName, 'customer_notes'),
    fn() => $db->exec("CREATE TABLE customer_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    )"),
    $report);
step('accounts table',
    fn() => tableExists($db, $dbName, 'accounts'),
    fn() => $db->exec("CREATE TABLE accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(10) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        type ENUM('asset','liability','equity','income','expense') NOT NULL,
        is_active TINYINT(1) DEFAULT 1
    )"),
    $report);
step('accounts chart-of-accounts seed',
    fn() => (int)$db->query("SELECT COUNT(*) FROM accounts")->fetchColumn() > 0,
    fn() => $db->exec("INSERT IGNORE INTO accounts (code, name, type) VALUES
        ('1000','Stripe Account','asset'),
        ('1020','Bank Account','asset'),
        ('2000','Customer Deposits Held','liability'),
        ('4000','Service Revenue','income'),
        ('4020','Late Cancellation Fees','income'),
        ('5000','Cost of Sales','expense'),
        ('5100','Business Expenses','expense'),
        ('5200','Owner''s Draw','expense')"),
    $report);
step('journal_entries table',
    fn() => tableExists($db, $dbName, 'journal_entries'),
    fn() => $db->exec("CREATE TABLE journal_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        description VARCHAR(255) NOT NULL,
        reference VARCHAR(50) DEFAULT NULL,
        source ENUM('booking_payment','expense','owner_draw','manual') NOT NULL,
        source_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"),
    $report);
step('journal_entry_lines table',
    fn() => tableExists($db, $dbName, 'journal_entry_lines'),
    fn() => $db->exec("CREATE TABLE journal_entry_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        journal_entry_id INT NOT NULL,
        account_id INT NOT NULL,
        debit DECIMAL(10,2) DEFAULT 0.00,
        credit DECIMAL(10,2) DEFAULT 0.00,
        memo VARCHAR(255) DEFAULT NULL,
        FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
        FOREIGN KEY (account_id) REFERENCES accounts(id)
    )"),
    $report);
step('expenses table',
    fn() => tableExists($db, $dbName, 'expenses'),
    fn() => $db->exec("CREATE TABLE expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        category VARCHAR(100) DEFAULT 'Business Expenses',
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"),
    $report);
step('owner_draws table',
    fn() => tableExists($db, $dbName, 'owner_draws'),
    fn() => $db->exec("CREATE TABLE owner_draws (
        id INT AUTO_INCREMENT PRIMARY KEY,
        draw_date DATE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )"),
    $report);

// Loyalty settings seed.
step('settings loyalty rows',
    fn() => (function() use ($db) {
        $s = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='loyalty_enabled'");
        $s->execute();
        return (int)$s->fetchColumn() > 0;
    })(),
    fn() => $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('loyalty_enabled','1'),
        ('loyalty_earn_rate','1'),
        ('loyalty_redeem_rate','100'),
        ('loyalty_min_redeem','500')"),
    $report);

// One-off backfill: complete bookings whose date has already passed.
// Guard skips once none remain, so re-running is a no-op.
step('backfill past bookings to completed',
    fn() => (int)$db->query("SELECT COUNT(*) FROM bookings
                             WHERE status IN ('confirmed','pending') AND booked_date < CURDATE()")->fetchColumn() === 0,
    fn() => $db->exec("UPDATE bookings SET status='completed'
                       WHERE status IN ('confirmed','pending') AND booked_date < CURDATE()"),
    $report);

// ── Multi-booking cart ────────────────────────────────────
step('bookings.guest_name column',
    fn() => columnExists($db, $dbName, 'bookings', 'guest_name'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN guest_name VARCHAR(120) DEFAULT NULL AFTER customer_id"),
    $report);

step('bookings.cart_group_ref column',
    fn() => columnExists($db, $dbName, 'bookings', 'cart_group_ref'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN cart_group_ref VARCHAR(24) DEFAULT NULL AFTER booking_ref"),
    $report);

step('bookings.idx_cart_group index',
    fn() => indexExists($db, $dbName, 'bookings', 'idx_cart_group'),
    fn() => $db->exec("ALTER TABLE bookings ADD INDEX idx_cart_group (cart_group_ref)"),
    $report);

// ── Review fix: status ENUM must include 'cancelled' ──────
step("bookings.status ENUM includes 'cancelled'",
    fn() => enumHasValue($db, $dbName, 'bookings', 'status', 'cancelled'),
    fn() => $db->exec("ALTER TABLE bookings MODIFY COLUMN status
                       ENUM('pending','confirmed','cancelled','rejected','completed','no_show','late_cancelled')
                       DEFAULT 'pending'"),
    $report);

// ── Business address setting (seed empty row if missing) ──
step("settings.business_address row",
    fn() => (function() use ($db) {
        $s = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='business_address'");
        $s->execute();
        return (int)$s->fetchColumn() > 0;
    })(),
    fn() => $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('business_address','')"),
    $report);

// ── Revert add-ons to a per-service model ─────────────────
// A previous change converted every add-on to "global" (service_id = NULL,
// is_global = 1) plus a service_addon_exclusions table. That made add-on
// prices un-editable per service. We revert to per-service add-ons: each
// global add-on is copied onto every active, non-excluded service, then the
// global originals are retired (kept — not deleted — so historical
// booking_addons foreign keys stay intact).
//
// The first three steps just guarantee the columns/table exist so the
// explode query is valid even on a DB that never ran the old global migration.

step('service_addons.is_global column (ensure exists)',
    fn() => columnExists($db, $dbName, 'service_addons', 'is_global'),
    fn() => $db->exec("ALTER TABLE service_addons ADD COLUMN is_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"),
    $report);

step('service_addons.service_id nullable (for retired globals)',
    fn() => (function() use ($db, $dbName) {
        $s = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=? AND TABLE_NAME='service_addons' AND COLUMN_NAME='service_id'");
        $s->execute([$dbName]);
        return strtoupper((string)$s->fetchColumn()) === 'YES';
    })(),
    fn() => $db->exec("ALTER TABLE service_addons MODIFY COLUMN service_id INT UNSIGNED NULL DEFAULT NULL"),
    $report);

step('service_addon_exclusions table (ensure exists)',
    fn() => tableExists($db, $dbName, 'service_addon_exclusions'),
    fn() => $db->exec("CREATE TABLE service_addon_exclusions (
        service_id INT NOT NULL,
        addon_id   INT NOT NULL,
        PRIMARY KEY (service_id, addon_id)
    )"),
    $report);

step('Explode global add-ons into per-service rows, then retire globals',
    // Already applied once no global add-ons remain.
    fn() => (int)$db->query("SELECT COUNT(*) FROM service_addons WHERE is_global = 1")->fetchColumn() === 0,
    function() use ($db) {
        $db->beginTransaction();
        try {
            $globals  = $db->query("SELECT id, name, price, is_active FROM service_addons WHERE is_global = 1")->fetchAll();
            $services = $db->query("SELECT id FROM services WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);

            // exclusions[service_id][addon_id] = true
            $exc = [];
            foreach ($db->query("SELECT service_id, addon_id FROM service_addon_exclusions")->fetchAll() as $r) {
                $exc[(int)$r['service_id']][(int)$r['addon_id']] = true;
            }
            // existing per-service add-on names so we never duplicate one
            $existing = [];
            foreach ($db->query("SELECT service_id, name FROM service_addons WHERE service_id IS NOT NULL")->fetchAll() as $r) {
                $existing[(int)$r['service_id']][$r['name']] = true;
            }

            $ins = $db->prepare("INSERT INTO service_addons (service_id, name, price, is_active, is_global) VALUES (?,?,?,?,0)");
            foreach ($globals as $g) {
                foreach ($services as $sid) {
                    $sid = (int)$sid;
                    if (isset($exc[$sid][(int)$g['id']]))   continue;  // service had this global excluded
                    if (isset($existing[$sid][$g['name']])) continue;  // a per-service add-on with this name already exists
                    $ins->execute([$sid, $g['name'], $g['price'], (int)$g['is_active']]);
                    $existing[$sid][$g['name']] = true;
                }
            }
            // Retire the global originals: hide them and clear the global flag.
            // Kept (not deleted) so booking_addons rows that reference them remain valid.
            $db->exec("UPDATE service_addons SET is_active = 0, is_global = 0 WHERE is_global = 1");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    },
    $report);

// ── Discount codes: standardise on uses_count ─────────────
// The app counts redemptions in discount_codes.uses_count (see schema.sql).
// Some older databases were built with a legacy `times_used` column, which
// made coupon validation fail. Ensure uses_count exists and carry over any
// legacy counts.
step('discount_codes.uses_count column (ensure exists)',
    fn() => columnExists($db, $dbName, 'discount_codes', 'uses_count'),
    function() use ($db, $dbName) {
        $db->exec("ALTER TABLE discount_codes ADD COLUMN uses_count INT UNSIGNED NOT NULL DEFAULT 0");
        if (columnExists($db, $dbName, 'discount_codes', 'times_used')) {
            $db->exec("UPDATE discount_codes SET uses_count = times_used");
        }
    },
    $report);

// ── Home service + media consent columns on bookings ──────
// These back the home-visit booking flow (location, travel area/fee,
// address) and the per-appointment photo/video consent captured at
// checkout. WITHOUT them the cart booking INSERT (api/index.php) writes to
// columns that don't exist, so EVERY booking is rejected with
// "Your booking could not be saved" — on every bank and payment method.
// Column position is irrelevant to the app, so no AFTER clause is used
// (keeps each step independent of the others' order).
step('bookings.service_location column',
    fn() => columnExists($db, $dbName, 'bookings', 'service_location'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN service_location VARCHAR(20) NOT NULL DEFAULT 'salon'"),
    $report);

step('bookings.travel_area column',
    fn() => columnExists($db, $dbName, 'bookings', 'travel_area'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN travel_area VARCHAR(80) DEFAULT NULL"),
    $report);

step('bookings.travel_fee column',
    fn() => columnExists($db, $dbName, 'bookings', 'travel_fee'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN travel_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00"),
    $report);

step('bookings.service_address column',
    fn() => columnExists($db, $dbName, 'bookings', 'service_address'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN service_address TEXT DEFAULT NULL"),
    $report);

step('bookings.media_consent column',
    fn() => columnExists($db, $dbName, 'bookings', 'media_consent'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN media_consent VARCHAR(20) NOT NULL DEFAULT 'none'"),
    $report);

// ── Home service settings (seed defaults if missing) ──────
// So the admin Home Service panel and the server-side travel-fee/£70-gate
// validation read real values instead of relying only on code fallbacks.
step('settings home-service rows',
    fn() => (function() use ($db) {
        $s = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='home_service_min'");
        $s->execute();
        return (int)$s->fetchColumn() > 0;
    })(),
    fn() => $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('home_service_min','70'),
        ('travel_fee_farnborough','25'),
        ('travel_fee_camberley_aldershot','30'),
        ('travel_fee_further','45')"),
    $report);

// ============================================================
// OUTPUT
// ============================================================
$applied = count(array_filter($report, fn($l) => str_starts_with($l, 'OK')));
$errors  = count(array_filter($report, fn($l) => str_starts_with($l, 'ERROR')));

$out  = "BraidedbyAGB — database migration\n";
$out .= date('Y-m-d H:i:s') . "   database: {$dbName}\n";
$out .= str_repeat('-', 56) . "\n";
$out .= implode("\n", $report) . "\n";
$out .= str_repeat('-', 56) . "\n";
$out .= "{$applied} applied, " . (count($report) - $applied - $errors) . " skipped, {$errors} error(s).\n";
$out .= $errors === 0 ? "All migrations are up to date. ✓\n" : "Some migrations failed — see ERROR lines above.\n";

echo $out;
