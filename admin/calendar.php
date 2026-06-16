<?php
// ============================================================
// BraidedbyAGB — Admin Calendar
// FILE: /admin/calendar.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Calendar';

// ── Handle POST actions ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $date   = sanitize($_POST['date']   ?? '');

    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        try {
            $reason   = sanitize($_POST['reason'] ?? '');
            $rawSlot  = sanitize($_POST['time_slot'] ?? '');
            // 'all' or empty = full-day block (NULL); otherwise validate HH:MM:SS format
            $timeSlot = ($rawSlot === '' || $rawSlot === 'all') ? null
                        : (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $rawSlot) ? $rawSlot : null);

            if ($action === 'block') {
                if ($timeSlot === null) {
                    // Full-day block: upsert the null-slot row
                    $db->prepare("INSERT INTO availability (avail_date, time_slot, is_blocked, block_reason)
                                  VALUES (?,NULL,1,?)
                                  ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=VALUES(block_reason)")
                       ->execute([$date, $reason]);
                } else {
                    // Specific-slot block
                    $db->prepare("INSERT INTO availability (avail_date, time_slot, is_blocked, block_reason)
                                  VALUES (?,?,1,?)
                                  ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=VALUES(block_reason)")
                       ->execute([$date, $timeSlot, $reason]);
                }

            } elseif ($action === 'unblock') {
                if ($timeSlot === null) {
                    // Unblock full-day (remove null-slot row only)
                    $db->prepare("DELETE FROM availability WHERE avail_date=? AND time_slot IS NULL")->execute([$date]);
                } else {
                    // Unblock a specific slot
                    $db->prepare("DELETE FROM availability WHERE avail_date=? AND time_slot=?")->execute([$date, $timeSlot]);
                }

            } elseif ($action === 'block_range') {
                $endDate = sanitize($_POST['end_date'] ?? '');
                if ($endDate && $endDate >= $date) {
                    $cur  = new DateTime($date);
                    $end  = new DateTime($endDate);
                    $stmt = $db->prepare("INSERT INTO availability (avail_date, time_slot, is_blocked, block_reason)
                                         VALUES (?,NULL,1,?)
                                         ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=VALUES(block_reason)");
                    while ($cur <= $end) {
                        $stmt->execute([$cur->format('Y-m-d'), $reason]);
                        $cur->modify('+1 day');
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Calendar action error: ' . $e->getMessage());
        }
    }
    header('Location: /admin/calendar?msg=saved&year=' . ($_POST['year'] ?? date('Y')) . '&month=' . ($_POST['month'] ?? date('n')));
    exit;
}


$pageTitle = $pageTitle ?? 'Calendar';
require_once __DIR__ . '/includes/layout.php';

// ── Month navigation ──────────────────────────────────────
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$prevM = $month === 1  ? 12 : $month - 1;
$prevY = $month === 1  ? $year - 1 : $year;
$nextM = $month === 12 ? 1  : $month + 1;
$nextY = $month === 12 ? $year + 1 : $year;

$monthName   = date('F Y', mktime(0,0,0,$month,1,$year));
$firstDay    = (int)date('w', mktime(0,0,0,$month,1,$year));
$daysInMonth = (int)date('t', mktime(0,0,0,$month,1,$year));
$today       = date('Y-m-d');

// ── Fetch data (safe) ─────────────────────────────────────
$bookingsByDate = [];
$blockedDates   = [];

try {
    // Include duration so the calendar can show each booking's true end time.
    $stmt = $db->prepare("
        SELECT b.booked_date, b.booked_time, b.status, b.id,
               COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins,
               c.name as c_name, s.name as s_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE YEAR(b.booked_date)=? AND MONTH(b.booked_date)=?
          AND b.status != 'cancelled'
        ORDER BY b.booked_time ASC
    ");
    $stmt->execute([$year, $month]);
    foreach ($stmt->fetchAll() as $bk) {
        $bookingsByDate[$bk['booked_date']][] = $bk;
    }
} catch (Exception $e) {
    // duration_mins column may not be migrated yet — fall back without it
    $stmt = $db->prepare("
        SELECT b.booked_date, b.booked_time, b.status, b.id,
               COALESCE(NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS duration_mins,
               c.name as c_name, s.name as s_name
        FROM bookings b
        JOIN customers c ON c.id = b.customer_id
        JOIN services  s ON s.id = b.service_id
        LEFT JOIN service_variants sv ON sv.id = b.variant_id
        WHERE YEAR(b.booked_date)=? AND MONTH(b.booked_date)=?
          AND b.status != 'cancelled'
        ORDER BY b.booked_time ASC
    ");
    try {
        $stmt->execute([$year, $month]);
        foreach ($stmt->fetchAll() as $bk) {
            $bookingsByDate[$bk['booked_date']][] = $bk;
        }
    } catch (Exception $e2) { error_log('Calendar bookings error: ' . $e2->getMessage()); }
}

// Full-day blocks (time_slot IS NULL)
try {
    $stmt = $db->prepare("SELECT avail_date, block_reason FROM availability
                          WHERE YEAR(avail_date)=? AND MONTH(avail_date)=? AND is_blocked=1 AND time_slot IS NULL");
    $stmt->execute([$year, $month]);
    foreach ($stmt->fetchAll() as $b) {
        $blockedDates[$b['avail_date']] = $b['block_reason'] ?? '';
    }
} catch (Exception $e) { error_log('Calendar availability error: ' . $e->getMessage()); }

// Time-specific blocks keyed by date → [time => reason]
$blockedSlots = [];
try {
    $stmt = $db->prepare("SELECT avail_date, time_slot, block_reason FROM availability
                          WHERE YEAR(avail_date)=? AND MONTH(avail_date)=? AND is_blocked=1 AND time_slot IS NOT NULL");
    $stmt->execute([$year, $month]);
    foreach ($stmt->fetchAll() as $b) {
        $blockedSlots[$b['avail_date']][] = ['time' => $b['time_slot'], 'reason' => $b['block_reason'] ?? ''];
    }
} catch (Exception $e) { error_log('Calendar slot blocks error: ' . $e->getMessage()); }

$msg = sanitize($_GET['msg'] ?? '');
?>

<?php if ($msg === 'saved'): ?>
<div class="alert-success">✓ Calendar updated successfully.</div>
<?php endif; ?>

<!-- Controls row -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <!-- Month nav -->
  <div style="display:flex;align-items:center;gap:8px">
    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn-admin btn-admin-outline">←</a>
    <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:1.05rem;color:var(--admin-primary-dark);min-width:160px;text-align:center"><?= $monthName ?></span>
    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn-admin btn-admin-outline">→</a>
  </div>
  <a href="?year=<?= date('Y') ?>&month=<?= date('n') ?>" class="btn-admin btn-admin-outline">Today</a>

  <!-- Block date range -->
  <form method="POST" action="/admin/calendar" style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="action" value="block_range">
    <input type="hidden" name="year"   value="<?= $year ?>">
    <input type="hidden" name="month"  value="<?= $month ?>">
    <span style="font-size:0.72rem;font-weight:700;color:var(--admin-muted)">Block range:</span>
    <input type="date" name="date"     class="admin-input" style="width:145px" required>
    <span style="font-size:0.72rem;color:var(--admin-muted)">to</span>
    <input type="date" name="end_date" class="admin-input" style="width:145px" required>
    <input type="text" name="reason"   class="admin-input" style="width:130px" placeholder="Reason (optional)">
    <button type="submit" class="btn-admin btn-admin-danger">Block Range</button>
  </form>
</div>

<!-- Legend -->
<div style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap">
  <span style="font-size:0.72rem;color:var(--admin-muted);display:flex;align-items:center;gap:5px">
    <span style="width:11px;height:11px;background:var(--admin-primary);border-radius:2px;display:inline-block"></span> Pending
  </span>
  <span style="font-size:0.72rem;color:var(--admin-muted);display:flex;align-items:center;gap:5px">
    <span style="width:11px;height:11px;background:var(--admin-success);border-radius:2px;display:inline-block"></span> Confirmed
  </span>
  <span style="font-size:0.72rem;color:var(--admin-muted);display:flex;align-items:center;gap:5px">
    <span style="width:11px;height:11px;background:#fee2e2;border:1px solid #fca5a5;border-radius:2px;display:inline-block"></span> Fully Blocked
  </span>
  <span style="font-size:0.72rem;color:var(--admin-muted);display:flex;align-items:center;gap:5px">
    <span style="width:11px;height:11px;background:#fef9c3;border:1px solid #fde047;border-radius:2px;display:inline-block"></span> Slot Blocked
  </span>
  <span style="font-size:0.72rem;color:var(--admin-muted);margin-left:auto">Click any date to block or unblock it</span>
</div>

<!-- Calendar -->
<div style="background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);overflow:hidden;box-shadow:var(--admin-shadow)">
  <div class="admin-cal-grid" style="padding:10px;gap:4px">

    <!-- Day headers -->
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
      <div class="admin-cal-day-header"><?= $d ?></div>
    <?php endforeach; ?>

    <!-- Empty cells before month starts -->
    <?php for ($i = 0; $i < $firstDay; $i++): ?>
      <div class="admin-cal-day empty"></div>
    <?php endfor; ?>

    <!-- Day cells -->
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
      $dateStr      = sprintf('%04d-%02d-%02d', $year, $month, $d);
      $dayBks       = $bookingsByDate[$dateStr] ?? [];
      $isBlocked    = isset($blockedDates[$dateStr]);
      $daySlotBlocks= $blockedSlots[$dateStr] ?? [];
      $isPast       = $dateStr < $today;
      $isToday      = $dateStr === $today;
      $cls = 'admin-cal-day';
      if ($isBlocked)           $cls .= ' blocked';
      if (!empty($daySlotBlocks) && !$isBlocked) $cls .= ' partial-blocked';
      if ($isPast)              $cls .= ' past';
      if ($isToday)             $cls .= ' today';
    ?>
    <div class="<?= $cls ?>" onclick="<?= !$isPast ? "dayClick('$dateStr', " . ($isBlocked ? 'true' : 'false') . ")" : '' ?>">
      <div class="cal-day-num"><?= $d ?></div>

      <?php if ($isBlocked): ?>
        <div style="font-size:0.58rem;color:#dc2626;font-weight:700;margin-top:2px">
          🔴 Blocked<?php if ($blockedDates[$dateStr]): ?>: <em style="font-weight:400"><?= htmlspecialchars($blockedDates[$dateStr]) ?></em><?php endif; ?>
        </div>
      <?php elseif (!empty($daySlotBlocks)): ?>
        <?php foreach ($daySlotBlocks as $sb): ?>
          <div style="font-size:0.56rem;color:#b45309;font-weight:700;margin-top:1px">
            🟡 <?= date('g:i A', strtotime($sb['time'])) ?><?php if ($sb['reason']): ?> <em style="font-weight:400"><?= htmlspecialchars($sb['reason']) ?></em><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php foreach ($dayBks as $bk):
        $bkDur = (int)($bk['duration_mins'] ?? 60);
        $bkEnd = date('g:i A', strtotime($bk['booked_date'] . ' ' . $bk['booked_time']) + $bkDur * 60);
      ?>
        <a href="/admin/bookings/<?= $bk['id'] ?>"
           class="cal-booking-pill <?= $bk['status'] ?>"
           onclick="event.stopPropagation()"
           title="<?= htmlspecialchars($bk['c_name']) ?> — <?= formatTime($bk['booked_time']) ?>–<?= $bkEnd ?> (<?= formatDuration($bkDur) ?>)">
          <?= formatTime($bk['booked_time']) ?>–<?= $bkEnd ?> <?= htmlspecialchars(explode(' ', $bk['c_name'])[0]) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>

  </div>
</div>

<!-- Day click modal -->
<div id="day-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--admin-radius-lg);padding:28px;width:420px;max-width:94vw;box-shadow:0 24px 48px rgba(0,0,0,0.3)">
    <h3 style="font-family:'Montserrat',sans-serif;font-weight:800;color:var(--admin-primary-dark);margin-bottom:4px" id="modal-date-title"></h3>
    <p  style="font-size:0.8rem;color:var(--admin-muted);margin-bottom:20px" id="modal-date-sub"></p>

    <!-- Block form -->
    <div id="modal-block-section">
      <form method="POST" action="/admin/calendar">
        <input type="hidden" name="action" value="block">
        <input type="hidden" name="date"   id="modal-date-input">
        <input type="hidden" name="year"   value="<?= $year ?>">
        <input type="hidden" name="month"  value="<?= $month ?>">
        <div class="admin-form-group" style="margin-bottom:14px">
          <label class="admin-label">Time slot</label>
          <select name="time_slot" class="admin-input admin-select">
            <option value="all">🔴 All day (block entire date)</option>
            <?php
              for ($t = strtotime('08:00'); $t <= strtotime('17:30'); $t += 30 * 60) {
                $val   = date('H:i', $t);
                $label = date('g:i A', $t);
                echo "<option value=\"$val\">🟡 $label only</option>";
              }
            ?>
          </select>
        </div>
        <div class="admin-form-group" style="margin-bottom:14px">
          <label class="admin-label">Reason (optional)</label>
          <input class="admin-input" type="text" name="reason" placeholder="e.g. Holiday, personal appointment">
        </div>
        <button type="submit" class="btn-admin btn-admin-danger" style="width:100%;justify-content:center">
          Block
        </button>
      </form>
    </div>

    <!-- Unblock form (full-day) -->
    <div id="modal-unblock-section" style="display:none">
      <form method="POST" action="/admin/calendar">
        <input type="hidden" name="action"    value="unblock">
        <input type="hidden" name="date"      id="modal-date-unblock">
        <input type="hidden" name="time_slot" value="all">
        <input type="hidden" name="year"      value="<?= $year ?>">
        <input type="hidden" name="month"     value="<?= $month ?>">
        <button type="submit" class="btn-admin btn-admin-success" style="width:100%;justify-content:center">
          ✓ Unblock This Date
        </button>
      </form>
    </div>

    <button onclick="closeModal()" class="btn-admin btn-admin-outline"
            style="width:100%;margin-top:10px;justify-content:center">Cancel</button>
  </div>
</div>

<script>
function dayClick(date, isBlocked) {
  const d = new Date(date + 'T12:00:00');
  document.getElementById('modal-date-title').textContent =
    d.toLocaleDateString('en-GB', {weekday:'long', day:'numeric', month:'long', year:'numeric'});
  document.getElementById('modal-date-sub').textContent =
    isBlocked ? 'This date is fully blocked. Unblock to allow bookings, or add a time-slot block below.' : 'Block this date or a specific time slot.';
  document.getElementById('modal-date-input').value   = date;
  document.getElementById('modal-date-unblock').value = date;
  document.getElementById('modal-block-section').style.display   = 'block';
  document.getElementById('modal-unblock-section').style.display = isBlocked ? 'block' : 'none';
  document.getElementById('day-modal').style.display = 'flex';
}
function closeModal() {
  document.getElementById('day-modal').style.display = 'none';
}
document.getElementById('day-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
