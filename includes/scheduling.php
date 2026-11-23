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
 * How many bookings may run concurrently on a given date (Phase C5).
 *
 * Capacity = the number of active stylists available that day. Each stylist can
 * LEAD one concurrent booking; an assistant on the SAME booking shares that one
 * slot (heavy job, two people, one client), so capacity tracks HEADCOUNT, not
 * assignments. With two stylists a 2pm slot already holding one booking can still
 * be booked once more — the owner assigns the second stylist and pays commission.
 * It expands automatically as stylists are added; no setting to maintain.
 *
 * A stylist with a FULL-DAY time-off (a stylist_time_off row with no start_time)
 * covering the date is removed from the count, so the salon can't be overbooked
 * on a short-staffed day. Floored at 1: per-stylist time-off alone can never
 * close the whole salon — real closures use the `availability` table (isDayBlocked).
 *
 * Falls back to the legacy booking_capacity_default setting only if the stylists
 * table is somehow unavailable, so scheduling never hard-fails.
 */
function slotCapacity(string $date): int {
    static $cache = [];
    if (isset($cache[$date])) return $cache[$date];

    // Multi-stylist mode off (the default): a single chair, one booking per slot.
    if (!multiStylistEnabled()) return $cache[$date] = 1;

    $db = getDB();
    try {
        $active = (int) $db->query("SELECT COUNT(*) FROM stylists WHERE is_active = 1")->fetchColumn();
        $off = $db->prepare("SELECT COUNT(DISTINCT st.id)
                             FROM stylists st
                             JOIN stylist_time_off t ON t.stylist_id = st.id
                             WHERE st.is_active = 1 AND t.start_time IS NULL
                               AND ? BETWEEN t.start_date AND t.end_date");
        $off->execute([$date]);
        $cap = $active - (int) $off->fetchColumn();
    } catch (Throwable $e) {
        $cap = (int) getSetting('booking_capacity_default', '1');
    }

    return $cache[$date] = ($cap > 0 ? $cap : 1);
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
    $normal = normalDayMins();
    foreach ($stmt->fetchAll() as $row) {
        $bs = strtotime($date . ' ' . $row['booked_time']);
        $d  = (int) $row['dur'];
        // A one-day long appointment (longer than the normal day) has the whole day.
        $out[] = ['start' => $bs, 'end' => $bs + $d * 60, 'whole_day' => $d > $normal];
    }

    // Day 2 of a two-day appointment (owner-approved 23 Nov 2026): same start
    // time, duration_mins is the length of EACH day. Columns may not exist on a
    // database that hasn't run the migration yet, so this never hard-fails.
    try {
        $sql2 = "SELECT b.day2_time, {$dur} AS dur
                 FROM bookings b
                 JOIN services s ON s.id = b.service_id
                 LEFT JOIN service_variants sv ON sv.id = b.variant_id
                 WHERE b.day2_date = ? AND b.day2_time IS NOT NULL AND b.status IN ({$in})";
        $p2 = [$date];
        if ($excludeBookingId !== null) { $sql2 .= " AND b.id != ?"; $p2[] = $excludeBookingId; }
        $st2 = $db->prepare($sql2);
        $st2->execute($p2);
        foreach ($st2->fetchAll() as $row) {
            $bs = strtotime($date . ' ' . $row['day2_time']);
            $out[] = ['start' => $bs, 'end' => $bs + ((int) $row['dur']) * 60, 'whole_day' => false];
        }
    } catch (Throwable $e) { /* pre-migration: no two-day bookings yet */ }
    return $out;
}

/** True when any booking in the list has the whole day (a one-day long appointment). */
function dayHasWholeDayBooking(array $bookings): bool {
    foreach ($bookings as $b) { if (!empty($b['whole_day'])) return true; }
    return false;
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
    // Long appointments take the whole day: nothing else that day, either way round.
    if (dayHasWholeDayBooking($bookings)) return false;
    if (isLongDuration($newDurMins)) return count($bookings) === 0 && count(loadBlockedSlots($date)) === 0;
    return peakConcurrency($bookings, $startTs, $newDurMins) < slotCapacity($date);
}

/**
 * Whether a specific stylist has NO other booking overlapping this window.
 * A person is not a room: regardless of slotCapacity(), one stylist can only be
 * in one place at a time. Used as an ADVISORY warning in the assignment picker
 * (Phase C5) so the owner doesn't accidentally put the same stylist on two
 * concurrent bookings — it never blocks. Excludes the booking being assigned so
 * an existing assignment to it isn't counted as a clash, and voided assignments
 * don't count.
 */
function isStylistFree(string $date, string $time, int $durMins, int $stylistId, ?int $excludeBookingId = null): bool {
    if ($durMins < 1) $durMins = 60;
    $db  = getDB();
    $dur = bookingDurationExpr();
    $in  = "'" . implode("','", SCHEDULE_ACTIVE_STATUSES) . "'";
    $sql = "SELECT b.booked_time, {$dur} AS dur
            FROM booking_assignments ba
            JOIN bookings b ON b.id = ba.booking_id
            JOIN services s ON s.id = b.service_id
            LEFT JOIN service_variants sv ON sv.id = b.variant_id
            WHERE ba.stylist_id = ? AND b.booked_date = ? AND b.status IN ({$in})
              AND ba.earnings_status <> 'void'";
    $params = [$stylistId, $date];
    if ($excludeBookingId !== null) { $sql .= " AND b.id != ?"; $params[] = $excludeBookingId; }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $startTs = strtotime($date . ' ' . $time);
    $endTs   = $startTs + $durMins * 60;
    foreach ($stmt->fetchAll() as $row) {
        $bs = strtotime($date . ' ' . $row['booked_time']);
        $be = $bs + ((int) $row['dur']) * 60;
        if ($bs < $endTs && $startTs < $be) return false;   // overlaps
    }
    return true;
}

/**
 * Opening hours: the ONE source for every time grid (website slots, admin new
 * booking, admin calendar blocks, the app). Settings `day_start_time` /
 * `day_end_time` (HH:MM; the end is when the last appointment must finish).
 * Returns ['start' => 'HH:MM', 'end' => 'HH:MM'], falling back to 09:30–19:00
 * when a setting is missing or malformed. (Was 20:30; owner: closes at 7pm.)
 */
const DAY_START_DEFAULT = '09:30';
const DAY_END_DEFAULT   = '19:00';   // salon closes at 7pm
function businessHours(): array {
    $norm = function (string $v, string $def): string {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $def;
    };
    $start = $norm(trim((string) getSetting('day_start_time', DAY_START_DEFAULT)), DAY_START_DEFAULT);
    $end   = $norm(trim((string) getSetting('day_end_time',   DAY_END_DEFAULT)),   DAY_END_DEFAULT);
    if (strcmp($end, $start) <= 0) { $start = DAY_START_DEFAULT; $end = DAY_END_DEFAULT; }
    return ['start' => $start, 'end' => $end];
}

/** Does an appointment at $time ('HH:MM[:SS]') lasting $durMins fit inside opening hours? */
function withinBusinessHours(string $time, int $durMins): bool {
    $h = isLongDuration($durMins) ? longDayWindow() : businessHours();
    $t = strtotime('2000-01-01 ' . $time);
    if ($t === false) return false;
    return $t >= strtotime('2000-01-01 ' . $h['start'])
        && $t + max(1, $durMins) * 60 <= strtotime('2000-01-01 ' . $h['end']);
}

/** Length of the normal day in minutes (opening to closing). */
function normalDayMins(): int {
    $h = businessHours();
    return (int) ((strtotime('2000-01-01 ' . $h['end']) - strtotime('2000-01-01 ' . $h['start'])) / 60);
}

/** A "long appointment": longer than the normal day (e.g. 12–15 hour styles). */
function isLongDuration(int $durMins): bool {
    return $durMins > normalDayMins();
}

/**
 * Hours for a ONE-DAY long appointment (owner-approved 23 Nov 2026): earliest
 * start / latest finish from Settings `long_day_start` / `long_day_end`
 * (defaults 07:00 / 22:00), never narrower than the normal day.
 */
function longDayWindow(): array {
    $norm = function (string $v, string $def): string {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $def;
    };
    $h     = businessHours();
    $start = $norm(trim((string) getSetting('long_day_start', '07:00')), '07:00');
    $end   = $norm(trim((string) getSetting('long_day_end',   '22:00')), '22:00');
    if (strcmp($start, $h['start']) > 0) $start = $h['start'];
    if (strcmp($end,   $h['end'])   < 0) $end   = $h['end'];
    return ['start' => $start, 'end' => $end];
}

/** Two-day option: each day is half the time (rounded up), same start both days. */
function twoDayPartMins(int $totalMins): int {
    return (int) ceil(max(1, $totalMins) / 2);
}

/** Extra charge for spreading a long style over two days (Settings `two_day_fee`, default £30). */
function twoDayFee(): float {
    return max(0.0, round((float) getSetting('two_day_fee', '30'), 2));
}

/** The reason a time is refused for being outside opening hours (shown in admin + app). */
function outsideHoursMessage(): string {
    $h = businessHours();
    return 'That appointment would start before opening (' . date('g:i A', strtotime($h['start'])) . ') or finish after closing ('
         . date('g:i A', strtotime($h['end'])) . '). Pick an earlier time, or change the hours in Settings.';
}

/** Start times ('HH:MM') from opening to the last start that still fits a step before closing. */
function dayTimeGrid(int $stepMins = 30, bool $includeLongDay = false): array {
    $h = $includeLongDay ? longDayWindow() : businessHours();
    $out = [];
    for ($t = strtotime('2000-01-01 ' . $h['start']); $t < strtotime('2000-01-01 ' . $h['end']); $t += $stepMins * 60) {
        $out[] = date('H:i', $t);
    }
    return $out;
}

/**
 * The bookable-slots grid for a day — replaces the inline copy in the public
 * `slots` API endpoint, producing the identical array of
 * ['time'=>'HH:MM:SS', 'label'=>'g:i A', 'available'=>bool].
 *
 * $opts: day_start / day_end (default: businessHours()), step_mins (30),
 *        buffer_hours (int|null → falls back to the booking_buffer_hours setting).
 */
function availableSlotsForDay(string $date, int $newDurMins, array $opts = []): array {
    if ($newDurMins < 1) $newDurMins = 60;
    $isLong   = isLongDuration($newDurMins);
    $hours    = $isLong ? longDayWindow() : businessHours();
    $dayStart = strtotime($date . ' ' . ($opts['day_start'] ?? $hours['start']) . ':00');
    $dayEnd   = strtotime($date . ' ' . ($opts['day_end']   ?? $hours['end'])   . ':00');
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
    // Whole-day rules: a day with a long appointment takes nothing else, and a
    // long appointment needs an empty day (no bookings, no blocked times).
    if (dayHasWholeDayBooking($bookings) || ($isLong && (count($bookings) > 0 || count($blockedSlots) > 0))) {
        $dayBlocked = true;
    }

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

/**
 * Start times for a TWO-DAY long appointment: day 1 = $date, day 2 = the next
 * day, same start, each day twoDayPartMins($totalMins) long, within normal
 * hours. A time is available only when it is free on BOTH days.
 * Returns the same shape as availableSlotsForDay() plus 'day2' => 'Y-m-d'.
 */
function availableTwoDaySlots(string $date, int $totalMins, array $opts = []): array {
    $part  = twoDayPartMins($totalMins);
    $next  = date('Y-m-d', strtotime($date . ' +1 day'));
    $day1  = availableSlotsForDay($date, $part, $opts);
    $day2  = [];
    foreach (availableSlotsForDay($next, $part, $opts) as $sl) $day2[$sl['time']] = $sl['available'];
    foreach ($day1 as &$sl) {
        $sl['available'] = $sl['available'] && !empty($day2[$sl['time']]);
        $sl['day2']      = $next;
    }
    unset($sl);
    return $day1;
}

/**
 * Admin/app reschedule check for one day of a booking (excluding the booking
 * itself): overlap/capacity plus the long-appointment whole-day rules.
 * Returns null when the time is free, else a short reason.
 */
function rescheduleClash(string $date, string $time, int $durMins, int $excludeBookingId): ?string {
    $others = loadDayBookings($date, $excludeBookingId);
    if (dayHasWholeDayBooking($others)) return 'That day already has a long (whole-day) appointment.';
    if (isLongDuration($durMins) && count($others) > 0) return 'A long appointment needs the whole day, and that day already has a booking.';
    if (peakConcurrency($others, strtotime($date . ' ' . $time), $durMins) >= slotCapacity($date)) return 'That time overlaps another booking.';
    return null;
}
