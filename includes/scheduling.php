<?php
// ============================================================
// BraidedbyAGB — Scheduling / availability (single source of truth)
// FILE: /includes/scheduling.php
//
// Availability used to be re-implemented in four places that had already
// drifted apart (the admin reschedule check ignored duration entirely). This
// is the ONE implementation they all now call, which is what lets capacity
// (more than one chair) be added in one place instead of four.
//
// CAPACITY MODEL: a slot is available while the peak number of concurrent
// bookings over the requested window stays BELOW the day's capacity. Capacity
// defaults to 1 everywhere, at which point "peak < 1" is identical to "any
// overlap makes the slot unavailable" — exactly the old behaviour. So shipping
// this with capacity pinned at 1 changes nothing; capacity > 1 is a later,
// separate, owner-approved step.
//
// Duration precedence (unchanged): bookings.duration_mins → variant → service
// → 60. Only 'pending'/'confirmed' bookings occupy the chair — 'completed',
// 'incomplete', 'cancelled', etc. never block a future slot.
//
// Loaded from the foot of includes/helpers.php, so every entry point that
// already requires helpers gets these with no extra require.
// ============================================================

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/helpers.php';
}

/** Statuses that actually occupy the chair. */
const SCHEDULE_ACTIVE_STATUSES = ['pending', 'confirmed'];

/**
 * How many bookings may run concurrently on a given date. Defaults to 1 (single
 * chair = today's behaviour). A per-day override table and weekday map arrive in
 * a later step; for now this reads a single setting that defaults to 1, so the
 * value is 1 unless deliberately changed.
 */
function slotCapacity(string $date): int {
    $cap = (int) getSetting('booking_capacity_default', '1');
    return $cap > 0 ? $cap : 1;
}

/** The one duration precedence rule, as SQL. Requires aliases b, sv, s in scope. */
function bookingDurationExpr(): string {
    return "COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60)";
}

/**
 * Active bookings on a date as ['start'=>unixTs, 'end'=>unixTs] windows.
 * Optionally excludes one booking id (for reschedule) and overrides the statuses.
 */
function loadDayBookings(string $date, ?int $excludeBookingId = null, array $statuses = SCHEDULE_ACTIVE_STATUSES): array {
    $db  = getDB();
    $in  = "'" . implode("','", array_map(fn($s) => str_replace("'", "", $s), $statuses)) . "'";
    $dur = bookingDurationExpr();
    $sql = "SELECT b.booked_time, {$dur} AS dur
            FROM bookings b
            JOIN services s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE b.booked_date = ? AND b.status IN ({$in})";
    $params = [$date];
    if ($excludeBookingId !== null) { $sql .= " AND b.id != ?"; $params[] = $excludeBookingId; }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $bs = strtotime($date . ' ' . $row['booked_time']);
        $out[] = ['start' => $bs, 'end' => $bs + ((int) $row['dur']) * 60];
    }
    return $out;
}

/** Whole day blocked (availability row with time_slot IS NULL). */
function isDayBlocked(string $date): bool {
    $s = getDB()->prepare("SELECT COUNT(*) FROM availability WHERE avail_date = ? AND is_blocked = 1 AND time_slot IS NULL");
    $s->execute([$date]);
    return (int) $s->fetchColumn() > 0;
}

/** A specific HH:MM slot blocked. */
function isSlotBlocked(string $date, string $time): bool {
    $s = getDB()->prepare("SELECT COUNT(*) FROM availability WHERE avail_date = ? AND is_blocked = 1 AND TIME_FORMAT(time_slot,'%H:%i') = ?");
    $s->execute([$date, substr($time, 0, 5)]);
    return (int) $s->fetchColumn() > 0;
}

/** All blocked specific-slot times for a day as 'HH:MM:SS' strings (loaded once). */
function loadBlockedSlots(string $date): array {
    $s = getDB()->prepare("SELECT time_slot FROM availability WHERE avail_date = ? AND is_blocked = 1 AND time_slot IS NOT NULL");
    $s->execute([$date]);
    return array_column($s->fetchAll(), 'time_slot');
}

/**
 * Peak number of the given bookings that are concurrently in progress at any
 * instant of the window [startTs, startTs + durMins). Concurrency only changes
 * at a booking's start, so we test the window start plus every existing start
 * that falls inside the window — exact, not sampled.
 *
 * @param array $bookings rows of ['start'=>ts,'end'=>ts] (from loadDayBookings)
 */
function peakConcurrency(array $bookings, int $startTs, int $durMins): int {
    $endTs = $startTs + $durMins * 60;

    $instants = [$startTs];
    foreach ($bookings as $b) {
        if ($b['start'] > $startTs && $b['start'] < $endTs) $instants[] = $b['start'];
    }

    $peak = 0;
    foreach ($instants as $t) {
        $n = 0;
        foreach ($bookings as $b) {
            if ($b['start'] <= $t && $t < $b['end']) $n++;
        }
        if ($n > $peak) $peak = $n;
    }
    return $peak;
}

/**
 * Is [date time, +durMins) bookable? Honours full-day and specific-slot blocks,
 * then capacity: available while peak concurrent existing bookings stay below
 * the day's capacity. At capacity 1 this is exactly "no overlap".
 *
 * The optional 4th argument excludes a booking from the check (reschedule),
 * matching the old 3-argument signature for existing callers.
 */
function isSlotAvailable(string $date, string $time, int $newDurMins = 60, ?int $excludeBookingId = null): bool {
    if ($newDurMins < 1) $newDurMins = 60;
    if (isDayBlocked($date)) return false;
    if (isSlotBlocked($date, $time)) return false;

    $startTs  = strtotime($date . ' ' . $time);
    $bookings = loadDayBookings($date, $excludeBookingId);
    return peakConcurrency($bookings, $startTs, $newDurMins) < slotCapacity($date);
}

/**
 * Whether a specific stylist is free — reserved for the assignment step in a
 * later part of Phase C. A person is not a room, so this is always capacity 1
 * regardless of slotCapacity(). Defined here so the primitive lives in one place.
 */
// (isStylistFree lands with the stylist schema — kept out of C1 to keep this
//  change purely a consolidation.)

/**
 * The bookable-slots grid for a day — replaces the inline copy in the public
 * `slots` API endpoint, producing the identical array of
 * ['time'=>'HH:MM:SS', 'label'=>'g:i A', 'available'=>bool].
 *
 * $opts: day_start ('08:00'), day_end ('20:30'), step_mins (30),
 *        buffer_hours (int|null → falls back to the booking_buffer_hours setting).
 */
function availableSlotsForDay(string $date, int $newDurMins, array $opts = []): array {
    if ($newDurMins < 1) $newDurMins = 60;
    $dayStart = strtotime($date . ' ' . ($opts['day_start'] ?? '08:00') . ':00');
    $dayEnd   = strtotime($date . ' ' . ($opts['day_end']   ?? '20:30') . ':00');
    $step     = (int) ($opts['step_mins'] ?? 30) * 60;
    $newSecs  = $newDurMins * 60;
    $capacity = slotCapacity($date);

    $bufferHours = array_key_exists('buffer_hours', $opts) && $opts['buffer_hours'] !== null
        ? (int) $opts['buffer_hours']
        : (int) getSetting('booking_buffer_hours', '0');
    $cutoffTime = time() + $bufferHours * 3600;
    $isToday    = ($date === date('Y-m-d'));

    $dayBlocked   = isDayBlocked($date);
    $blockedSlots = loadBlockedSlots($date);   // loaded once, matching the old endpoint
    $bookings     = loadDayBookings($date);

    $slots = [];
    for ($t = $dayStart; $t < $dayEnd; $t += $step) {
        $ts    = date('H:i:s', $t);
        $label = date('g:i A', $t);

        // Must finish by day end.
        if ($t + $newSecs > $dayEnd) { $slots[] = ['time' => $ts, 'label' => $label, 'available' => false]; continue; }
        // Past / too-soon (today only).
        if ($isToday && $t < $cutoffTime) { $slots[] = ['time' => $ts, 'label' => $label, 'available' => false]; continue; }
        // Blocks.
        if ($dayBlocked || in_array($ts, $blockedSlots, true)) { $slots[] = ['time' => $ts, 'label' => $label, 'available' => false]; continue; }
        // Capacity / overlap.
        $available = peakConcurrency($bookings, $t, $newDurMins) < $capacity;
        $slots[] = ['time' => $ts, 'label' => $label, 'available' => $available];
    }
    return $slots;
}
