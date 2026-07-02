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
// ============================================================

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
