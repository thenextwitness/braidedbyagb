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
//   AUTOMATIC (since 20 Nov 2026): after every deploy, the server runs this
//   file by itself IF it changed in that deploy (a database backup is taken
//   first). The deploy workflow waits for the result and fails if any step
//   reports ERROR. So: add a guarded step() at the bottom, push, done.
//   Because a run re-checks EVERY step, data-fixing steps (e.g. "backfill past
//   bookings to completed") also run whenever this file changes: keep every
//   step's "already applied" check exact.
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

// ── Missing cash account code (Phase 0 / bug B3) ──────────
// api/admin.php posts booking completions and Terminal payments to code
// '1010', which was never in the chart-of-accounts seed. createJournalEntry()
// silently skipped the unknown code, so every cash-completed booking wrote an
// entry with its debit leg missing. Seed the code; the helper now throws on an
// unknown code instead of dropping the line.
step('accounts 1010 Cash on Hand',
    fn() => (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code='1010'")->fetchColumn() > 0,
    fn() => $db->exec("INSERT IGNORE INTO accounts (code, name, type) VALUES ('1010','Cash on Hand','asset')"),
    $report);

// ── Phase R: booking completion & revenue recognition ─────
// A booking is income only once the owner marks it completed. Anything left
// unmarked is auto-marked 'incomplete' (not successful, fees not taken).
step("bookings.status ENUM includes 'incomplete'",
    fn() => enumHasValue($db, $dbName, 'bookings', 'status', 'incomplete'),
    fn() => $db->exec("ALTER TABLE bookings MODIFY COLUMN status
                       ENUM('pending','confirmed','cancelled','rejected','completed','no_show','late_cancelled','incomplete')
                       DEFAULT 'pending'"),
    $report);

// Distinct journal sources for the booking money lifecycle, so each event is
// idempotent (query by source+source_id) and reversible without deleting rows:
//   booking_deposit  — deposit received, held as a liability (CR 2000)
//   booking_payment  — revenue recognised on completion (CR 4000)
//   booking_forfeit  — deposit forfeited on incomplete (CR 4020)
//   booking_reversal — mirror entry backing out a prior booking entry
step("journal_entries.source includes booking lifecycle values",
    fn() => enumHasValue($db, $dbName, 'journal_entries', 'source', 'booking_deposit'),
    fn() => $db->exec("ALTER TABLE journal_entries MODIFY COLUMN source
                       ENUM('booking_payment','expense','owner_draw','manual',
                            'booking_deposit','booking_forfeit','booking_reversal') NOT NULL"),
    $report);

// ── payments.method must allow 'stripe_terminal' (bug B5) ──
// api/admin.php records Tap-to-Pay takings with method='stripe_terminal',
// a value the ENUM never contained — it throws under strict mode and
// silently coerces to '' otherwise. Keeping it distinct from 'stripe' is
// worth it: card-present and online takings reconcile separately.
step("payments.method ENUM includes 'stripe_terminal'",
    fn() => enumHasValue($db, $dbName, 'payments', 'method', 'stripe_terminal'),
    fn() => $db->exec("ALTER TABLE payments MODIFY COLUMN method
                       ENUM('stripe','bank_transfer','stripe_terminal') NOT NULL"),
    $report);

// ── Phase A: customer accounts / one login ────────────────
// Auth + profile columns on customers. password_hash NULL = passwordless
// (emailed-code) account until the customer opts into a password.
step('customers.password_hash column',
    fn() => columnExists($db, $dbName, 'customers', 'password_hash'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL"),
    $report);
step('customers.email_verified column',
    fn() => columnExists($db, $dbName, 'customers', 'email_verified'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0"),
    $report);
step('customers.last_login_at column',
    fn() => columnExists($db, $dbName, 'customers', 'last_login_at'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN last_login_at DATETIME DEFAULT NULL"),
    $report);
step('customers.login_attempts column',
    fn() => columnExists($db, $dbName, 'customers', 'login_attempts'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN login_attempts INT UNSIGNED NOT NULL DEFAULT 0"),
    $report);
step('customers.locked_until column',
    fn() => columnExists($db, $dbName, 'customers', 'locked_until'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN locked_until DATETIME DEFAULT NULL"),
    $report);
// Saved address, so a logged-in client never re-types it. Flat columns (one
// address per client) — a separate table would be over-engineering here.
foreach ([
    'address_line1' => "VARCHAR(160) DEFAULT NULL",
    'address_line2' => "VARCHAR(160) DEFAULT NULL",
    'address_city'  => "VARCHAR(80)  DEFAULT NULL",
    'address_postcode' => "VARCHAR(16) DEFAULT NULL",
] as $col => $def) {
    step("customers.$col column",
        fn() => columnExists($db, $dbName, 'customers', $col),
        fn() => $db->exec("ALTER TABLE customers ADD COLUMN $col $def"),
        $report);
}

// Emailed one-time login codes. Polymorphic (user_type/user_id) so the same
// table serves stylists in Phase C — hence NO foreign key on user_id. The code
// itself is never stored: only its HMAC-SHA256 (64 hex) is kept, short-lived,
// single-use and attempt-capped.
step('portal_auth_codes table',
    fn() => tableExists($db, $dbName, 'portal_auth_codes'),
    fn() => $db->exec("CREATE TABLE portal_auth_codes (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_type   ENUM('client','stylist') NOT NULL DEFAULT 'client',
        user_id     INT UNSIGNED NOT NULL,
        email       VARCHAR(180) NOT NULL,
        code_hash   CHAR(64)     NOT NULL,
        expires_at  DATETIME     NOT NULL,
        consumed_at DATETIME     DEFAULT NULL,
        attempts    INT UNSIGNED NOT NULL DEFAULT 0,
        ip          VARCHAR(45)  DEFAULT NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        KEY idx_email_type (email, user_type),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Split-token 'remember me'. Cookie carries selector + validator; only the
// validator's hash is stored, so a DB leak cannot forge a session. Required,
// not optional: shared-hosting session GC (~24 min idle) would otherwise log a
// PWA user out constantly.
step('portal_remember_tokens table',
    fn() => tableExists($db, $dbName, 'portal_remember_tokens'),
    fn() => $db->exec("CREATE TABLE portal_remember_tokens (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_type      ENUM('client','stylist') NOT NULL DEFAULT 'client',
        user_id        INT UNSIGNED NOT NULL,
        selector       CHAR(32)     NOT NULL UNIQUE,
        validator_hash CHAR(64)     NOT NULL,
        expires_at     DATETIME     NOT NULL,
        created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_type, user_id),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Secret token on a booking so the public confirmation page can prove the
// viewer owns it, instead of leaking name/email/phone to anyone with a ref
// (bug B6). Backfilled for existing bookings so their in-flight links keep
// working; the confirmation page also accepts a logged-in owner.
step('bookings.confirm_token column',
    fn() => columnExists($db, $dbName, 'bookings', 'confirm_token'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN confirm_token CHAR(64) DEFAULT NULL"),
    $report);
step('bookings.confirm_token backfill',
    fn() => (int)$db->query("SELECT COUNT(*) FROM bookings WHERE confirm_token IS NULL")->fetchColumn() === 0,
    function() use ($db) {
        $rows = $db->query("SELECT id FROM bookings WHERE confirm_token IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $upd  = $db->prepare("UPDATE bookings SET confirm_token = ? WHERE id = ?");
        foreach ($rows as $bid) { $upd->execute([bin2hex(random_bytes(32)), $bid]); }
    },
    $report);

// ── Phase C2: multi-stylist data model ────────────────────
// Stylists are a SEPARATE table from admin_users (which grants full admin via
// requireAdmin()). Auth columns mirror what includes/portal-auth.php expects
// (password_hash NULL until invite accepted, last_login_at, login_attempts,
// locked_until) so the same passwordless-code/optional-password core serves them.
step('stylists table',
    fn() => tableExists($db, $dbName, 'stylists'),
    fn() => $db->exec("CREATE TABLE stylists (
        id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name                   VARCHAR(120)  NOT NULL,
        email                  VARCHAR(180)  NOT NULL UNIQUE,
        phone                  VARCHAR(40)   DEFAULT NULL,
        password_hash          VARCHAR(255)  DEFAULT NULL,
        email_verified         TINYINT(1)    NOT NULL DEFAULT 0,
        stylist_type           ENUM('braider','barber','both') NOT NULL DEFAULT 'braider',
        default_commission_pct DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
        default_hourly_rate    DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
        is_active              TINYINT(1)    NOT NULL DEFAULT 1,
        is_owner               TINYINT(1)    NOT NULL DEFAULT 0,
        portal_enabled         TINYINT(1)    NOT NULL DEFAULT 1,
        bio                    TEXT          DEFAULT NULL,
        photo_url              VARCHAR(255)  DEFAULT NULL,
        invite_token           VARCHAR(64)   DEFAULT NULL,
        invite_expires         DATETIME      DEFAULT NULL,
        last_login_at          DATETIME      DEFAULT NULL,
        login_attempts         INT UNSIGNED  NOT NULL DEFAULT 0,
        locked_until           DATETIME      DEFAULT NULL,
        created_at             DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at             DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_active (is_active),
        KEY idx_invite (invite_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Per-service assignment: each cart item is already its own bookings row sharing
// cart_group_ref, so a row here = one stylist doing one service. A booking can
// carry a lead AND an assistant at once (owner works it, stylist assists hourly),
// which is why this is a separate table, not a bookings.stylist_id column. Rates
// are snapshotted onto the assignment so a later default-rate change never
// rewrites past pay. No FK on payout_id (set when a pay run covers it).
step('booking_assignments table',
    fn() => tableExists($db, $dbName, 'booking_assignments'),
    fn() => $db->exec("CREATE TABLE booking_assignments (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        booking_id      INT UNSIGNED NOT NULL,
        stylist_id      INT UNSIGNED NOT NULL,
        assign_role     ENUM('lead','assist')              NOT NULL DEFAULT 'lead',
        pay_model       ENUM('commission','hourly','none') NOT NULL DEFAULT 'commission',
        commission_pct  DECIMAL(5,2)  DEFAULT NULL,
        hourly_rate     DECIMAL(8,2)  DEFAULT NULL,
        hours_planned   DECIMAL(5,2)  DEFAULT NULL,
        hours_worked    DECIMAL(5,2)  DEFAULT NULL,
        earnings_base   DECIMAL(10,2) DEFAULT NULL,
        earnings_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        earnings_status ENUM('pending','earned','void')    NOT NULL DEFAULT 'pending',
        payout_id       INT UNSIGNED  DEFAULT NULL,
        notes           VARCHAR(255)  DEFAULT NULL,
        assigned_by     INT UNSIGNED  DEFAULT NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_booking_stylist_role (booking_id, stylist_id, assign_role),
        KEY idx_booking (booking_id),
        KEY idx_stylist_status (stylist_id, earnings_status),
        KEY idx_payout (payout_id),
        CONSTRAINT fk_ba_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
        CONSTRAINT fk_ba_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// What the owner actually PAID a stylist (distinct from what a booking earned).
// Invariant: commission_total + hourly_total + adjustment = amount.
step('stylist_payouts table',
    fn() => tableExists($db, $dbName, 'stylist_payouts'),
    fn() => $db->exec("CREATE TABLE stylist_payouts (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stylist_id       INT UNSIGNED NOT NULL,
        payout_date      DATE NOT NULL,
        period_start     DATE DEFAULT NULL,
        period_end       DATE DEFAULT NULL,
        commission_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        hourly_total     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        adjustment       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount           DECIMAL(10,2) NOT NULL,
        method           ENUM('bank_transfer','cash','other') NOT NULL DEFAULT 'bank_transfer',
        reference        VARCHAR(80)  DEFAULT NULL,
        notes            TEXT         DEFAULT NULL,
        journal_entry_id INT UNSIGNED DEFAULT NULL,
        created_by       INT UNSIGNED DEFAULT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_stylist_date (stylist_id, payout_date),
        CONSTRAINT fk_sp_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Coarse skill attribution (braiding/barbering) on services, plus an optional
// fine-grained allow-list. Both ADVISORY — the assignment UI warns but never
// blocks.
step('services.service_type column',
    fn() => columnExists($db, $dbName, 'services', 'service_type'),
    fn() => $db->exec("ALTER TABLE services ADD COLUMN service_type
        ENUM('braiding','barbering','both') NOT NULL DEFAULT 'braiding'"),
    $report);
step('stylist_services table',
    fn() => tableExists($db, $dbName, 'stylist_services'),
    fn() => $db->exec("CREATE TABLE stylist_services (
        stylist_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (stylist_id, service_id),
        CONSTRAINT fk_ss_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE,
        CONSTRAINT fk_ss_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Per-stylist unavailability. Kept OUT of the salon-wide `availability` table on
// purpose — adding a nullable stylist_id there would break its UNIQUE(avail_date,
// time_slot) guard (MySQL treats NULLs as distinct). Greys a stylist out in the
// assignment picker only; never affects the public slot grid.
step('stylist_time_off table',
    fn() => tableExists($db, $dbName, 'stylist_time_off'),
    fn() => $db->exec("CREATE TABLE stylist_time_off (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stylist_id INT UNSIGNED NOT NULL,
        start_date DATE NOT NULL,
        end_date   DATE NOT NULL,
        start_time TIME DEFAULT NULL,
        end_time   TIME DEFAULT NULL,
        reason     VARCHAR(160) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_stylist_range (stylist_id, start_date, end_date),
        CONSTRAINT fk_sto_stylist FOREIGN KEY (stylist_id) REFERENCES stylists(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Accounting: stylist cost accounts + a journal source for payouts. Revenue is
// journalled per the booking lifecycle (Phase R); payouts hit the ledger when
// the pay-run ships (C4/C5) via journalStylistPayout().
step('accounts stylist cost codes',
    fn() => (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code IN ('5300','5310')")->fetchColumn() >= 2,
    fn() => $db->exec("INSERT IGNORE INTO accounts (code, name, type) VALUES
        ('5300','Stylist Commission','expense'),
        ('5310','Stylist Wages','expense')"),
    $report);
step("journal_entries.source includes 'stylist_payout'",
    fn() => enumHasValue($db, $dbName, 'journal_entries', 'source', 'stylist_payout'),
    fn() => $db->exec("ALTER TABLE journal_entries MODIFY COLUMN source
                       ENUM('booking_payment','expense','owner_draw','manual',
                            'booking_deposit','booking_forfeit','booking_reversal',
                            'stylist_payout') NOT NULL"),
    $report);

// Seed the owner as a stylist (is_owner=1) so reports separate 'owner worked it'
// from 'a stylist worked it' without a magic id. portal_enabled=0 — she uses the
// admin panel, not the stylist portal.
step('stylists owner seed row',
    fn() => (int)$db->query("SELECT COUNT(*) FROM stylists WHERE is_owner=1")->fetchColumn() > 0,
    function() use ($db) {
        $a = $db->query("SELECT id, name, email FROM admin_users ORDER BY id LIMIT 1")->fetch();
        if (!$a) return;
        $db->prepare("INSERT INTO stylists
            (name, email, stylist_type, default_commission_pct, default_hourly_rate,
             is_active, is_owner, portal_enabled)
            VALUES (?,?,'both',100.00,0.00,1,1,0)")
           ->execute([$a['name'] ?: 'Owner', $a['email']]);
    },
    $report);

// ── Phase G: service gallery ───────────────────────────────
// Customer-facing gallery images the owner uploads from admin. service_id is
// nullable so an image can be general or tied to a service; ON DELETE SET NULL
// keeps an image (as general) if its service is later deleted.
step('gallery_images table',
    fn() => tableExists($db, $dbName, 'gallery_images'),
    fn() => $db->exec("CREATE TABLE gallery_images (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_id    INT UNSIGNED DEFAULT NULL,
        image_url     VARCHAR(255) NOT NULL,
        caption       VARCHAR(255) DEFAULT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_active_order (is_active, display_order),
        KEY idx_service (service_id),
        CONSTRAINT fk_gi_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// ── Phase D1: training academy schema ──────────────────────
// A trainee is the SAME customers row (one person, one login). Learner-only
// fields live on a 1:1 satellite so customers/booking/shop code is untouched.
step('trainee_profiles table',
    fn() => tableExists($db, $dbName, 'trainee_profiles'),
    fn() => $db->exec("CREATE TABLE trainee_profiles (
        customer_id        INT UNSIGNED PRIMARY KEY,
        date_of_birth      DATE DEFAULT NULL,
        guardian_name      VARCHAR(120) DEFAULT NULL,
        guardian_contact   VARCHAR(120) DEFAULT NULL,
        guardian_consent   TINYINT(1) NOT NULL DEFAULT 0,
        funding_body       VARCHAR(120) DEFAULT NULL,
        safeguarding_notes TEXT DEFAULT NULL,
        created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_tp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('courses table',
    fn() => tableExists($db, $dbName, 'courses'),
    fn() => $db->exec("CREATE TABLE courses (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title          VARCHAR(160) NOT NULL,
        slug           VARCHAR(180) NOT NULL UNIQUE,
        level          ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'beginner',
        summary        VARCHAR(255) DEFAULT NULL,
        description    TEXT DEFAULT NULL,
        syllabus       TEXT DEFAULT NULL,
        price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_url      VARCHAR(255) DEFAULT NULL,
        is_active      TINYINT(1) NOT NULL DEFAULT 1,
        display_order  INT NOT NULL DEFAULT 0,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_active (is_active, display_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_cohorts table',
    fn() => tableExists($db, $dbName, 'course_cohorts'),
    fn() => $db->exec("CREATE TABLE course_cohorts (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id  INT UNSIGNED NOT NULL,
        name       VARCHAR(120) NOT NULL,
        start_date DATE DEFAULT NULL,
        end_date   DATE DEFAULT NULL,
        seats      INT UNSIGNED NOT NULL DEFAULT 8,
        location   VARCHAR(160) DEFAULT NULL,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_course (course_id, is_active),
        CONSTRAINT fk_cc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_modules table',
    fn() => tableExists($db, $dbName, 'course_modules'),
    fn() => $db->exec("CREATE TABLE course_modules (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id     INT UNSIGNED NOT NULL,
        title         VARCHAR(160) NOT NULL,
        display_order INT NOT NULL DEFAULT 0,
        CONSTRAINT fk_cm_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_lessons table',
    fn() => tableExists($db, $dbName, 'course_lessons'),
    fn() => $db->exec("CREATE TABLE course_lessons (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module_id     INT UNSIGNED NOT NULL,
        title         VARCHAR(160) NOT NULL,
        content       MEDIUMTEXT DEFAULT NULL,
        display_order INT NOT NULL DEFAULT 0,
        is_preview    TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_cl_module FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_lesson_materials table',
    fn() => tableExists($db, $dbName, 'course_lesson_materials'),
    fn() => $db->exec("CREATE TABLE course_lesson_materials (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lesson_id     INT UNSIGNED NOT NULL,
        title         VARCHAR(160) NOT NULL,
        url           VARCHAR(255) NOT NULL,
        material_type ENUM('link','file','video') NOT NULL DEFAULT 'link',
        display_order INT NOT NULL DEFAULT 0,
        CONSTRAINT fk_clm_lesson FOREIGN KEY (lesson_id) REFERENCES course_lessons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// One enrolment = one person on one course (optionally a specific cohort).
step('course_enrolments table',
    fn() => tableExists($db, $dbName, 'course_enrolments'),
    fn() => $db->exec("CREATE TABLE course_enrolments (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_id    INT UNSIGNED NOT NULL,
        course_id      INT UNSIGNED NOT NULL,
        cohort_id      INT UNSIGNED DEFAULT NULL,
        status         ENUM('pending','active','completed','withdrawn','waitlisted') NOT NULL DEFAULT 'pending',
        payment_status ENUM('unpaid','deposit_paid','paid','waived') NOT NULL DEFAULT 'unpaid',
        amount_due     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_paid    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes          VARCHAR(255) DEFAULT NULL,
        enrolled_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at   DATETIME DEFAULT NULL,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_customer_cohort (customer_id, cohort_id),
        KEY idx_customer (customer_id),
        KEY idx_course_status (course_id, status),
        CONSTRAINT fk_ce_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        CONSTRAINT fk_ce_course   FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE CASCADE,
        CONSTRAINT fk_ce_cohort   FOREIGN KEY (cohort_id)   REFERENCES course_cohorts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_payments table',
    fn() => tableExists($db, $dbName, 'course_payments'),
    fn() => $db->exec("CREATE TABLE course_payments (
        id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrolment_id      INT UNSIGNED NOT NULL,
        amount            DECIMAL(10,2) NOT NULL,
        type              ENUM('deposit','full','installment') NOT NULL DEFAULT 'full',
        method            VARCHAR(40) NOT NULL DEFAULT 'stripe',
        stripe_payment_intent VARCHAR(255) DEFAULT NULL,
        status            ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
        journal_entry_id  INT UNSIGNED DEFAULT NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_enrolment (enrolment_id),
        KEY idx_intent (stripe_payment_intent),
        CONSTRAINT fk_cp_enrolment FOREIGN KEY (enrolment_id) REFERENCES course_enrolments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_lesson_progress table',
    fn() => tableExists($db, $dbName, 'course_lesson_progress'),
    fn() => $db->exec("CREATE TABLE course_lesson_progress (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrolment_id INT UNSIGNED NOT NULL,
        lesson_id    INT UNSIGNED NOT NULL,
        completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_enrol_lesson (enrolment_id, lesson_id),
        CONSTRAINT fk_clp_enrolment FOREIGN KEY (enrolment_id) REFERENCES course_enrolments(id) ON DELETE CASCADE,
        CONSTRAINT fk_clp_lesson    FOREIGN KEY (lesson_id)    REFERENCES course_lessons(id)    ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_attendance table',
    fn() => tableExists($db, $dbName, 'course_attendance'),
    fn() => $db->exec("CREATE TABLE course_attendance (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrolment_id INT UNSIGNED NOT NULL,
        session_date DATE NOT NULL,
        present      TINYINT(1) NOT NULL DEFAULT 1,
        note         VARCHAR(160) DEFAULT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_enrol_session (enrolment_id, session_date),
        CONSTRAINT fk_ca_enrolment FOREIGN KEY (enrolment_id) REFERENCES course_enrolments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_certificates table',
    fn() => tableExists($db, $dbName, 'course_certificates'),
    fn() => $db->exec("CREATE TABLE course_certificates (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrolment_id        INT UNSIGNED NOT NULL,
        certificate_ref     VARCHAR(32) NOT NULL UNIQUE,
        name_on_certificate VARCHAR(160) NOT NULL,
        issued_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_enrolment (enrolment_id),
        CONSTRAINT fk_cert_enrolment FOREIGN KEY (enrolment_id) REFERENCES course_enrolments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// Course fees are their own income line; recognised when paid (paid up front).
step('accounts 4030 Course Revenue',
    fn() => (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code='4030'")->fetchColumn() > 0,
    fn() => $db->exec("INSERT IGNORE INTO accounts (code, name, type) VALUES ('4030','Course Revenue','income')"),
    $report);
step("journal_entries.source includes 'course_payment'",
    fn() => enumHasValue($db, $dbName, 'journal_entries', 'source', 'course_payment'),
    fn() => $db->exec("ALTER TABLE journal_entries MODIFY COLUMN source
                       ENUM('booking_payment','expense','owner_draw','manual',
                            'booking_deposit','booking_forfeit','booking_reversal',
                            'stylist_payout','course_payment') NOT NULL"),
    $report);

// ── Phase D5: quiz engine ───────────────────────────────────
// SECURITY: course_question_options.is_correct is server-only — it is NEVER
// selected into any client-facing query/JSON. Grading happens server-side in
// includes/quiz.php against the attempt, which is bound to the learner's own
// enrolment by a random token.
step('course_quizzes table',
    fn() => tableExists($db, $dbName, 'course_quizzes'),
    fn() => $db->exec("CREATE TABLE course_quizzes (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id       INT UNSIGNED NOT NULL,
        title           VARCHAR(160) NOT NULL,
        pass_mark       TINYINT UNSIGNED NOT NULL DEFAULT 70,
        time_limit_mins INT UNSIGNED DEFAULT NULL,
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_course (course_id, is_active),
        CONSTRAINT fk_cq_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_questions table',
    fn() => tableExists($db, $dbName, 'course_questions'),
    fn() => $db->exec("CREATE TABLE course_questions (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quiz_id       INT UNSIGNED NOT NULL,
        question_text TEXT NOT NULL,
        display_order INT NOT NULL DEFAULT 0,
        KEY idx_quiz (quiz_id),
        CONSTRAINT fk_cqu_quiz FOREIGN KEY (quiz_id) REFERENCES course_quizzes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_question_options table',
    fn() => tableExists($db, $dbName, 'course_question_options'),
    fn() => $db->exec("CREATE TABLE course_question_options (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id   INT UNSIGNED NOT NULL,
        option_text   VARCHAR(500) NOT NULL,
        is_correct    TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT NOT NULL DEFAULT 0,
        KEY idx_question (question_id),
        CONSTRAINT fk_cqo_question FOREIGN KEY (question_id) REFERENCES course_questions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_quiz_attempts table',
    fn() => tableExists($db, $dbName, 'course_quiz_attempts'),
    fn() => $db->exec("CREATE TABLE course_quiz_attempts (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quiz_id       INT UNSIGNED NOT NULL,
        enrolment_id  INT UNSIGNED NOT NULL,
        attempt_token CHAR(64) NOT NULL,
        started_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at    DATETIME DEFAULT NULL,
        submitted_at  DATETIME DEFAULT NULL,
        score         TINYINT UNSIGNED DEFAULT NULL,
        passed        TINYINT(1) DEFAULT NULL,
        KEY idx_enrol (enrolment_id),
        KEY idx_token (attempt_token),
        CONSTRAINT fk_cqa_quiz     FOREIGN KEY (quiz_id)      REFERENCES course_quizzes(id)   ON DELETE CASCADE,
        CONSTRAINT fk_cqa_enrol    FOREIGN KEY (enrolment_id) REFERENCES course_enrolments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

step('course_quiz_answers table',
    fn() => tableExists($db, $dbName, 'course_quiz_answers'),
    fn() => $db->exec("CREATE TABLE course_quiz_answers (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attempt_id  INT UNSIGNED NOT NULL,
        question_id INT UNSIGNED NOT NULL,
        option_id   INT UNSIGNED DEFAULT NULL,
        is_correct  TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_attempt_question (attempt_id, question_id),
        KEY idx_attempt (attempt_id),
        CONSTRAINT fk_cqan_attempt FOREIGN KEY (attempt_id) REFERENCES course_quiz_attempts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
    $report);

// ── Business WhatsApp number → 07312017135 (owner request 2026-10) ──
// The number now has ONE source (whatsapp_number setting, read via waNumber*()).
// Set it once; the marker stops a re-run overwriting a number later changed in
// Admin → Settings.
step('whatsapp_number set to 07312017135 (one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='wa_number_2026_10_set'")->fetchColumn() > 0,
    function() use ($db) {
        $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $up->execute(['whatsapp_number', '07312017135']);
        $up->execute(['wa_number_2026_10_set', '1']);
    },
    $report);

// ── Rebooking: per-service reminder interval + sent marker ──
// services.rebook_weeks: NULL = use the default (Settings), 0 = never remind.
step('services.rebook_weeks column',
    fn() => columnExists($db, $dbName, 'services', 'rebook_weeks'),
    fn() => $db->exec("ALTER TABLE services ADD COLUMN rebook_weeks TINYINT UNSIGNED DEFAULT NULL"),
    $report);
step('bookings.rebook_reminder_sent_at column',
    fn() => columnExists($db, $dbName, 'bookings', 'rebook_reminder_sent_at'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN rebook_reminder_sent_at DATETIME DEFAULT NULL"),
    $report);

// ── Follow-ups (owner request 2026-11) ──
// bookings.rebook_on: a follow-up date the owner picked when completing the
//   appointment (NULL = automatic: the service's weeks, else the default).
// bookings.rebook_off: 1 = no rebook reminder for this appointment.
// bookings.aftercare_sent_at: the "how are your braids settling?" message.
// customers.winback_sent_at: last "we miss you" email (once per lapse).
step('bookings.rebook_on column',
    fn() => columnExists($db, $dbName, 'bookings', 'rebook_on'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN rebook_on DATE DEFAULT NULL"),
    $report);
step('bookings.rebook_off column',
    fn() => columnExists($db, $dbName, 'bookings', 'rebook_off'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN rebook_off TINYINT(1) NOT NULL DEFAULT 0"),
    $report);
step('bookings.aftercare_sent_at column',
    fn() => columnExists($db, $dbName, 'bookings', 'aftercare_sent_at'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN aftercare_sent_at DATETIME DEFAULT NULL"),
    $report);
step('customers.winback_sent_at column',
    fn() => columnExists($db, $dbName, 'customers', 'winback_sent_at'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN winback_sent_at DATETIME DEFAULT NULL"),
    $report);

// ── Salon closes at 7pm (owner, 20 Nov 2026): last appointment must END by 19:00.
// One-time marker so a later change in Settings is never overwritten.
step('day_end_time set to 19:00 (one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='day_end_1900_set'")->fetchColumn() > 0,
    function() use ($db) {
        $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $up->execute(['day_end_time', '19:00']);
        $up->execute(['day_end_1900_set', '1']);
    },
    $report);

// ── Thank-you + reviews (owner, 20 Nov 2026; mockup docs/mockups/2026-11-thankyou-reviews.png) ──
step('review_requests.reminder_sent_at column',
    fn() => columnExists($db, $dbName, 'review_requests', 'reminder_sent_at'),
    fn() => $db->exec("ALTER TABLE review_requests ADD COLUMN reminder_sent_at DATETIME DEFAULT NULL"),
    $report);
// One-time: new timings, Google review link, social pages (only if still empty),
// and review discounts off for good. A marker stops re-runs overwriting later edits.
step('thank-you/review settings (one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='thankyou_reviews_2026_11_set'")->fetchColumn() > 0,
    function() use ($db) {
        $up = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $ifEmpty = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                                 ON DUPLICATE KEY UPDATE setting_value = IF(setting_value IS NULL OR setting_value = '', VALUES(setting_value), setting_value)");
        $up->execute(['aftercare_delay_hours', '2']);        // thank-you 2 hours after completion
        $up->execute(['review_delay_hours', '24']);          // review request the next day
        $up->execute(['review_incentive_enabled', '0']);     // no discounts for reviews
        $up->execute(['google_review_url', 'https://g.page/r/CeLCLpIR1r0uEBE/review']);
        $ifEmpty->execute(['tiktok_url', 'https://www.tiktok.com/@braidedbyagb']);
        $ifEmpty->execute(['facebook_url', 'https://www.facebook.com/BraidedbyAGB']);
        $up->execute(['thankyou_reviews_2026_11_set', '1']);
    },
    $report);

// ── Email-offers consent: when and how it last changed (owner-approved 20 Nov 2026) ──
// email_optin: 1 allowed, 0 turned off, NULL not asked. Source: booking |
// account | unsubscribe | agreed_in_person.
step('customers.email_optin_changed_at / email_optin_source columns',
    fn() => columnExists($db, $dbName, 'customers', 'email_optin_source'),
    fn() => $db->exec("ALTER TABLE customers
        ADD COLUMN email_optin_changed_at DATETIME DEFAULT NULL,
        ADD COLUMN email_optin_source VARCHAR(40) DEFAULT NULL"),
    $report);
step('customers.email_optin allows NULL (not asked)',
    fn() => strtoupper((string)$db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $db->quote($dbName) . " AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_optin'")->fetchColumn()) === 'YES',
    fn() => $db->exec("ALTER TABLE customers MODIFY COLUMN email_optin TINYINT(1) NULL DEFAULT NULL"),
    $report);
// The step above was skipped on the live database: the column already allowed
// NULL but still defaulted to 1, so clients created without a choice (course
// enrolments) would count as "allowed". Check the DEFAULT itself.
step('customers.email_optin defaults to NULL (not asked)',
    fn() => $db->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $db->quote($dbName) . " AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_optin'")->fetchColumn() === null
            || strtoupper((string)$db->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $db->quote($dbName) . " AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'email_optin'")->fetchColumn()) === 'NULL',
    fn() => $db->exec("ALTER TABLE customers MODIFY COLUMN email_optin TINYINT(1) NULL DEFAULT NULL"),
    $report);

// ── Owner request 21 Nov 2026: delete the £1,500 test order by "Tolulope
// Agboola" (it was a trial, not a sale) and take it out of revenue. Safe by
// design: acts only on EXACTLY ONE matching order; refuses (changes nothing)
// if it was paid by card through Stripe, since real money would need a refund
// first. Removes the order, its items/payments/review links and its ledger
// entries; puts back stock it took; keeps the client record. One-time.
step('delete test order: Tolulope Agboola £1,500 (owner request, one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='test_order_tolulope_1500_removed'")->fetchColumn() > 0,
    function() use ($db) {
        $q = $db->query("SELECT o.* FROM orders o JOIN customers c ON c.id = o.customer_id
                         WHERE LOWER(TRIM(c.name)) = 'tolulope agboola' AND o.total BETWEEN 1499.995 AND 1500.005");
        $rows = $q->fetchAll();
        if (count($rows) !== 1) throw new RuntimeException('found ' . count($rows) . ' matching orders (need exactly 1) — nothing changed');
        $o = $rows[0]; $oid = (int)$o['id'];
        if (($o['payment_method'] ?? '') === 'stripe' && ($o['payment_status'] ?? '') === 'paid' && !empty($o['stripe_payment_id'])) {
            throw new RuntimeException("order {$o['order_ref']} was paid by card through Stripe ({$o['stripe_payment_id']}) — refund it in Stripe and confirm before it is deleted; nothing changed");
        }
        $db->beginTransaction();
        try {
            if ((int)($o['stock_deducted'] ?? 0) === 1) {
                // Put back the stock it took (same rule as finance.php: its own
                // variant, else the product's only variant).
                $it = $db->prepare("SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?");
                $it->execute([$oid]);
                foreach ($it->fetchAll() as $line) {
                    $vid = $line['variant_id'] !== null ? (int)$line['variant_id'] : 0;
                    if (!$vid) {
                        $vs = $db->prepare("SELECT id FROM product_variants WHERE product_id = ? LIMIT 2");
                        $vs->execute([(int)$line['product_id']]);
                        $ids = $vs->fetchAll(PDO::FETCH_COLUMN);
                        $vid = count($ids) === 1 ? (int)$ids[0] : 0;
                    }
                    if ($vid) $db->prepare("UPDATE product_variants SET stock_qty = stock_qty + ? WHERE id = ?")->execute([(int)$line['quantity'], $vid]);
                }
            }
            if (!empty($o['discount_code_id']) && ($o['payment_status'] ?? '') === 'paid') {
                $db->prepare("UPDATE discount_codes SET uses_count = GREATEST(0, uses_count - 1) WHERE id = ?")->execute([(int)$o['discount_code_id']]);
            }
            $db->prepare("DELETE FROM journal_entries WHERE source IN ('order_payment','order_reversal','order_refund') AND source_id = ?")->execute([$oid]);
            if (tableExists($db, DB_NAME, 'refunds')) $db->prepare("DELETE FROM refunds WHERE kind = 'order' AND ref_id = ?")->execute([$oid]);
            $db->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$oid]);
            $db->prepare("UPDATE reviews SET order_id = NULL WHERE order_id = ?")->execute([$oid]);
            $db->prepare("DELETE FROM review_requests WHERE order_id = ?")->execute([$oid]);
            $db->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$oid]);
            $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$oid]);
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('test_order_tolulope_1500_removed', ?)")
               ->execute([json_encode(['ref' => $o['order_ref'], 'total' => $o['total'], 'created' => $o['created_at'],
                                       'payment' => [$o['payment_method'], $o['payment_status']], 'removed_at' => date('c')])]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    },
    $report);

// ── Owner request 21 Nov 2026: delete the test client "Tolulope Agboola" and
// everything attached to her. Guarded: exactly one such client, and it refuses
// (changes nothing) if she has any real business history — a completed
// booking, a succeeded payment, a paid order, a course enrolment or earned
// referral credit — so a real client can never be wiped by this.
step('delete test client: Tolulope Agboola (owner request, one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='test_client_tolulope_removed'")->fetchColumn() > 0,
    function() use ($db) {
        $q = $db->query("SELECT id, email FROM customers WHERE LOWER(TRIM(name)) = 'tolulope agboola'");
        $rows = $q->fetchAll();
        if (count($rows) !== 1) throw new RuntimeException('found ' . count($rows) . ' clients named Tolulope Agboola (need exactly 1) — nothing changed');
        $cid = (int)$rows[0]['id']; $email = (string)$rows[0]['email'];
        $has = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
        $why = [];
        if ($n = $has("SELECT COUNT(*) FROM bookings WHERE customer_id = $cid AND status = 'completed'")) $why[] = "$n completed booking(s)";
        if ($n = $has("SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.customer_id = $cid AND p.status = 'succeeded'")) $why[] = "$n paid booking payment(s)";
        if ($n = $has("SELECT COUNT(*) FROM orders WHERE customer_id = $cid AND payment_status = 'paid'")) $why[] = "$n paid order(s)";
        if (tableExists($db, DB_NAME, 'course_enrolments') && ($n = $has("SELECT COUNT(*) FROM course_enrolments WHERE customer_id = $cid"))) $why[] = "$n course enrolment(s)";
        if (tableExists($db, DB_NAME, 'referral_credits') && ($n = $has("SELECT COUNT(*) FROM referral_credits WHERE customer_id = $cid AND status = 'earned'"))) $why[] = "$n earned referral credit(s)";
        if ($why) throw new RuntimeException('client #' . $cid . ' has real history (' . implode(', ', $why) . ') — not deleted, nothing changed');

        $del = function(string $sql, array $args = []) use ($db) { $db->prepare($sql)->execute($args); };
        $opt = fn(string $t) => tableExists($db, DB_NAME, $t);
        $db->beginTransaction();
        try {
            $bids = $db->query("SELECT id FROM bookings WHERE customer_id = $cid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($bids as $bid) {
                $bid = (int)$bid;
                $del("DELETE FROM journal_entries WHERE source IN ('booking_payment','booking_deposit','booking_forfeit','booking_reversal','booking_travel','booking_refund') AND source_id = ?", [$bid]);
                $del("DELETE FROM booking_addons WHERE booking_id = ?", [$bid]);
                $del("DELETE FROM payments WHERE booking_id = ?", [$bid]);
                if ($opt('booking_assignments'))   $del("DELETE FROM booking_assignments WHERE booking_id = ?", [$bid]);
                if ($opt('referral_credit_uses'))  $del("DELETE FROM referral_credit_uses WHERE booking_id = ?", [$bid]);
                if ($opt('refunds'))               $del("DELETE FROM refunds WHERE kind = 'booking' AND ref_id = ?", [$bid]);
                $del("DELETE FROM review_requests WHERE booking_id = ?", [$bid]);
                $del("UPDATE reviews SET booking_id = NULL WHERE booking_id = ?", [$bid]);
                $del("UPDATE orders SET booking_id = NULL WHERE booking_id = ? AND customer_id <> ?", [$bid, $cid]);
            }
            $oids = $db->query("SELECT id FROM orders WHERE customer_id = $cid")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($oids as $oid) {
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
                foreach ($db->query("SELECT id FROM referrals WHERE referrer_id = $cid OR referred_id = $cid")->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                    $del("DELETE FROM referral_credits WHERE referral_id = ?", [(int)$rid]);
                }
                $del("DELETE FROM referrals WHERE referrer_id = ? OR referred_id = ?", [$cid, $cid]);
                $del("DELETE FROM referral_credits WHERE customer_id = ?", [$cid]);
            }
            foreach (['loyalty_transactions', 'customer_notes', 'review_requests', 'reviews', 'message_batch_recipients', 'trainee_profiles', 'discount_codes'] as $t) {
                if ($opt($t)) $del("DELETE FROM $t WHERE customer_id = ?", [$cid]);
            }
            if ($opt('portal_auth_codes'))      $del("DELETE FROM portal_auth_codes WHERE user_type = 'client' AND (user_id = ? OR email = ?)", [$cid, $email]);
            if ($opt('portal_remember_tokens')) $del("DELETE FROM portal_remember_tokens WHERE user_type = 'client' AND user_id = ?", [$cid]);
            $del("DELETE FROM customers WHERE id = ?", [$cid]);
            $del("INSERT INTO settings (setting_key, setting_value) VALUES ('test_client_tolulope_removed', ?)",
                 [json_encode(['customer_id' => $cid, 'bookings' => count($bids), 'orders' => count($oids), 'removed_at' => date('c')])]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    },
    $report);

// ── Batch messages to clients (owner-approved 20 Nov 2026; includes/messaging.php) ──
step('customers.unsubscribe_token column (unique)',
    fn() => columnExists($db, $dbName, 'customers', 'unsubscribe_token'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN unsubscribe_token VARCHAR(40) DEFAULT NULL, ADD UNIQUE KEY uq_customers_unsub (unsubscribe_token)"),
    $report);
step('message_batches table',
    fn() => tableExists($db, $dbName, 'message_batches'),
    fn() => $db->exec("CREATE TABLE message_batches (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        channel     ENUM('email','whatsapp') NOT NULL,
        template    VARCHAR(30) DEFAULT NULL,
        subject     VARCHAR(255) DEFAULT NULL,
        body        TEXT NOT NULL,
        book_button TINYINT(1) NOT NULL DEFAULT 1,
        audience    VARCHAR(100) DEFAULT NULL,
        total       INT NOT NULL DEFAULT 0,
        sent        INT NOT NULL DEFAULT 0,
        failed      INT NOT NULL DEFAULT 0,
        status      ENUM('sending','done','cancelled') NOT NULL DEFAULT 'sending',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);
step('message_batch_recipients table',
    fn() => tableExists($db, $dbName, 'message_batch_recipients'),
    fn() => $db->exec("CREATE TABLE message_batch_recipients (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT NOT NULL,
        customer_id INT NOT NULL,
        status      ENUM('pending','sending','sent','failed','skipped','opened') NOT NULL DEFAULT 'pending',
        error       VARCHAR(255) DEFAULT NULL,
        claimed_at  DATETIME DEFAULT NULL,
        sent_at     DATETIME DEFAULT NULL,
        UNIQUE KEY uq_batch_customer (batch_id, customer_id),
        KEY idx_mbr_customer (customer_id, sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);

// ── "AGB bring your bestie" referrals (owner request 2026-11; rules in includes/referrals.php) ──
step('customers.referral_code column (unique)',
    fn() => columnExists($db, $dbName, 'customers', 'referral_code'),
    fn() => $db->exec("ALTER TABLE customers ADD COLUMN referral_code VARCHAR(20) DEFAULT NULL, ADD UNIQUE KEY uq_customers_referral_code (referral_code)"),
    $report);
step('bookings.referral_discount column',
    fn() => columnExists($db, $dbName, 'bookings', 'referral_discount'),
    fn() => $db->exec("ALTER TABLE bookings ADD COLUMN referral_discount DECIMAL(8,2) NOT NULL DEFAULT 0.00"),
    $report);
step('referrals table',
    fn() => (bool)$db->query("SHOW TABLES LIKE 'referrals'")->fetchColumn(),
    fn() => $db->exec("CREATE TABLE referrals (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        referrer_id  INT NOT NULL,
        referred_id  INT NOT NULL,
        booking_id   INT NOT NULL,
        code         VARCHAR(20) NOT NULL,
        status       ENUM('pending','completed','void') NOT NULL DEFAULT 'pending',
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        KEY idx_ref_referrer (referrer_id),
        KEY idx_ref_referred (referred_id),
        KEY idx_ref_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);
step('referral_credits table',
    fn() => (bool)$db->query("SHOW TABLES LIKE 'referral_credits'")->fetchColumn(),
    fn() => $db->exec("CREATE TABLE referral_credits (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        referral_id INT DEFAULT NULL,
        role        ENUM('referrer','friend') NOT NULL,
        amount      DECIMAL(8,2) NOT NULL,
        remaining   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        status      ENUM('pending','earned','void') NOT NULL DEFAULT 'pending',
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        earned_at   DATETIME DEFAULT NULL,
        KEY idx_rc_customer (customer_id, status),
        KEY idx_rc_referral (referral_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);
// ── Finance (owner request 2026-11; rules in includes/finance.php) ──
step('accounts 4010/4011/4012 (product sales, travel fees, delivery)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM accounts WHERE code IN ('4010','4011','4012')")->fetchColumn() >= 3,
    fn() => $db->exec("INSERT IGNORE INTO accounts (code, name, type) VALUES
        ('4010','Product Sales','income'), ('4011','Travel Fees','income'), ('4012','Delivery Charges','income')"),
    $report);
step("journal_entries.source includes finance sources",
    fn() => enumHasValue($db, $dbName, 'journal_entries', 'source', 'expense_reversal'),
    fn() => $db->exec("ALTER TABLE journal_entries MODIFY COLUMN source
                       ENUM('booking_payment','expense','owner_draw','manual',
                            'booking_deposit','booking_forfeit','booking_reversal',
                            'stylist_payout','course_payment',
                            'order_payment','order_reversal','order_refund',
                            'booking_travel','booking_refund','expense_reversal') NOT NULL"),
    $report);
step('orders.stock_deducted column',
    fn() => columnExists($db, $dbName, 'orders', 'stock_deducted'),
    fn() => $db->exec("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) NOT NULL DEFAULT 0"),
    $report);
// Products bought with bookings before stock tracking existed never had stock
// taken off; the owner has managed those counts by hand, so mark them "legacy"
// (2) and never move their stock now. Runs in the same deploy as the column.
step('orders: existing booking-product orders marked legacy for stock (one-time)',
    fn() => (int)$db->query("SELECT COUNT(*) FROM settings WHERE setting_key='stock_legacy_marked_2026_11'")->fetchColumn() > 0,
    function() use ($db) {
        $db->exec("UPDATE orders SET stock_deducted = 2 WHERE from_pipeline = 1");
        $db->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('stock_legacy_marked_2026_11','1')
                   ON DUPLICATE KEY UPDATE setting_value = '1'");
    },
    $report);
step('expenses.paid_from / receipt_url / recurring_id / is_void columns',
    fn() => columnExists($db, $dbName, 'expenses', 'is_void'),
    fn() => $db->exec("ALTER TABLE expenses
        ADD COLUMN paid_from VARCHAR(12) NOT NULL DEFAULT 'bank',
        ADD COLUMN receipt_url VARCHAR(255) DEFAULT NULL,
        ADD COLUMN recurring_id INT DEFAULT NULL,
        ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0"),
    $report);
step('recurring_expenses table',
    fn() => tableExists($db, $dbName, 'recurring_expenses'),
    fn() => $db->exec("CREATE TABLE recurring_expenses (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        description       VARCHAR(255) NOT NULL,
        amount            DECIMAL(10,2) NOT NULL,
        category          VARCHAR(100) NOT NULL,
        paid_from         VARCHAR(12) NOT NULL DEFAULT 'bank',
        day_of_month      TINYINT NOT NULL DEFAULT 1,
        is_active         TINYINT(1) NOT NULL DEFAULT 1,
        last_posted_month CHAR(7) DEFAULT NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);
// The app can set an order to 'refunded' (api/admin.php) but the column never
// allowed it. Append it to whatever values the live column has (never drops any).
step("orders.status allows 'refunded'",
    fn() => enumHasValue($db, $dbName, 'orders', 'status', 'refunded'),
    function() use ($db, $dbName) {
        $q = $db->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status'");
        $q->execute([$dbName]);
        $col = $q->fetch();
        if (!$col || !preg_match('/^enum\((.*)\)$/i', $col['COLUMN_TYPE'], $m)) throw new RuntimeException('orders.status is not an ENUM');
        $type = 'ENUM(' . $m[1] . ",'refunded')";
        $def  = $col['COLUMN_DEFAULT'] !== null ? ' DEFAULT ' . $db->quote(trim($col['COLUMN_DEFAULT'], "'")) : '';
        $db->exec("ALTER TABLE orders MODIFY COLUMN status {$type}" . ($col['IS_NULLABLE'] === 'NO' ? ' NOT NULL' : ' NULL') . $def);
    },
    $report);

step('refunds table',
    fn() => tableExists($db, $dbName, 'refunds'),
    fn() => $db->exec("CREATE TABLE refunds (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        kind        ENUM('booking','order') NOT NULL,
        ref_id      INT NOT NULL,
        reference   VARCHAR(40) NOT NULL,
        amount      DECIMAL(10,2) NOT NULL,
        method      VARCHAR(12) NOT NULL,
        reason      VARCHAR(255) DEFAULT NULL,
        refund_date DATE NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_refunds_ref (kind, ref_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
    $report);

step('referral_credit_uses table',
    fn() => (bool)$db->query("SHOW TABLES LIKE 'referral_credit_uses'")->fetchColumn(),
    fn() => $db->exec("CREATE TABLE referral_credit_uses (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        credit_id  INT NOT NULL,
        booking_id INT NOT NULL,
        amount     DECIMAL(8,2) NOT NULL,
        status     ENUM('active','reversed') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_rcu_credit (credit_id),
        KEY idx_rcu_booking (booking_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"),
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
