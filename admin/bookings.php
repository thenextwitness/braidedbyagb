<?php
// ============================================================
// BraidedbyAGB — Admin Bookings
// FILE: /admin/bookings.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Bookings';

// ── Handle status update ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id     = (int)($_POST['booking_id'] ?? 0);
    $action = sanitize($_POST['action']);

    if ($id && in_array($action, ['confirm','cancel','complete'])) {
        $statusMap = ['confirm'=>'confirmed','cancel'=>'cancelled','complete'=>'completed'];
        $newStatus = $statusMap[$action];
        $db->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$newStatus, $id]);

        if ($action === 'confirm') {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $bk = $db->query("SELECT b.*,c.name as c_name,c.email as c_email,s.name as s_name FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN services s ON s.id=b.service_id WHERE b.id=$id")->fetch();
                if ($bk) emailBookingApproved($bk, ['name'=>$bk['c_name'],'email'=>$bk['c_email']], ['name'=>$bk['s_name']]);
            } catch (Throwable $e) {
                error_log('Confirm email error: ' . $e->getMessage());
            }
        } elseif ($action === 'cancel') {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $bk = $db->query("SELECT b.*,c.name as c_name,c.email as c_email,s.name as s_name FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN services s ON s.id=b.service_id WHERE b.id=$id")->fetch();
                if ($bk && !empty($bk['c_email'])) {
                    emailBookingRejected($bk, ['name'=>$bk['c_name'],'email'=>$bk['c_email']], ['name'=>$bk['s_name']]);
                }
            } catch (Throwable $e) {
                error_log('Cancel email error: ' . $e->getMessage());
            }
        }
        header('Location: /admin/bookings?msg=' . urlencode("Booking $newStatus."));
        exit;
    }
}


$pageTitle = $pageTitle ?? 'Bookings';
require_once __DIR__ . '/includes/layout.php';

// ── Filters ───────────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($statusFilter && in_array($statusFilter, ['pending','confirmed','completed','cancelled'])) {
    $where[] = 'b.status = ?'; $params[] = $statusFilter;
}
if ($search) {
    $where[] = '(c.name LIKE ? OR c.email LIKE ? OR b.booking_ref LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
$whereClause = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id=b.customer_id WHERE $whereClause");
$total->execute($params); $total = (int)$total->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $db->prepare("
    SELECT b.*, c.name as c_name, c.email as c_email, c.phone as c_phone,
           s.name as s_name, sv.variant_name
    FROM bookings b
    JOIN customers c ON c.id = b.customer_id
    JOIN services  s ON s.id = b.service_id
    LEFT JOIN service_variants sv ON sv.id = b.variant_id
    WHERE $whereClause
    ORDER BY b.booked_date DESC, b.booked_time DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Count appointments per cart group so the list can flag family/group bookings.
// Defensive: cart_group_ref may not be migrated on the live DB yet.
$groupCounts = [];
try {
    $grefs = array_values(array_filter(array_unique(array_column($bookings, 'cart_group_ref'))));
    if ($grefs) {
        $in = implode(',', array_fill(0, count($grefs), '?'));
        $gc = $db->prepare("SELECT cart_group_ref, COUNT(*) c FROM bookings WHERE cart_group_ref IN ($in) GROUP BY cart_group_ref");
        $gc->execute($grefs);
        foreach ($gc->fetchAll() as $r) $groupCounts[$r['cart_group_ref']] = (int)$r['c'];
    }
} catch (Throwable $e) {
    $groupCounts = [];
}

$msg = sanitize($_GET['msg'] ?? '');
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Filters row -->
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
  <form method="GET" action="/admin/bookings" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <input class="admin-search" type="text" name="q" placeholder="Search name, email, ref…"
           value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="status" class="admin-input admin-select" style="width:150px" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-admin btn-admin-primary">Search</button>
    <?php if ($search || $statusFilter): ?>
      <a href="/admin/bookings" class="btn-admin btn-admin-outline">Clear</a>
    <?php endif; ?>
  </form>
  <a href="/admin/booking-new.php" class="btn-admin btn-admin-primary">+ New Booking</a>
</div>

<!-- Table -->
<div class="admin-table-wrap">
  <div class="admin-table-header">
    <span class="admin-table-title">
      <?= $total ?> booking<?= $total != 1 ? 's' : '' ?>
      <?= $statusFilter ? '· ' . ucfirst($statusFilter) : '' ?>
    </span>
  </div>

  <?php if (empty($bookings)): ?>
    <div class="table-empty"><div class="table-empty-icon">📅</div><p>No bookings found</p></div>
  <?php else: ?>
  <table class="admin-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Client</th>
        <th>Service</th>
        <th>Date & Time</th>
        <th>Total</th>
        <th>Deposit</th>
        <th>Payment</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bookings as $bk): ?>
      <tr>
        <td><span class="td-ref"><?= htmlspecialchars($bk['booking_ref']) ?></span></td>
        <td>
          <div class="td-name"><?= htmlspecialchars($bk['c_name']) ?></div>
          <div class="td-muted" style="font-size:0.72rem"><?= htmlspecialchars($bk['c_email']) ?></div>
          <?php if (!empty($bk['guest_name'])): ?>
            <div class="td-muted" style="font-size:0.7rem">For: <strong><?= htmlspecialchars($bk['guest_name']) ?></strong></div>
          <?php endif; ?>
          <?php $gref = $bk['cart_group_ref'] ?? ''; if ($gref && ($groupCounts[$gref] ?? 0) > 1): ?>
            <span class="status-badge status-pending" style="font-size:0.55rem;margin-top:3px;display:inline-block"
                  title="Booked together — <?= (int)$groupCounts[$gref] ?> appointments">👪 Group · <?= (int)$groupCounts[$gref] ?> appts</span>
          <?php endif; ?>
        </td>
        <td>
          <div><?= htmlspecialchars($bk['s_name']) ?></div>
          <?php if ($bk['variant_name']): ?>
            <div class="td-muted" style="font-size:0.72rem"><?= htmlspecialchars($bk['variant_name']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <div><?= formatDate($bk['booked_date'], 'D j M Y') ?></div>
          <div class="td-muted"><?= formatTime($bk['booked_time']) ?></div>
        </td>
        <td class="td-price"><?= formatPrice($bk['total_price']) ?></td>
        <td>
          <?php if ($bk['deposit_paid']): ?>
            <span class="status-badge status-confirmed">Paid</span>
          <?php else: ?>
            <span class="status-badge status-pending">Pending</span>
          <?php endif; ?>
          <div class="td-muted" style="font-size:0.7rem"><?= formatPrice($bk['deposit_amount']) ?></div>
        </td>
        <td class="td-muted"><?= ucfirst($bk['payment_method'] ?? '—') ?></td>
        <td><span class="status-badge status-<?= $bk['status'] ?>"><?= ucfirst($bk['status']) ?></span></td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="/admin/bookings/<?= $bk['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">View</a>
            <?php if ($bk['status'] === 'pending'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn-admin btn-admin-success btn-admin-sm"
                        onclick="return confirm('Confirm this booking?')">Confirm</button>
              </form>
            <?php endif; ?>
            <?php if (in_array($bk['status'], ['pending','confirmed'])): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="booking_id" value="<?= $bk['id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                        onclick="return confirm('Cancel this booking? Deposit is forfeited.')">Cancel</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?page=<?= $p ?>&status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>"
         class="admin-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <span class="admin-pagination-info">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> total</span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
