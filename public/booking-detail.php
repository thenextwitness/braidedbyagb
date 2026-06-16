<?php
// ============================================================
// BraidedbyAGB — Admin Booking Detail
// FILE: /admin/booking-detail.php (routed via .htaccess)
// ============================================================
$pageTitle = 'Booking Detail';
require_once __DIR__ . '/includes/layout.php';

$bookingId = (int)($_GET['id'] ?? 0);
if (!$bookingId) { header('Location: /admin/bookings'); exit; }

$stmt = $db->prepare("
    SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
           s.name as s_name, s.slug as s_slug, sv.variant_name
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
        if (in_array($status, ['pending','confirmed','completed','cancelled'])) {
            $db->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$status, $bookingId]);
            if ($status === 'confirmed') {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    // Re-fetch booking with updated status
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
        }
    } elseif ($action === 'confirm_deposit') {
        $db->prepare("UPDATE bookings SET deposit_paid=1 WHERE id=?")->execute([$bookingId]);
        $db->prepare("UPDATE payments SET status='succeeded', confirmed_by='admin', confirmed_at=NOW() WHERE booking_id=? AND type='deposit'")->execute([$bookingId]);
        $db->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND status='pending'")->execute([$bookingId]);
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
    } elseif ($action === 'update_notes') {
        $notes = sanitize($_POST['admin_notes'] ?? '');
        $db->prepare("UPDATE bookings SET admin_notes=? WHERE id=?")->execute([$notes, $bookingId]);
    }
    header("Location: /admin/bookings/$bookingId?msg=Updated.");
    exit;
}

// Refresh
$stmt->execute([$bookingId]); $bk = $stmt->fetch();
$msg = sanitize($_GET['msg'] ?? '');
$pageTitle = 'Booking — ' . $bk['booking_ref'];
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
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
      <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $bk['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-admin btn-admin-primary">Update</button>
  </form>

  <a href="https://wa.me/44<?= ltrim($bk['c_phone'], '0') ?>" target="_blank" class="btn-admin btn-admin-outline">💬 WhatsApp</a>
  <a href="mailto:<?= htmlspecialchars($bk['c_email']) ?>" class="btn-admin btn-admin-outline">✉️ Email</a>
</div>

<div class="detail-grid">

  <!-- Booking info -->
  <div class="detail-card">
    <div class="detail-card-title">📅 Booking Details</div>
    <div class="detail-row"><span class="dl">Reference</span><span class="dv td-ref"><?= htmlspecialchars($bk['booking_ref']) ?></span></div>
    <div class="detail-row"><span class="dl">Service</span><span class="dv"><?= htmlspecialchars($bk['s_name']) ?><?= $bk['variant_name'] ? ' — ' . $bk['variant_name'] : '' ?></span></div>
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
      <a href="/admin/customers?q=<?= urlencode($bk['c_email']) ?>" class="btn-admin btn-admin-outline btn-admin-sm">View profile</a>
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

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
