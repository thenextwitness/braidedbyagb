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
define('MIGRATE_KEY', 'CHANGE-ME-to-a-long-random-secret');  // ← set your own secret

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
