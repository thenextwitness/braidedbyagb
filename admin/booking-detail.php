<?php
// ============================================================
// BraidedbyAGB — Admin Booking Detail
// FILE: /admin/booking-detail.php (routed via .htaccess)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Booking Detail';

$bookingId = (int)($_GET['id'] ?? 0);
if (!$bookingId) { header('Location: /admin/bookings'); exit; }

$stmt = $db->prepare("
    SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
           s.name as s_name, s.slug as s_slug, sv.variant_name,
           b.payment_token, b.payment_method_allowed
    FROM bookings b
    JOIN customers c ON c.id = b.customer_id
    JOIN services  s ON s.id = b.service_id
    LEFT JOIN service_variants sv ON sv.id = b.variant_id
    WHERE b.id = ?
");
// receipt_url may not exist if migration not yet run — handle gracefully
$stmt->execute([$bookingId]);
$bk = $stmt->fetch();
if (!$bk) { header('Location: /admin/bookings'); exit; }

$addons = $db->prepare("SELECT ba.*, sa.name FROM booking_addons ba JOIN service_addons sa ON sa.id=ba.addon_id WHERE ba.booking_id=?");
$addons->execute([$bookingId]);
$addons = $addons->fetchAll();

$payments = $db->prepare("SELECT * FROM payments WHERE booking_id=? ORDER BY created_at DESC");
$payments->execute([$bookingId]);
$payments = $payments->fetchAll();

$pipelineOrder = $db->prepare("SELECT o.*, GROUP_CONCAT(p.name SEPARATOR ', ') as products FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN products p ON p.id=oi.product_id WHERE o.booking_id=? AND o.from_pipeline=1 GROUP BY o.id");
$pipelineOrder->execute([$bookingId]);
$pipelineOrder = $pipelineOrder->fetch();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'update_status') {
        $status = sanitize($_POST['status'] ?? '');
        if (in_array($status, ['pending','confirmed','completed','cancelled','incomplete'])) {
            // Auto-archive when cancelled. 'incomplete' is left un-archived so the
            // owner can still see it in the list and correct it to completed.
            $isArchived = $status === 'cancelled' ? 1 : 0;
            $db->prepare("UPDATE bookings SET status=?, is_archived=? WHERE id=?")
               ->execute([$status, $isArchived, $bookingId]);

            // Recognise/reverse revenue, forfeit deposit, award/reverse loyalty —
            // through the one canonical hook. This web path previously awarded
            // loyalty but never journalled revenue; routing it here fixes that.
            try { onBookingStatusChanged($db, (int)$bookingId, $status); }
            catch (Throwable $e) { error_log('onBookingStatusChanged (web status): ' . $e->getMessage()); }

            if ($status === 'confirmed') {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $freshBk = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id=$bookingId")->fetch();
                    emailBookingApproved(
                        $freshBk,
                        ['name' => $bk['c_name'], 'email' => $bk['c_email']],
                        ['name' => $bk['s_name']]
                    );
                } catch (Throwable $e) {
                    error_log('Confirm email error: ' . $e->getMessage());
                }
            }

            // (loyalty + revenue for 'completed' are handled by onBookingStatusChanged above)

            if ($status === 'cancelled') {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $freshBk = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id=$bookingId")->fetch();
                    if ($freshBk && !empty($bk['c_email'])) {
                        emailBookingRejected(
                            $freshBk,
                            ['name' => $bk['c_name'], 'email' => $bk['c_email']],
                            ['name' => $bk['s_name']]
                        );
                    }
                } catch (Throwable $e) {
                    error_log('Cancel email error: ' . $e->getMessage());
                }
            }
        }
    } elseif ($action === 'confirm_deposit') {
        $db->prepare("UPDATE bookings SET deposit_paid=1 WHERE id=?")->execute([$bookingId]);
        $db->prepare("UPDATE payments SET status='succeeded', confirmed_by='admin', confirmed_at=NOW() WHERE booking_id=? AND type='deposit'")->execute([$bookingId]);
        $db->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND status='pending'")->execute([$bookingId]);
        // Record the confirmed deposit as a held liability (CR 2000). Idempotent.
        try { journalBookingDeposit($db, (int)$bookingId); }
        catch (Throwable $e) { error_log('confirm_deposit journal: ' . $e->getMessage()); }
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $freshBk = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id=$bookingId")->fetch();
            emailBookingApproved(
                $freshBk,
                ['name' => $bk['c_name'], 'email' => $bk['c_email']],
                ['name' => $bk['s_name']]
            );
        } catch (Throwable $e) {
            error_log('Confirm deposit email error: ' . $e->getMessage());
        }
    } elseif ($action === 'reschedule') {
        $newDate = sanitize($_POST['new_date'] ?? '');
        $newTime = sanitize($_POST['new_time'] ?? '');
        if ($newDate && $newTime && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $newTime)) {
            $newTime = strlen($newTime) === 5 ? $newTime . ':00' : $newTime;
            // Duration-aware clash check via the consolidated primitives (excludes
            // this booking). Previously an exact date+time match that ignored
            // duration — so a longer service could be rescheduled to overlap the
            // one before it. Now consistent with the app's reschedule.
            $newStart = strtotime($newDate . ' ' . $newTime);
            $durStmt = $db->prepare(
                "SELECT COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60) AS dur
                 FROM bookings b JOIN services s ON s.id=b.service_id LEFT JOIN service_variants sv ON sv.id=b.variant_id
                 WHERE b.id=?"
            );
            $durStmt->execute([$bookingId]);
            $thisDur = (int)($durStmt->fetchColumn() ?: 60);
            $others  = loadDayBookings($newDate, (int)$bookingId);
            if (peakConcurrency($others, $newStart, $thisDur) >= slotCapacity($newDate)) {
                header("Location: /admin/bookings/$bookingId?msg=That+slot+clashes+with+another+booking.+Choose+another+time.");
                exit;
            }
            $db->prepare("UPDATE bookings SET booked_date=?, booked_time=?, status=IF(status='cancelled','confirmed',status) WHERE id=?")
               ->execute([$newDate, $newTime, $bookingId]);
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $freshBk = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id=$bookingId")->fetch();
                emailBookingRescheduled($freshBk, ['name' => $bk['c_name'], 'email' => $bk['c_email']], ['name' => $bk['s_name']]);
            } catch (Throwable $e) {
                error_log('Reschedule email error: ' . $e->getMessage());
            }
            header("Location: /admin/bookings/$bookingId?msg=Booking+rescheduled.");
            exit;
        }
    } elseif ($action === 'delete_booking') {
        // Only allow deletion of cancelled/archived bookings
        if (in_array($bk['status'], ['cancelled','rejected']) || $bk['is_archived']) {
            // Check for paid payments — block deletion if money was collected
            $paidStmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE booking_id=? AND status='succeeded'");
            $paidStmt->execute([$bookingId]);
            if ((int)$paidStmt->fetchColumn() > 0) {
                header("Location: /admin/bookings/$bookingId?msg=Cannot+delete:+this+booking+has+a+payment+record.");
                exit;
            }
            $db->prepare("DELETE FROM booking_addons WHERE booking_id=?")->execute([$bookingId]);
            $db->prepare("DELETE FROM payments WHERE booking_id=?")->execute([$bookingId]);
            $db->prepare("DELETE FROM bookings WHERE id=?")->execute([$bookingId]);
            header("Location: /admin/bookings?msg=Booking+deleted.");
            exit;
        }
    } elseif ($action === 'update_notes') {
        $notes = sanitize($_POST['admin_notes'] ?? '');
        $db->prepare("UPDATE bookings SET admin_notes=? WHERE id=?")->execute([$notes, $bookingId]);
    } elseif ($action === 'regenerate_payment_link') {
        // Generate a fresh token (old one is invalidated)
        $newToken = bin2hex(random_bytes(32));
        $db->prepare("UPDATE bookings SET payment_token=? WHERE id=? AND deposit_paid=0")
           ->execute([$newToken, $bookingId]);
        header("Location: /admin/bookings/$bookingId?msg=Payment+link+regenerated.&show_payment_link=1");
        exit;
    } elseif ($action === 'revoke_payment_link') {
        $db->prepare("UPDATE bookings SET payment_token=NULL WHERE id=?")->execute([$bookingId]);

    // ── Phase C4: stylist assignments ───────────────────────
    // Each save recomputes earnings via recalcBookingAssignments() (C3), which
    // also stamps the right status from the booking's state. Payout-locked rows
    // are protected here and in the engine.
    } elseif ($action === 'assign_stylist') {
        $sid   = (int)($_POST['stylist_id'] ?? 0);
        $role  = in_array($_POST['assign_role'] ?? '', ['lead','assist'], true) ? $_POST['assign_role'] : 'lead';
        $model = in_array($_POST['pay_model'] ?? '', ['commission','hourly','none'], true) ? $_POST['pay_model'] : 'commission';
        // Empty rate/hours = NULL, so the engine falls back to the stylist default.
        $pct   = ($_POST['commission_pct'] ?? '') === '' ? null : max(0.0, min(100.0, round((float)$_POST['commission_pct'], 2)));
        $rate  = ($_POST['hourly_rate']    ?? '') === '' ? null : max(0.0, round((float)$_POST['hourly_rate'], 2));
        $hp    = ($_POST['hours_planned']  ?? '') === '' ? null : max(0.0, round((float)$_POST['hours_planned'], 2));
        $notes = sanitize($_POST['assign_notes'] ?? '');
        $redir = 'Stylist assigned.';
        if ($sid <= 0) {
            $redir = 'Pick a stylist to assign.';
        } else {
            try {
                $db->prepare("INSERT INTO booking_assignments
                    (booking_id, stylist_id, assign_role, pay_model, commission_pct, hourly_rate, hours_planned, notes, assigned_by)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$bookingId, $sid, $role, $model, $pct, $rate, $hp, $notes, (int)($_SESSION['admin_id'] ?? 0)]);
                recalcBookingAssignments($db, (int)$bookingId);
            } catch (PDOException $e) {
                $redir = ((string)$e->getCode() === '23000')
                       ? 'That stylist already has that role on this booking.'
                       : 'Could not assign stylist.';
                error_log('assign_stylist: ' . $e->getMessage());
            }
        }
        header("Location: /admin/bookings/$bookingId?msg=" . urlencode($redir)); exit;

    } elseif ($action === 'update_assignment') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $chk = $db->prepare("SELECT payout_id FROM booking_assignments WHERE id=? AND booking_id=?");
        $chk->execute([$aid, $bookingId]);
        $cur = $chk->fetch();
        if (!$cur) {
            $redir = 'Assignment not found.';
        } elseif ($cur['payout_id'] !== null) {
            $redir = 'That assignment is locked to a payout and cannot be edited.';
        } else {
            $role  = in_array($_POST['assign_role'] ?? '', ['lead','assist'], true) ? $_POST['assign_role'] : 'lead';
            $model = in_array($_POST['pay_model'] ?? '', ['commission','hourly','none'], true) ? $_POST['pay_model'] : 'commission';
            $pct   = ($_POST['commission_pct'] ?? '') === '' ? null : max(0.0, min(100.0, round((float)$_POST['commission_pct'], 2)));
            $rate  = ($_POST['hourly_rate']    ?? '') === '' ? null : max(0.0, round((float)$_POST['hourly_rate'], 2));
            $hp    = ($_POST['hours_planned']  ?? '') === '' ? null : max(0.0, round((float)$_POST['hours_planned'], 2));
            $hw    = ($_POST['hours_worked']   ?? '') === '' ? null : max(0.0, round((float)$_POST['hours_worked'], 2));
            $notes = sanitize($_POST['assign_notes'] ?? '');
            $db->prepare("UPDATE booking_assignments
                          SET assign_role=?, pay_model=?, commission_pct=?, hourly_rate=?, hours_planned=?, hours_worked=?, notes=?
                          WHERE id=?")
               ->execute([$role, $model, $pct, $rate, $hp, $hw, $notes, $aid]);
            recalcBookingAssignments($db, (int)$bookingId);
            $redir = 'Assignment updated.';
        }
        header("Location: /admin/bookings/$bookingId?msg=" . urlencode($redir)); exit;

    } elseif ($action === 'remove_assignment') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $chk = $db->prepare("SELECT payout_id FROM booking_assignments WHERE id=? AND booking_id=?");
        $chk->execute([$aid, $bookingId]);
        $cur = $chk->fetch();
        if (!$cur) {
            $redir = 'Assignment not found.';
        } elseif ($cur['payout_id'] !== null) {
            $redir = 'Locked to a payout — cannot remove.';
        } else {
            $db->prepare("DELETE FROM booking_assignments WHERE id=?")->execute([$aid]);
            $redir = 'Assignment removed.';
        }
        header("Location: /admin/bookings/$bookingId?msg=" . urlencode($redir)); exit;
    }
    header("Location: /admin/bookings/$bookingId?msg=Updated.");
    exit;
}


$pageTitle = $pageTitle ?? 'Booking Detail';
require_once __DIR__ . '/includes/layout.php';

// Refresh
$stmt->execute([$bookingId]); $bk = $stmt->fetch();
$msg = sanitize($_GET['msg'] ?? '');
$pageTitle = 'Booking — ' . $bk['booking_ref'];

// ── Phase C4: assignment picker + list data ─────────────────
// Skill / time-off flags are ADVISORY — the form warns but never blocks.
$svcId = (int)$bk['service_id'];
$bkDate = $bk['booked_date'];
// This booking's duration (same precedence as scheduling) — for the "already on
// another booking at this time" advisory in the picker.
$thisDur = (int)($db->query("SELECT COALESCE(NULLIF(b.duration_mins,0), NULLIF(sv.duration_mins,0), NULLIF(s.duration_mins,0), 60)
                             FROM bookings b JOIN services s ON s.id=b.service_id
                             LEFT JOIN service_variants sv ON sv.id=b.variant_id
                             WHERE b.id=" . (int)$bookingId)->fetchColumn() ?: 60);
$assignStylists = []; $skillAny = []; $canDoThis = []; $onTimeOff = []; $assignments = [];
try {
    $assignStylists = $db->query("SELECT id, name, stylist_type, default_commission_pct, default_hourly_rate, is_owner
                                  FROM stylists WHERE is_active = 1 ORDER BY is_owner DESC, name")->fetchAll();
    foreach ($db->query("SELECT DISTINCT stylist_id FROM stylist_services")->fetchAll(PDO::FETCH_COLUMN) as $x) $skillAny[(int)$x] = true;
    $cd = $db->prepare("SELECT stylist_id FROM stylist_services WHERE service_id = ?");
    $cd->execute([$svcId]);
    foreach ($cd->fetchAll(PDO::FETCH_COLUMN) as $x) $canDoThis[(int)$x] = true;
    $to = $db->prepare("SELECT DISTINCT stylist_id FROM stylist_time_off WHERE ? BETWEEN start_date AND end_date");
    $to->execute([$bkDate]);
    foreach ($to->fetchAll(PDO::FETCH_COLUMN) as $x) $onTimeOff[(int)$x] = true;
    $as = $db->prepare("SELECT ba.*, s.name AS stylist_name, s.is_owner
                        FROM booking_assignments ba JOIN stylists s ON s.id = ba.stylist_id
                        WHERE ba.booking_id = ? ORDER BY FIELD(ba.assign_role,'lead','assist'), s.name");
    $as->execute([$bookingId]);
    $assignments = $as->fetchAll();
} catch (Throwable $e) { /* stylist tables absent — card renders without a picker */ }

$assignTotal = 0.0;
foreach ($assignments as $a) if ($a['earnings_status'] !== 'void') $assignTotal += (float)$a['earnings_amount'];

/** [label, textColor, bgColor] for an earnings status. */
function earnBadge(string $status): array {
    return match ($status) {
        'earned' => ['Earned',  '#065f46', '#d1fae5'],
        'void'   => ['Void',    '#991b1b', '#fee2e2'],
        default  => ['Pending', '#854d0e', '#fef9c3'],
    };
}
function payModelLabel(string $m): string {
    return ['commission' => 'Commission', 'hourly' => 'Hourly', 'none' => 'No pay'][$m] ?? $m;
}
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php
// Show payment link banner when the booking has a token and deposit is unpaid
$showPayLink = !empty($bk['payment_token']) && !$bk['deposit_paid'];
$payLink     = $showPayLink ? (SITE_URL . '/pay?token=' . $bk['payment_token']) : '';
$highlightLink = ($_GET['show_payment_link'] ?? '') === '1';
?>
<?php if ($showPayLink): ?>
<div style="background:<?= $highlightLink ? 'linear-gradient(135deg,#f0e8ff,#e8d5ff)' : '#faf5ff' ?>;border:2px solid var(--admin-primary);border-radius:var(--admin-radius);padding:14px 18px;margin-bottom:20px">
  <p style="font-size:0.72rem;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:var(--admin-primary);margin-bottom:10px">🔗 Client Payment Link</p>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="text" id="payLinkInput" value="<?= htmlspecialchars($payLink) ?>"
           readonly style="flex:1;min-width:0;font-size:0.78rem;padding:8px 10px;border:1px solid var(--admin-border);border-radius:var(--admin-radius);background:#fff;color:var(--admin-text);font-family:monospace">
    <button type="button" onclick="copyPayLink()" id="copyPayLinkBtn"
            class="btn-admin btn-admin-primary btn-admin-sm" style="white-space:nowrap">
      📋 Copy Link
    </button>
    <a href="https://wa.me/44<?= ltrim($bk['c_phone'] ?? '', '0') ?>?text=<?= urlencode('Hi ' . $bk['c_name'] . ', here is your deposit payment link for your upcoming appointment: ' . $payLink) ?>"
       target="_blank" class="btn-admin btn-admin-outline btn-admin-sm" style="white-space:nowrap">
      💬 Send via WhatsApp
    </a>
    <a href="mailto:<?= htmlspecialchars($bk['c_email']) ?>?subject=<?= urlencode('Your BraidedbyAGB Deposit Payment Link') ?>&body=<?= urlencode("Hi {$bk['c_name']},\n\nHere is your deposit payment link for your upcoming appointment:\n{$payLink}\n\nSee you soon!\nBraidedbyAGB") ?>"
       class="btn-admin btn-admin-outline btn-admin-sm" style="white-space:nowrap">
      ✉️ Send via Email
    </a>
    <form method="POST" style="margin:0" onsubmit="return confirm('Regenerate payment link? The old link will stop working immediately.')">
      <input type="hidden" name="action" value="regenerate_payment_link">
      <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm" style="white-space:nowrap;color:var(--admin-muted)">
        🔄 Regenerate
      </button>
    </form>
    <form method="POST" style="margin:0" onsubmit="return confirm('Revoke this payment link? Client will no longer be able to pay through it.')">
      <input type="hidden" name="action" value="revoke_payment_link">
      <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm" style="white-space:nowrap;color:#991b1b">
        ✕ Revoke
      </button>
    </form>
  </div>
  <p style="font-size:0.7rem;color:var(--admin-muted);margin-top:8px">
    Send this link to your client so they can pay the <?= formatPrice($bk['deposit_amount']) ?> deposit directly — no rescheduling needed.
    Payment method allowed: <strong><?= ucwords(str_replace('_', ' ', $bk['payment_method_allowed'] ?? 'both')) ?></strong>.
  </p>
</div>
<script>
function copyPayLink() {
  var input = document.getElementById('payLinkInput');
  navigator.clipboard.writeText(input.value).then(function() {
    var btn = document.getElementById('copyPayLinkBtn');
    btn.textContent = '✓ Copied!';
    btn.style.background = '#059669';
    setTimeout(function() {
      btn.textContent = '📋 Copy Link';
      btn.style.background = '';
    }, 2500);
  });
}
</script>
<?php endif; ?>

<!-- Header actions -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px">
  <a href="/admin/bookings" class="btn-admin btn-admin-outline">← All Bookings</a>
  <span class="status-badge status-<?= $bk['status'] ?>" style="font-size:0.72rem;padding:6px 12px">
    <?= ucfirst($bk['status']) ?>
  </span>

  <!-- Status change -->
  <form method="POST" style="display:flex;gap:6px;margin-left:auto">
    <input type="hidden" name="action" value="update_status">
    <select name="status" class="admin-input admin-select" style="width:140px">
      <?php foreach (['pending','confirmed','completed','incomplete','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $bk['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-admin btn-admin-primary">Update</button>
  </form>

  <a href="https://wa.me/44<?= ltrim($bk['c_phone'], '0') ?>" target="_blank" class="btn-admin btn-admin-outline">💬 WhatsApp</a>
  <a href="mailto:<?= htmlspecialchars($bk['c_email']) ?>" class="btn-admin btn-admin-outline">✉️ Email</a>

  <!-- Reschedule -->
  <?php if (in_array($bk['status'], ['pending','confirmed'])): ?>
  <button type="button" onclick="document.getElementById('reschedulePanel').classList.toggle('hidden')"
          class="btn-admin btn-admin-outline" style="margin-left:auto">📅 Reschedule</button>
  <?php endif; ?>

  <!-- Delete (cancelled only, no payment) -->
  <?php if (in_array($bk['status'], ['cancelled','rejected']) || $bk['is_archived']): ?>
  <form method="POST" style="margin:0" onsubmit="return confirm('Permanently delete this booking? This cannot be undone.')">
    <input type="hidden" name="action" value="delete_booking">
    <button type="submit" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">🗑 Delete</button>
  </form>
  <?php endif; ?>
</div>

<!-- Reschedule panel -->
<div id="reschedulePanel" class="hidden" style="background:#faf5ff;border:1px solid var(--admin-primary);border-radius:var(--admin-radius);padding:16px;margin-bottom:20px">
  <p style="font-weight:700;font-size:0.8rem;color:var(--admin-primary);margin-bottom:12px">📅 Reschedule Appointment</p>
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="action" value="reschedule">
    <div class="admin-form-group" style="margin:0">
      <label class="admin-label" style="font-size:0.72rem">New Date</label>
      <input type="date" name="new_date" class="admin-input" style="width:160px"
             value="<?= htmlspecialchars($bk['booked_date']) ?>"
             min="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="admin-form-group" style="margin:0">
      <label class="admin-label" style="font-size:0.72rem">New Time</label>
      <input type="time" name="new_time" class="admin-input" style="width:130px"
             value="<?= substr($bk['booked_time'],0,5) ?>"
             step="1800" required>
    </div>
    <button type="submit" class="btn-admin btn-admin-primary">Confirm Reschedule</button>
    <button type="button" onclick="document.getElementById('reschedulePanel').classList.add('hidden')"
            class="btn-admin btn-admin-outline">Cancel</button>
  </form>
  <p style="font-size:0.7rem;color:var(--admin-muted);margin-top:8px">
    A rescheduling email will be sent to <?= htmlspecialchars($bk['c_email']) ?> automatically.
  </p>
</div>
<style>#reschedulePanel.hidden{display:none}</style>

<div class="detail-grid">

  <!-- Booking info -->
  <div class="detail-card">
    <div class="detail-card-title">📅 Booking Details</div>
    <div class="detail-row"><span class="dl">Reference</span><span class="dv td-ref"><?= htmlspecialchars($bk['booking_ref']) ?></span></div>
    <?php if (!empty($bk['cart_group_ref'])):
        $grpCount = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE cart_group_ref=" . $db->quote($bk['cart_group_ref']))->fetchColumn();
    ?>
    <div class="detail-row"><span class="dl">Group</span><span class="dv">👪 <?= htmlspecialchars($bk['cart_group_ref']) ?> <span class="td-muted">(<?= $grpCount ?> appointment<?= $grpCount !== 1 ? 's' : '' ?> booked together)</span></span></div>
    <?php endif; ?>
    <?php if (!empty($bk['guest_name'])): ?>
    <div class="detail-row"><span class="dl">For</span><span class="dv"><strong><?= htmlspecialchars($bk['guest_name']) ?></strong></span></div>
    <?php endif; ?>
    <div class="detail-row"><span class="dl">Service</span><span class="dv"><?= htmlspecialchars($bk['s_name']) ?><?= $bk['variant_name'] ? ' — ' . $bk['variant_name'] : '' ?></span></div>
    <?php if (($bk['service_location'] ?? 'salon') === 'home'): ?>
    <div class="detail-row"><span class="dl">📍 Location</span><span class="dv"><strong style="color:#8e2de2">Home service</strong><?= $bk['travel_area'] ? ' — ' . htmlspecialchars(ucwords(str_replace('_', ' / ', $bk['travel_area']))) : '' ?></span></div>
    <?php if (!empty($bk['service_address'])): ?>
    <div class="detail-row"><span class="dl">Address</span><span class="dv"><?= nl2br(htmlspecialchars($bk['service_address'])) ?></span></div>
    <?php endif; ?>
    <?php if ((float)($bk['travel_fee'] ?? 0) > 0): ?>
    <div class="detail-row"><span class="dl">Travel fee (paid)</span><span class="dv"><?= formatPrice($bk['travel_fee']) ?></span></div>
    <?php endif; ?>
    <?php endif; ?>
    <div class="detail-row"><span class="dl">Date</span><span class="dv"><?= formatDate($bk['booked_date'], 'l, j F Y') ?></span></div>
    <div class="detail-row"><span class="dl">Time</span><span class="dv"><?= formatTime($bk['booked_time']) ?></span></div>
    <?php if (!empty($addons)): ?>
    <div class="detail-row">
      <span class="dl">Add-ons</span>
      <span class="dv"><?= implode(', ', array_column($addons, 'name')) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($bk['client_notes']): ?>
    <div class="detail-row"><span class="dl">Client notes</span><span class="dv"><?= htmlspecialchars($bk['client_notes']) ?></span></div>
    <?php endif; ?>
    <?php
      $mc = $bk['media_consent'] ?? 'none';
      $mcLabel = $mc === 'hair_face' ? '✅ Yes — hair & face' : ($mc === 'hair' ? '✅ Hair only (no face)' : '⛔ No — do not post');
      $mcColor = $mc === 'none' ? '#c0392b' : '#1d9e75';
    ?>
    <div class="detail-row"><span class="dl">📸 Social media</span><span class="dv" style="color:<?= $mcColor ?>;font-weight:600"><?= $mcLabel ?></span></div>
    <div class="detail-row"><span class="dl">Booked on</span><span class="dv td-muted"><?= date('j M Y', strtotime($bk['created_at'])) ?></span></div>
  </div>

  <!-- Client info -->
  <div class="detail-card">
    <div class="detail-card-title">👤 Client</div>
    <div class="detail-row"><span class="dl">Name</span><span class="dv"><?= htmlspecialchars($bk['c_name']) ?></span></div>
    <div class="detail-row"><span class="dl">Email</span><span class="dv"><a href="mailto:<?= htmlspecialchars($bk['c_email']) ?>"><?= htmlspecialchars($bk['c_email']) ?></a></span></div>
    <div class="detail-row"><span class="dl">Phone</span><span class="dv"><a href="tel:<?= htmlspecialchars($bk['c_phone']) ?>"><?= htmlspecialchars($bk['c_phone']) ?></a></span></div>
    <div class="detail-row"><span class="dl">Policy accepted</span><span class="dv"><?= $bk['policy_accepted'] ? '✓ Yes' : '✗ No' ?></span></div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <a href="https://wa.me/44<?= ltrim($bk['c_phone'] ?? '', '0') ?>" target="_blank" class="btn-admin btn-admin-primary btn-admin-sm">💬 WhatsApp</a>
      <a href="/admin/customers/<?= (int)$bk['customer_id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">👤 View profile</a>
    </div>
  </div>

  <!-- Payment -->
  <div class="detail-card">
    <div class="detail-card-title">💳 Payment</div>
    <div class="detail-row"><span class="dl">Total price</span><span class="dv" style="color:var(--admin-primary)"><?= formatPrice($bk['total_price']) ?></span></div>
    <div class="detail-row">
      <span class="dl">Deposit (<?= getSetting('deposit_percent','30') ?>%)</span>
      <span class="dv">
        <?= formatPrice($bk['deposit_amount']) ?>
        <?php if ($bk['deposit_paid']): ?>
          <span class="status-badge status-confirmed" style="margin-left:6px">Paid ✓</span>
        <?php else: ?>
          <span class="status-badge status-pending" style="margin-left:6px">Unpaid</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="detail-row"><span class="dl">Balance on day</span><span class="dv"><?= formatPrice($bk['remaining_balance']) ?></span></div>
    <div class="detail-row"><span class="dl">Method</span><span class="dv"><?= ucfirst(str_replace('_',' ',$bk['payment_method'])) ?></span></div>

    <?php if ($bk['payment_method'] === 'bank_transfer'): ?>
      <?php
        // Safely get receipt_url — column may not exist if migration not run
        $receiptUrl = $bk['receipt_url'] ?? null;
      ?>
      <?php if ($receiptUrl): ?>
        <div style="margin-top:14px;padding:12px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:var(--admin-radius)">
          <p style="font-size:0.75rem;font-weight:700;color:#166534;margin-bottom:8px">📎 Transfer Receipt Uploaded</p>
          <?php
            $ext = strtolower(pathinfo($receiptUrl, PATHINFO_EXTENSION));
            $isPdf = $ext === 'pdf';
          ?>
          <?php if (!$isPdf): ?>
            <img src="<?= htmlspecialchars($receiptUrl) ?>" alt="Transfer receipt"
                 style="max-width:100%;max-height:280px;object-fit:contain;border-radius:4px;border:1px solid #bbf7d0;display:block;margin-bottom:8px">
          <?php endif; ?>
          <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank"
             class="btn-admin btn-admin-outline btn-admin-sm" style="display:inline-flex;align-items:center;gap:5px">
            <?= $isPdf ? '📄 View PDF Receipt' : '🔍 Open Full Size' ?>
          </a>
        </div>
      <?php else: ?>
        <div style="margin-top:12px;padding:10px 14px;background:#fef9c3;border:1px solid #fde047;border-radius:var(--admin-radius)">
          <p style="font-size:0.75rem;color:#854d0e">⚠️ No receipt uploaded yet. Check WhatsApp or chase the client.</p>
        </div>
      <?php endif; ?>

      <?php if (!$bk['deposit_paid']): ?>
      <form method="POST" style="margin-top:10px">
        <input type="hidden" name="action" value="confirm_deposit">
        <button type="submit" class="btn-admin btn-admin-success"
                onclick="return confirm('Confirm bank transfer received and mark deposit as paid?')">
          ✓ Mark Deposit as Received
        </button>
      </form>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($payments)): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--admin-border)">
      <?php foreach ($payments as $pay): ?>
      <div style="font-size:0.72rem;color:var(--admin-muted);margin-bottom:3px">
        <?= ucfirst($pay['type']) ?> · <?= formatPrice($pay['amount']) ?> ·
        <span class="status-badge status-<?= $pay['status'] === 'succeeded' ? 'confirmed' : 'pending' ?>"><?= $pay['status'] ?></span>
        <?php if ($pay['stripe_id']): ?>
          · <code style="font-size:0.65rem"><?= substr($pay['stripe_id'],0,20) ?>…</code>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Pipeline / notes -->
  <div class="detail-card">
    <div class="detail-card-title">🔗 Pipeline Order & Notes</div>
    <?php if ($pipelineOrder): ?>
    <div style="background:#faf8fd;border:1px solid var(--admin-border);border-radius:var(--admin-radius);padding:10px 14px;margin-bottom:12px;font-size:0.8rem">
      <strong>Pipeline order placed:</strong> <?= htmlspecialchars($pipelineOrder['products']) ?><br>
      <span style="color:var(--admin-muted)">£<?= number_format($pipelineOrder['total'],2) ?> ·
      <span class="status-badge status-<?= $pipelineOrder['status'] ?>"><?= ucfirst($pipelineOrder['status']) ?></span>
      </span>
      <a href="/admin/orders/<?= $pipelineOrder['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm" style="margin-top:6px;display:inline-block">View order</a>
    </div>
    <?php else: ?>
    <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:12px">No pipeline order for this booking.</p>
    <?php endif; ?>

    <form method="POST">
      <input type="hidden" name="action" value="update_notes">
      <div class="admin-form-group">
        <label class="admin-label">Admin Notes (internal only)</label>
        <textarea name="admin_notes" class="admin-input admin-textarea" rows="4"
                  placeholder="Internal notes about this booking…"><?= htmlspecialchars($bk['admin_notes'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn-admin btn-admin-primary">Save Notes</button>
    </form>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- Phase C4 — Stylists & Earnings                             -->
<!-- ══════════════════════════════════════════════════════════ -->
<div class="detail-card" style="margin-top:20px">
  <div class="detail-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span>💇 Stylists &amp; Earnings</span>
    <span style="font-size:0.78rem;font-weight:600;color:var(--admin-muted)">
      <?php $earnedWord = $bk['status'] === 'completed' ? 'earned' : 'projected'; ?>
      <?= count(array_filter($assignments, fn($a) => $a['earnings_status'] !== 'void')) ?> assigned ·
      <?= formatPrice($assignTotal) ?> <?= $earnedWord ?>
    </span>
  </div>

  <p style="font-size:0.72rem;color:var(--admin-muted);margin:-4px 0 14px">
    Earnings become <strong>Earned</strong> when this booking is marked <em>completed</em> (same rule as revenue).
    Nothing is paid until a payout runs. Line total for commission: <strong><?= formatPrice($bk['total_price']) ?></strong>.
  </p>

  <?php if (empty($assignments)): ?>
    <p style="font-size:0.82rem;color:var(--admin-muted);margin-bottom:14px">No stylist assigned yet.</p>
  <?php else: foreach ($assignments as $a):
      [$bLabel,$bFg,$bBg] = earnBadge($a['earnings_status']);
      $locked = $a['payout_id'] !== null;
  ?>
    <div style="border:1px solid var(--admin-border);border-radius:var(--admin-radius);padding:12px 14px;margin-bottom:10px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <strong style="font-size:0.9rem"><?= htmlspecialchars($a['stylist_name']) ?></strong>
        <span class="status-badge" style="background:#ede9fe;color:#5b21b6"><?= ucfirst($a['assign_role']) ?></span>
        <span style="font-size:0.75rem;color:var(--admin-muted)"><?= payModelLabel($a['pay_model']) ?></span>
        <span style="margin-left:auto;font-weight:700;color:var(--admin-primary)"><?= formatPrice($a['earnings_amount']) ?></span>
        <span class="status-badge" style="background:<?= $bBg ?>;color:<?= $bFg ?>"><?= $bLabel ?><?= $locked ? ' · paid' : '' ?></span>
      </div>
      <div style="font-size:0.72rem;color:var(--admin-muted);margin-top:4px">
        <?php if ($a['pay_model'] === 'commission'): ?>
          <?= rtrim(rtrim(number_format((float)($a['commission_pct'] ?? 0), 2), '0'), '.') ?: '(default)' ?>% of <?= formatPrice($a['earnings_base'] ?? $bk['total_price']) ?>
        <?php elseif ($a['pay_model'] === 'hourly'): ?>
          <?= rtrim(rtrim(number_format((float)($a['hours_worked'] ?? $a['hours_planned'] ?? 0), 2), '0'), '.') ?>h ×
          £<?= number_format((float)($a['hourly_rate'] ?? 0), 2) ?>/hr
          <?= $a['hours_worked'] === null ? '(planned)' : '(worked)' ?>
        <?php else: ?>
          No payout for this assignment
        <?php endif; ?>
        <?php if (!empty($a['notes'])): ?> · <?= htmlspecialchars($a['notes']) ?><?php endif; ?>
      </div>

      <?php if (!$locked): ?>
      <div style="margin-top:8px;display:flex;gap:8px">
        <button type="button" class="btn-admin btn-admin-outline btn-admin-sm"
                onclick="document.getElementById('edit-a-<?= (int)$a['id'] ?>').classList.toggle('hidden')">✎ Edit</button>
        <form method="POST" style="margin:0" onsubmit="return confirm('Remove this assignment?')">
          <input type="hidden" name="action" value="remove_assignment">
          <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
          <button type="submit" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Remove</button>
        </form>
      </div>

      <!-- Inline edit -->
      <div id="edit-a-<?= (int)$a['id'] ?>" class="hidden" style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--admin-border)">
        <form method="POST" class="assign-form" onsubmit="return true">
          <input type="hidden" name="action" value="update_assignment">
          <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="admin-form-group" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Role</label>
              <select name="assign_role" class="admin-input admin-select" style="width:110px">
                <option value="lead"   <?= $a['assign_role']==='lead'?'selected':'' ?>>Lead</option>
                <option value="assist" <?= $a['assign_role']==='assist'?'selected':'' ?>>Assist</option>
              </select>
            </div>
            <div class="admin-form-group" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Pay model</label>
              <select name="pay_model" class="admin-input admin-select pay-model" style="width:130px" onchange="payModelToggle(this)">
                <?php foreach (['commission','hourly','none'] as $pm): ?>
                  <option value="<?= $pm ?>" <?= $a['pay_model']===$pm?'selected':'' ?>><?= payModelLabel($pm) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-form-group pm-commission" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Commission %</label>
              <input type="number" name="commission_pct" class="admin-input" style="width:110px" min="0" max="100" step="0.01"
                     value="<?= $a['commission_pct'] !== null ? htmlspecialchars((string)$a['commission_pct']) : '' ?>" placeholder="default">
            </div>
            <div class="admin-form-group pm-hourly" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Rate £/hr</label>
              <input type="number" name="hourly_rate" class="admin-input" style="width:100px" min="0" step="0.01"
                     value="<?= $a['hourly_rate'] !== null ? htmlspecialchars((string)$a['hourly_rate']) : '' ?>" placeholder="default">
            </div>
            <div class="admin-form-group pm-hourly" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Hours planned</label>
              <input type="number" name="hours_planned" class="admin-input" style="width:110px" min="0" step="0.25"
                     value="<?= $a['hours_planned'] !== null ? htmlspecialchars((string)$a['hours_planned']) : '' ?>">
            </div>
            <div class="admin-form-group pm-hourly" style="margin:0">
              <label class="admin-label" style="font-size:0.7rem">Hours worked</label>
              <input type="number" name="hours_worked" class="admin-input" style="width:110px" min="0" step="0.25"
                     value="<?= $a['hours_worked'] !== null ? htmlspecialchars((string)$a['hours_worked']) : '' ?>">
            </div>
            <div class="admin-form-group" style="margin:0;flex:1;min-width:140px">
              <label class="admin-label" style="font-size:0.7rem">Note</label>
              <input type="text" name="assign_notes" class="admin-input" value="<?= htmlspecialchars($a['notes'] ?? '') ?>">
            </div>
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Save</button>
          </div>
        </form>
      </div>
      <?php else: ?>
        <p style="font-size:0.68rem;color:var(--admin-muted);margin-top:6px">🔒 Locked — included in a payout, so it can't be changed here.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

  <!-- Assign a new stylist -->
  <?php if (!empty($assignStylists)): ?>
  <button type="button" class="btn-admin btn-admin-outline btn-admin-sm" onclick="document.getElementById('assignPanel').classList.toggle('hidden')" style="margin-top:6px">+ Assign a stylist</button>
  <div id="assignPanel" class="hidden" style="margin-top:12px;padding:14px;background:#faf5ff;border:1px solid var(--admin-primary);border-radius:var(--admin-radius)">
    <form method="POST" class="assign-form">
      <input type="hidden" name="action" value="assign_stylist">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div class="admin-form-group" style="margin:0">
          <label class="admin-label" style="font-size:0.7rem">Stylist</label>
          <select name="stylist_id" class="admin-input admin-select" style="width:190px" onchange="stylistPick(this)" required>
            <option value="">— Select —</option>
            <?php foreach ($assignStylists as $s):
                $sid = (int)$s['id'];
                $warn = [];
                if (!empty($skillAny[$sid]) && empty($canDoThis[$sid])) $warn[] = 'not skilled for this service';
                if (!empty($onTimeOff[$sid])) $warn[] = 'on time off this day';
                if (!isStylistFree($bkDate, $bk['booked_time'], $thisDur, $sid, (int)$bookingId)) $warn[] = 'already on another booking at this time';
            ?>
              <option value="<?= $sid ?>"
                      data-comm="<?= htmlspecialchars((string)$s['default_commission_pct']) ?>"
                      data-rate="<?= htmlspecialchars((string)$s['default_hourly_rate']) ?>"
                      data-warn="<?= htmlspecialchars(implode('; ', $warn)) ?>">
                <?= htmlspecialchars($s['name']) ?><?= $s['is_owner'] ? ' (owner)' : '' ?><?= $warn ? ' ⚠' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-form-group" style="margin:0">
          <label class="admin-label" style="font-size:0.7rem">Role</label>
          <select name="assign_role" class="admin-input admin-select" style="width:110px">
            <option value="lead">Lead</option>
            <option value="assist">Assist</option>
          </select>
        </div>
        <div class="admin-form-group" style="margin:0">
          <label class="admin-label" style="font-size:0.7rem">Pay model</label>
          <select name="pay_model" class="admin-input admin-select pay-model" style="width:130px" onchange="payModelToggle(this)">
            <option value="commission">Commission</option>
            <option value="hourly">Hourly</option>
            <option value="none">No pay</option>
          </select>
        </div>
        <div class="admin-form-group pm-commission" style="margin:0">
          <label class="admin-label" style="font-size:0.7rem">Commission %</label>
          <input type="number" name="commission_pct" class="admin-input" style="width:110px" min="0" max="100" step="0.01" placeholder="default">
        </div>
        <div class="admin-form-group pm-hourly" style="margin:0;display:none">
          <label class="admin-label" style="font-size:0.7rem">Rate £/hr</label>
          <input type="number" name="hourly_rate" class="admin-input" style="width:100px" min="0" step="0.01" placeholder="default">
        </div>
        <div class="admin-form-group pm-hourly" style="margin:0;display:none">
          <label class="admin-label" style="font-size:0.7rem">Hours planned</label>
          <input type="number" name="hours_planned" class="admin-input" style="width:110px" min="0" step="0.25">
        </div>
        <div class="admin-form-group" style="margin:0;flex:1;min-width:140px">
          <label class="admin-label" style="font-size:0.7rem">Note</label>
          <input type="text" name="assign_notes" class="admin-input" placeholder="Optional">
        </div>
        <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Assign</button>
      </div>
      <p id="assignWarn" style="font-size:0.72rem;color:#b45309;margin-top:8px;display:none"></p>
    </form>
  </div>
  <?php endif; ?>
</div>

<style>
.assign-form .pm-hourly{display:none}
#assignPanel.hidden, [id^="edit-a-"].hidden{display:none}
</style>
<script>
// Show the fields that matter for the chosen pay model, within THIS form only.
function payModelToggle(sel){
  var form = sel.closest('.assign-form');
  var model = sel.value;
  form.querySelectorAll('.pm-commission').forEach(function(el){ el.style.display = (model==='commission')?'':'none'; });
  form.querySelectorAll('.pm-hourly').forEach(function(el){ el.style.display = (model==='hourly')?'':'none'; });
}
// Prefill rate placeholders from the stylist's defaults + surface advisory warnings.
function stylistPick(sel){
  var opt = sel.options[sel.selectedIndex];
  var form = sel.closest('.assign-form');
  var comm = form.querySelector('input[name="commission_pct"]');
  var rate = form.querySelector('input[name="hourly_rate"]');
  if (comm) comm.placeholder = opt.dataset.comm ? ('default ' + opt.dataset.comm + '%') : 'default';
  if (rate) rate.placeholder = opt.dataset.rate ? ('default £' + opt.dataset.rate) : 'default';
  var warnEl = document.getElementById('assignWarn');
  if (warnEl){
    if (opt.dataset.warn){
      warnEl.textContent = '⚠ ' + opt.text.replace(' ⚠','') + ' — ' + opt.dataset.warn + '. You can still assign.';
      warnEl.style.display = '';
    } else {
      warnEl.style.display = 'none';
    }
  }
}
// Initialise every pay-model select on load (edit forms may start as hourly/none).
document.querySelectorAll('.assign-form .pay-model').forEach(payModelToggle);
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
