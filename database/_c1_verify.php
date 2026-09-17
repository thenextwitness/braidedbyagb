<?php
// ============================================================
// BraidedbyAGB — Phase C1 verification harness (TEMPORARY)
// FILE: /database/_c1_verify.php
//
// Proves the consolidated scheduling module (includes/scheduling.php) is
// behaviour-IDENTICAL to the old inline availability logic, against the LIVE
// database. Compares, across a wide matrix of dates/services/times:
//   • availableSlotsForDay()  vs a verbatim copy of the old /api slots grid
//   • isSlotAvailable()       vs a verbatim copy of the old helpers version
//
// Run:  https://braidedbyagb.co.uk/database/_c1_verify.php?key=<MIGRATE_KEY>
// Read-only. DELETE this file once C1 is verified.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';   // loads scheduling.php (new fns)

header('Content-Type: text/plain; charset=utf-8');
if (!defined('MIGRATE_KEY') || ($_GET['key'] ?? '') !== MIGRATE_KEY) { http_response_code(403); exit('Forbidden'); }

$db = getDB();

// ── Verbatim copy of the OLD helpers.php isSlotAvailable ──
function old_isSlotAvailable(string $date, string $time, int $newDurMins = 60): bool {
    $db = getDB();
    if ($newDurMins < 1) $newDurMins = 60;
    $stmt = $db->prepare("SELECT COUNT(*) FROM availability WHERE avail_date = ? AND is_blocked = 1 AND time_slot IS NULL");
    $stmt->execute([$date]);
    if ((int)$stmt->fetchColumn() > 0) return false;
    $timeH = substr($time, 0, 5);
    $stmt  = $db->prepare("SELECT COUNT(*) FROM availability WHERE avail_date = ? AND is_blocked = 1 AND TIME_FORMAT(time_slot,'%H:%i') = ?");
    $stmt->execute([$date, $timeH]);
    if ((int)$stmt->fetchColumn() > 0) return false;
    $newStart = strtotime($date . ' ' . $time);
    $newEnd   = $newStart + $newDurMins * 60;
    $stmt = $db->prepare("SELECT b.booked_time, COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
        FROM bookings b JOIN services s ON s.id = b.service_id LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')");
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll() as $row) {
        $bs = strtotime($date . ' ' . $row['booked_time']);
        $be = $bs + (int)$row['dur'] * 60;
        if ($newStart < $be && $newEnd > $bs) return false;
    }
    return true;
}

// ── Verbatim copy of the OLD /api/index.php slots grid ───
function old_slots(string $date, int $serviceId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT duration_mins FROM services WHERE id=?");
    $stmt->execute([$serviceId]);
    $svc        = $stmt->fetch();
    $rawDurMins = $svc ? (int)$svc['duration_mins'] : 0;
    $newDurMins = $rawDurMins > 0 ? $rawDurMins : 60;
    $newDurSecs = $newDurMins * 60;

    $stmt = $db->prepare("SELECT b.booked_time, COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins
        FROM bookings b JOIN services s ON s.id = b.service_id LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE b.booked_date = ? AND b.status IN ('pending','confirmed')");
    $stmt->execute([$date]);
    $booked = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM availability WHERE avail_date=? AND is_blocked=1 AND time_slot IS NULL");
    $stmt->execute([$date]);
    $fullDayBlocked = (int)$stmt->fetchColumn() > 0;
    $stmt = $db->prepare("SELECT time_slot FROM availability WHERE avail_date=? AND is_blocked=1 AND time_slot IS NOT NULL");
    $stmt->execute([$date]);
    $blockedSlots = array_column($stmt->fetchAll(), 'time_slot');

    $dayStart = strtotime($date . ' 08:00:00');
    $dayEnd   = strtotime($date . ' 20:30:00');
    $bufferHours = (int)getSetting('booking_buffer_hours', '0');
    $cutoffTime  = time() + $bufferHours * 3600;
    $slots = [];
    for ($t = $dayStart; $t < $dayEnd; $t += 30 * 60) {
        $ts = date('H:i:s', $t);
        if ($t + $newDurSecs > $dayEnd) { $slots[] = ['time'=>$ts,'label'=>date('g:i A',$t),'available'=>false]; continue; }
        if ($date === date('Y-m-d') && $t < $cutoffTime) { $slots[] = ['time'=>$ts,'label'=>date('g:i A',$t),'available'=>false]; continue; }
        if ($fullDayBlocked || in_array($ts, $blockedSlots)) { $slots[] = ['time'=>$ts,'label'=>date('g:i A',$t),'available'=>false]; continue; }
        $conflict = false;
        foreach ($booked as $row) {
            $bs = strtotime($date . ' ' . $row['booked_time']);
            $existDurSec = (int)$row['duration_mins'] * 60;
            if ($t < $bs + $existDurSec && $t + $newDurSecs > $bs) { $conflict = true; break; }
        }
        $slots[] = ['time'=>$ts,'label'=>date('g:i A',$t),'available'=>!$conflict];
    }
    return $slots;
}

// ── Run the matrix ───────────────────────────────────────
$services = $db->query("SELECT id, duration_mins FROM services WHERE is_active = 1 ORDER BY id")->fetchAll();
$serviceIds = array_column($services, 'id');
if (!$serviceIds) { echo "No active services to test.\n"; exit; }

$slotCmp = 0; $slotMiss = 0;
$avCmp = 0;  $avMiss = 0;
$misses = [];

// Test dates: tomorrow .. +35 days (future dates avoid the today/now cutoff, so
// the only variable is real blocks + real bookings). Include today separately.
$dates = [];
for ($i = 1; $i <= 35; $i++) $dates[] = date('Y-m-d', strtotime("+{$i} day"));

foreach ($dates as $date) {
    foreach ($serviceIds as $sid) {
        $a = old_slots($date, (int)$sid);
        $b = availableSlotsForDay($date, ((int)($db->query("SELECT duration_mins FROM services WHERE id={$sid}")->fetchColumn()) ?: 60));
        // Compare element by element.
        $n = max(count($a), count($b));
        for ($k = 0; $k < $n; $k++) {
            $slotCmp++;
            $x = $a[$k] ?? null; $y = $b[$k] ?? null;
            if (!$x || !$y || $x['time'] !== $y['time'] || $x['label'] !== $y['label'] || $x['available'] !== $y['available']) {
                $slotMiss++;
                if (count($misses) < 20) $misses[] = "SLOTS {$date} svc{$sid} #{$k}: old=" . json_encode($x) . " new=" . json_encode($y);
            }
        }
    }
}

// isSlotAvailable: a grid of times/durations on the first ~10 future dates.
$durations = [30, 60, 90, 120, 180, 240];
foreach (array_slice($dates, 0, 10) as $date) {
    for ($h = 8; $h <= 20; $h++) {
        foreach (['00', '30'] as $m) {
            $time = sprintf('%02d:%s:00', $h, $m);
            foreach ($durations as $d) {
                $avCmp++;
                $o = old_isSlotAvailable($date, $time, $d);
                $nw = isSlotAvailable($date, $time, $d);
                if ($o !== $nw) {
                    $avMiss++;
                    if (count($misses) < 40) $misses[] = "ISAVAIL {$date} {$time} dur{$d}: old=" . var_export($o, true) . " new=" . var_export($nw, true);
                }
            }
        }
    }
}

echo "BraidedbyAGB — Phase C1 verification\n";
echo str_repeat('-', 56) . "\n";
echo "slots comparisons:         {$slotCmp}   mismatches: {$slotMiss}\n";
echo "isSlotAvailable comparisons: {$avCmp}   mismatches: {$avMiss}\n";
echo str_repeat('-', 56) . "\n";
if ($slotMiss === 0 && $avMiss === 0) {
    echo "RESULT: PASS — new module is behaviour-identical to the old logic.\n";
} else {
    echo "RESULT: FAIL — differences found:\n\n";
    foreach ($misses as $m) echo "  $m\n";
}
