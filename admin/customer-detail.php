<?php
// ============================================================
// BraidedbyAGB — Admin CRM Customer Profile
// FILE: /admin/customer-detail.php
// ============================================================
$pageTitle = 'Customer Profile';
require_once __DIR__ . '/includes/layout.php';

$customerId = (int)($_GET['id'] ?? 0);
if (!$customerId) redirect('/admin/customers');

// ── POST action handling ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note) {
            $db->prepare("INSERT INTO customer_notes (customer_id, note) VALUES (?, ?)")
               ->execute([$customerId, $note]);
        }
        redirect("/admin/customers/$customerId#notes");
    }

    if ($action === 'update_tags') {
        $allowed  = ['VIP', 'Regular', 'New Client', 'At Risk'];
        $selected = array_filter((array)($_POST['tags'] ?? []), fn($t) => in_array($t, $allowed));
        $tags     = implode(',', $selected);
        $db->prepare("UPDATE customers SET tags=? WHERE id=?")
           ->execute([$tags ?: null, $customerId]);
        redirect("/admin/customers/$customerId#tags");
    }

    if ($action === 'update_hair_notes') {
        $hair = trim($_POST['hair_notes'] ?? '');
        $db->prepare("UPDATE customers SET hair_notes=? WHERE id=?")
           ->execute([$hair ?: null, $customerId]);
        redirect("/admin/customers/$customerId#hair");
    }

    if ($action === 'block') {
        $reason = trim($_POST['block_reason'] ?? '');
        $db->prepare("UPDATE customers SET is_blocked=1, block_reason=?, blocked_at=NOW() WHERE id=?")
           ->execute([$reason ?: null, $customerId]);
        redirect("/admin/customers/$customerId");
    }

    if ($action === 'unblock') {
        $db->prepare("UPDATE customers SET is_blocked=0, block_reason=NULL, blocked_at=NULL WHERE id=?")
           ->execute([$customerId]);
        redirect("/admin/customers/$customerId");
    }

    if ($action === 'adjust_points') {
        $delta = (int)($_POST['points']      ?? 0);
        $desc  = trim($_POST['description']  ?? '') ?: ($delta > 0 ? 'Manual addition' : 'Manual removal');
        $type  = $delta > 0 ? 'manual_add' : 'manual_remove';
        if ($delta !== 0) {
            adjustLoyaltyPoints($customerId, $delta, $type, $desc);
        }
        redirect("/admin/customers/$customerId#loyalty");
    }

    if ($action === 'delete') {
        // Guard: refuse if active bookings exist
        $activeStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id=? AND status IN ('pending','confirmed')");
        $activeStmt->execute([$customerId]);
        if ((int)$activeStmt->fetchColumn() > 0) {
            redirect("/admin/customers/$customerId?error=Cannot+delete%3A+client+has+active+bookings.+Cancel+them+first.");
        }
        // Delete all related records then the customer
        $bookingIds = $db->prepare("SELECT id FROM bookings WHERE customer_id=?");
        $bookingIds->execute([$customerId]);
        foreach ($bookingIds->fetchAll(PDO::FETCH_COLUMN) as $bid) {
            $db->prepare("DELETE FROM booking_addons WHERE booking_id=?")->execute([$bid]);
            $db->prepare("DELETE FROM payments WHERE booking_id=?")->execute([$bid]);
        }
        $db->prepare("DELETE FROM bookings WHERE customer_id=?")->execute([$customerId]);
        $orderIds = $db->prepare("SELECT id FROM orders WHERE customer_id=?");
        $orderIds->execute([$customerId]);
        foreach ($orderIds->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$oid]);
        }
        $db->prepare("DELETE FROM orders WHERE customer_id=?")->execute([$customerId]);
        $db->prepare("DELETE FROM loyalty_transactions WHERE customer_id=?")->execute([$customerId]);
        $db->prepare("DELETE FROM customer_notes WHERE customer_id=?")->execute([$customerId]);
        $db->prepare("DELETE FROM customers WHERE id=?")->execute([$customerId]);
        redirect("/admin/customers?msg=Client+deleted+successfully.");
    }

    redirect("/admin/customers/$customerId");
}

// ── Fetch customer ────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM customers WHERE id=?");
$stmt->execute([$customerId]);
$c = $stmt->fetch();
if (!$c) redirect('/admin/customers');

// Bookings
$bstmt = $db->prepare("
    SELECT b.id, b.booking_ref, b.booked_date, b.booked_time, b.status,
           b.total_price, b.deposit_paid, b.payment_method,
           s.name as s_name, sv.variant_name
    FROM bookings b
    JOIN services s ON s.id=b.service_id
    LEFT JOIN service_variants sv ON sv.id=b.variant_id
    WHERE b.customer_id=?
    ORDER BY b.booked_date DESC
    LIMIT 20
");
$bstmt->execute([$customerId]);
$bookings = $bstmt->fetchAll();

$ltvStmt = $db->prepare("SELECT COALESCE(SUM(total_price),0) FROM bookings WHERE customer_id=? AND status='completed'");
$ltvStmt->execute([$customerId]);
$ltv = (float)$ltvStmt->fetchColumn();

$completedCount = count(array_filter($bookings, fn($b) => $b['status'] === 'completed'));
$cancelledCount = count(array_filter($bookings, fn($b) => in_array($b['status'], ['cancelled','late_cancelled','no_show'])));

// Admin notes
$nstmt = $db->prepare("SELECT * FROM customer_notes WHERE customer_id=? ORDER BY created_at DESC LIMIT 20");
$nstmt->execute([$customerId]);
$notes = $nstmt->fetchAll();

// Loyalty history
$lstmt = $db->prepare("SELECT * FROM loyalty_transactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 15");
$lstmt->execute([$customerId]);
$loyaltyHistory = $lstmt->fetchAll();

// Tags
$tags    = array_filter(array_map('trim', explode(',', $c['tags'] ?? '')));
$allTags = ['VIP', 'Regular', 'New Client', 'At Risk'];

// Status badge helper
function statusBadge(string $status): string {
    $map = [
        'pending'       => ['Pending',        '#f59e0b', '#fffbeb'],
        'confirmed'     => ['Confirmed',       '#16a34a', '#f0fdf4'],
        'completed'     => ['Completed',       '#7c3aed', '#f5f3ff'],
        'cancelled'     => ['Cancelled',       '#dc2626', '#fef2f2'],
        'late_cancelled'=> ['Late Cancel',     '#dc2626', '#fef2f2'],
        'no_show'       => ['No Show',         '#9ca3af', '#f9fafb'],
    ];
    [$label, $color, $bg] = $map[$status] ?? [ucfirst($status), '#6b7280', '#f3f4f6'];
    return "<span style='display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.72rem;font-weight:700;color:{$color};background:{$bg}'>{$label}</span>";
}
?>

<?php if (!empty($_GET['error'])): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:0.85rem;color:#991b1b">
  ⚠ <?= htmlspecialchars($_GET['error']) ?>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header" style="margin-bottom:20px">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="/admin/customers" class="btn-admin btn-admin-outline btn-admin-sm">← Back</a>
    <div>
      <h2 class="section-heading"><?= htmlspecialchars($c['name']) ?></h2>
      <p style="color:var(--admin-muted);font-size:0.8rem">
        Client since <?= date('F Y', strtotime($c['created_at'])) ?>
        <?php if ($c['is_blocked']): ?>
          &nbsp;<span style="color:#dc2626;font-weight:700">⚠ BLOCKED</span>
        <?php endif; ?>
      </p>
    </div>
  </div>
</div>

<?php if ($c['is_blocked']): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center">
  <div>
    <strong style="color:#dc2626">⚠ This client is blocked</strong>
    <?php if ($c['block_reason']): ?>
      <span style="color:#9f1239"> — <?= htmlspecialchars($c['block_reason']) ?></span>
    <?php endif; ?>
    <?php if ($c['blocked_at']): ?>
      <span style="color:#9ca3af;font-size:0.78rem"> · Blocked <?= date('j M Y', strtotime($c['blocked_at'])) ?></span>
    <?php endif; ?>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="unblock">
    <button class="btn-admin btn-admin-outline btn-admin-sm">Unblock</button>
  </form>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Identity card -->
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Contact</h3>
    <div style="font-size:0.92rem;color:var(--admin-text);line-height:1.9">
      <div>📧 <a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--admin-primary)"><?= htmlspecialchars($c['email']) ?></a></div>
      <div>📱 <a href="https://wa.me/44<?= ltrim(preg_replace('/\s+/', '', $c['phone'] ?? ''), '0') ?>" target="_blank" style="color:#16a34a"><?= htmlspecialchars($c['phone'] ?? '—') ?></a></div>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px">
      <a href="https://wa.me/44<?= ltrim(preg_replace('/\s+/', '', $c['phone'] ?? ''), '0') ?>" target="_blank"
         class="btn-admin btn-admin-sm" style="background:#25D366;color:#fff;border:none">💬 WhatsApp</a>
      <a href="mailto:<?= htmlspecialchars($c['email']) ?>" class="btn-admin btn-admin-outline btn-admin-sm">✉️ Email</a>
    </div>
  </div>

  <!-- Metrics card -->
  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Metrics</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div style="text-align:center;padding:10px;background:#faf5ff;border-radius:8px">
        <div style="font-size:1.4rem;font-weight:800;color:var(--admin-primary)">£<?= number_format($ltv, 2) ?></div>
        <div style="font-size:0.72rem;color:var(--admin-muted)">Lifetime Value</div>
      </div>
      <div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px">
        <div style="font-size:1.4rem;font-weight:800;color:#16a34a"><?= $completedCount ?></div>
        <div style="font-size:0.72rem;color:var(--admin-muted)">Completed</div>
      </div>
      <div style="text-align:center;padding:10px;background:#fffbeb;border-radius:8px">
        <div style="font-size:1.4rem;font-weight:800;color:#d97706"><?= count($bookings) ?></div>
        <div style="font-size:0.72rem;color:var(--admin-muted)">Total Bookings</div>
      </div>
      <div style="text-align:center;padding:10px;background:#fefce8;border-radius:8px">
        <div style="font-size:1.4rem;font-weight:800;color:#ca8a04"><?= (int)$c['loyalty_points'] ?> pts</div>
        <div style="font-size:0.72rem;color:var(--admin-muted)">Loyalty Points</div>
      </div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <!-- Tags -->
  <div id="tags" style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Tags</h3>
    <form method="POST">
      <input type="hidden" name="action" value="update_tags">
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <?php foreach ($allTags as $tag): ?>
          <?php $active = in_array($tag, $tags); ?>
          <label style="cursor:pointer">
            <input type="checkbox" name="tags[]" value="<?= $tag ?>" <?= $active ? 'checked' : '' ?> style="display:none" onchange="this.closest('form').submit()">
            <span style="display:inline-block;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:600;
                         border:2px solid <?= $active ? 'var(--admin-primary)' : 'var(--admin-border,#e5e7eb)' ?>;
                         background:<?= $active ? 'var(--admin-primary)' : '#fff' ?>;
                         color:<?= $active ? '#fff' : 'var(--admin-muted)' ?>">
              <?= htmlspecialchars($tag) ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <!-- Loyalty points adjustment -->
  <div id="loyalty" style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Adjust Loyalty Points</h3>
    <form method="POST" style="display:flex;flex-direction:column;gap:8px">
      <input type="hidden" name="action" value="adjust_points">
      <div style="display:flex;gap:8px">
        <input type="number" name="points" placeholder="e.g. 50 or -50" required
               class="admin-input" style="flex:1">
        <button class="btn-admin btn-admin-primary">Apply</button>
      </div>
      <input type="text" name="description" placeholder="Reason (optional)" class="admin-input">
    </form>
    <?php if (!empty($loyaltyHistory)): ?>
      <div style="margin-top:14px;max-height:130px;overflow-y:auto">
        <?php foreach ($loyaltyHistory as $t): ?>
          <div style="display:flex;justify-content:space-between;padding:5px 0;border-top:1px solid #f3f4f6;font-size:0.8rem">
            <span style="color:var(--admin-text)"><?= htmlspecialchars($t['description'] ?? $t['type']) ?></span>
            <span style="font-weight:700;color:<?= $t['points'] > 0 ? '#16a34a' : '#dc2626' ?>">
              <?= $t['points'] > 0 ? '+' : '' ?><?= $t['points'] ?> pts
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Hair notes + Block -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

  <div id="hair" style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Hair Notes</h3>
    <form method="POST">
      <input type="hidden" name="action" value="update_hair_notes">
      <textarea name="hair_notes" rows="4" class="admin-input" style="width:100%;resize:vertical"
                placeholder="Hair type, texture, sensitivities, style preferences…"><?= htmlspecialchars($c['hair_notes'] ?? '') ?></textarea>
      <button class="btn-admin btn-admin-primary btn-admin-sm" style="margin-top:8px">Save</button>
    </form>
  </div>

  <div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
    <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Admin Actions</h3>
    <?php if (!$c['is_blocked']): ?>
      <p style="font-size:0.82rem;color:var(--admin-muted);margin-bottom:12px">Block this client from making new bookings. Internal only — they won't be notified.</p>
      <form method="POST">
        <input type="hidden" name="action" value="block">
        <input type="text" name="block_reason" placeholder="Reason (e.g. Repeated no-shows)"
               class="admin-input" style="width:100%;margin-bottom:8px">
        <button class="btn-admin btn-admin-sm" style="background:#dc2626;color:#fff;border:none;width:100%">🚫 Block Client</button>
      </form>
    <?php else: ?>
      <p style="font-size:0.82rem;color:#dc2626;margin-bottom:12px">
        This client is blocked.<br>
        <?= $c['block_reason'] ? '<em>' . htmlspecialchars($c['block_reason']) . '</em>' : '' ?>
      </p>
      <form method="POST">
        <input type="hidden" name="action" value="unblock">
        <button class="btn-admin btn-admin-outline" style="width:100%">✅ Unblock Client</button>
      </form>
    <?php endif; ?>

    <hr style="margin:16px 0;border:none;border-top:1px solid var(--admin-border,#e5e7eb)">
    <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:10px">
      ⚠ Permanently delete this client and all their data (bookings, orders, notes, loyalty history). This cannot be undone. Not allowed if active bookings exist.
    </p>
    <form method="POST" onsubmit="return confirm('DELETE <?= htmlspecialchars(addslashes($c['name'])) ?>?\n\nThis will permanently remove all their bookings, orders, notes and loyalty history. This cannot be undone.')">
      <input type="hidden" name="action" value="delete">
      <button class="btn-admin btn-admin-sm" style="background:#7f1d1d;color:#fff;border:none;width:100%">🗑 Delete Client Permanently</button>
    </form>
  </div>
</div>

<!-- Admin notes -->
<div id="notes" style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px;margin-bottom:20px">
  <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Admin Notes</h3>
  <form method="POST" style="display:flex;gap:8px;margin-bottom:16px">
    <input type="hidden" name="action" value="add_note">
    <input type="text" name="note" placeholder="Add a note…" required class="admin-input" style="flex:1">
    <button class="btn-admin btn-admin-primary">Add</button>
  </form>
  <?php if (empty($notes)): ?>
    <p style="color:var(--admin-muted);font-size:0.85rem">No notes yet.</p>
  <?php else: ?>
    <?php foreach ($notes as $n): ?>
      <div style="display:flex;gap:10px;padding:8px 0;border-top:1px solid #f3f4f6">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--admin-primary);margin-top:5px;flex-shrink:0"></div>
        <div>
          <div style="font-size:0.88rem;color:var(--admin-text)"><?= nl2br(htmlspecialchars($n['note'])) ?></div>
          <div style="font-size:0.73rem;color:var(--admin-muted);margin-top:2px"><?= date('j M Y, g:i A', strtotime($n['created_at'])) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Booking history -->
<div style="background:#fff;border:1px solid var(--admin-border,#e5e7eb);border-radius:10px;padding:20px">
  <h3 style="font-size:0.75rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.5px;margin:0 0 14px">Booking History</h3>
  <?php if (empty($bookings)): ?>
    <p style="color:var(--admin-muted);font-size:0.85rem">No bookings yet.</p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Ref</th>
            <th>Date</th>
            <th>Service</th>
            <th>Status</th>
            <th>Total</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td style="font-family:monospace;font-size:0.8rem"><?= htmlspecialchars($b['booking_ref']) ?></td>
            <td><?= date('j M Y', strtotime($b['booked_date'])) ?></td>
            <td>
              <?= htmlspecialchars($b['s_name']) ?>
              <?php if ($b['variant_name']): ?>
                <span style="color:var(--admin-muted);font-size:0.8rem"> · <?= htmlspecialchars($b['variant_name']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($b['status']) ?></td>
            <td>£<?= number_format((float)$b['total_price'], 2) ?></td>
            <td><a href="/admin/booking-detail?id=<?= $b['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
