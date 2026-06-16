<?php
// ============================================================
// BraidedbyAGB — Admin Orders
// FILE: /admin/orders.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Orders';

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['order_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');
    if ($id) {
        if ($action === 'update_status') {
            $status = sanitize($_POST['status'] ?? '');
            if (in_array($status, ['pending','processing','dispatched','delivered','cancelled'])) {
                $db->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $id]);
                if ($status === 'dispatched') {
                    $tracking = sanitize($_POST['tracking'] ?? '');
                    $db->prepare("UPDATE orders SET tracking_number=?, dispatched_at=NOW() WHERE id=?")->execute([$tracking, $id]);
                    // Send dispatch email (with tracking number if provided)
                    try {
                        require_once __DIR__ . '/../includes/mailer.php';
                        $ord = $db->query("SELECT o.*,c.name as c_name,c.email as c_email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=$id")->fetch();
                        if ($ord) emailOrderDispatched($ord, ['name'=>$ord['c_name'],'email'=>$ord['c_email']], $tracking);
                    } catch (Throwable $e) {
                        error_log('Order dispatch email error: ' . $e->getMessage());
                    }
                }
            }
        } elseif ($action === 'confirm_payment') {
            $db->prepare("UPDATE orders SET payment_status='paid', status='processing' WHERE id=?")->execute([$id]);
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $ord = $db->query("SELECT o.*,c.name as c_name,c.email as c_email FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.id=$id")->fetch();
                // Fetch order items so the receipt shows what was paid for
                $items = [];
                if ($ord) {
                    $itStmt = $db->prepare("SELECT p.name, oi.quantity, oi.price_charged, pv.colour FROM order_items oi JOIN products p ON p.id=oi.product_id LEFT JOIN product_variants pv ON pv.id=oi.variant_id WHERE oi.order_id=?");
                    $itStmt->execute([$id]);
                    $items = $itStmt->fetchAll();
                    emailOrderConfirmation($ord, ['name'=>$ord['c_name'],'email'=>$ord['c_email']], $items);
                }
            } catch (Throwable $e) {
                error_log('Order confirm_payment email error: ' . $e->getMessage());
            }
        }
    }
    header('Location: /admin/orders?msg=Updated.');
    exit;
}


$pageTitle = $pageTitle ?? 'Orders';
require_once __DIR__ . '/includes/layout.php';

$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where = ['1=1']; $params = [];
if ($statusFilter && in_array($statusFilter, ['pending','processing','dispatched','delivered','cancelled'])) {
    $where[] = 'o.status = ?'; $params[] = $statusFilter;
}
if ($search) {
    $where[] = '(c.name LIKE ? OR c.email LIKE ? OR o.order_ref LIKE ?)';
    $like = '%'.$search.'%';
    $params = array_merge($params, [$like,$like,$like]);
}
$whereClause = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id=o.customer_id WHERE $whereClause");
$total->execute($params); $total = (int)$total->fetchColumn();
$pages = ceil($total / $perPage);

$stmt = $db->prepare("
    SELECT o.*, c.name as c_name, c.email as c_email,
           GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') as product_names
    FROM orders o
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE $whereClause
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$msg = sanitize($_GET['msg'] ?? '');
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
  <form method="GET" action="/admin/orders" style="display:flex;gap:8px;flex-wrap:wrap">
    <input class="admin-search" type="text" name="q" placeholder="Search name, email, ref…"
           value="<?= htmlspecialchars($search) ?>" style="max-width:220px">
    <select name="status" class="admin-input admin-select" style="width:160px" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['pending','processing','dispatched','delivered','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-admin btn-admin-primary">Search</button>
    <?php if ($search || $statusFilter): ?>
      <a href="/admin/orders" class="btn-admin btn-admin-outline">Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="admin-table-wrap">
  <div class="admin-table-header">
    <span class="admin-table-title"><?= $total ?> order<?= $total != 1 ? 's' : '' ?><?= $statusFilter ? ' · '.ucfirst($statusFilter) : '' ?></span>
  </div>

  <?php if (empty($orders)): ?>
    <div class="table-empty"><div class="table-empty-icon">📦</div><p>No orders found</p></div>
  <?php else: ?>
  <table class="admin-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Customer</th>
        <th>Items</th>
        <th>Delivery</th>
        <th>Total</th>
        <th>Payment</th>
        <th>Status</th>
        <th>Date</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $ord): ?>
      <tr>
        <td>
          <span class="td-ref"><?= htmlspecialchars($ord['order_ref']) ?></span>
          <?php if ($ord['from_pipeline']): ?>
            <span class="status-badge status-pending" style="font-size:0.55rem;margin-left:4px">Pipeline</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="td-name"><?= htmlspecialchars($ord['c_name']) ?></div>
          <div class="td-muted" style="font-size:0.72rem"><?= htmlspecialchars($ord['c_email']) ?></div>
        </td>
        <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:0.78rem">
          <?= htmlspecialchars($ord['product_names'] ?? '—') ?>
        </td>
        <td>
          <span style="font-size:0.75rem">
            <?= $ord['delivery_type'] === 'local_pickup' ? '📍 Pickup' : '🚚 Delivery' ?>
          </span>
          <?php if (!empty($ord['tracking_number'])): ?>
            <div style="font-size:0.68rem;color:var(--admin-muted)">Track: <?= htmlspecialchars($ord['tracking_number']) ?></div>
          <?php endif; ?>
        </td>
        <td class="td-price"><?= formatPrice($ord['total']) ?></td>
        <td>
          <?php if ($ord['payment_status'] === 'paid'): ?>
            <span class="status-badge status-confirmed">Paid</span>
          <?php else: ?>
            <span class="status-badge status-pending">Pending</span>
            <?php if ($ord['payment_method'] === 'bank_transfer'): ?>
              <form method="POST" style="margin-top:4px">
                <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                <input type="hidden" name="action" value="confirm_payment">
                <button type="submit" class="btn-admin btn-admin-success btn-admin-sm"
                        onclick="return confirm('Confirm payment received?')">✓ Confirm</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td><span class="status-badge status-<?= $ord['status'] ?>"><?= ucfirst($ord['status']) ?></span></td>
        <td class="td-muted"><?= date('j M', strtotime($ord['created_at'])) ?></td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="/admin/orders/<?= $ord['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">View</a>
            <?php if ($ord['status'] === 'processing'): ?>
              <button class="btn-admin btn-admin-primary btn-admin-sm"
                      onclick="showDispatchModal(<?= $ord['id'] ?>)">Dispatch</button>
            <?php endif; ?>
            <?php if ($ord['status'] === 'pending' && $ord['payment_status'] === 'paid'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="processing">
                <button type="submit" class="btn-admin btn-admin-success btn-admin-sm">Process</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
  <div class="admin-pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?page=<?= $p ?>&status=<?= urlencode($statusFilter) ?>&q=<?= urlencode($search) ?>"
         class="admin-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <span class="admin-pagination-info">Page <?= $page ?> of <?= $pages ?></span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Dispatch modal -->
<div id="dispatch-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:var(--admin-radius-lg);padding:28px;width:400px;max-width:90vw">
    <h3 style="font-family:'Montserrat',sans-serif;font-weight:800;margin-bottom:16px;color:var(--admin-primary-dark)">Mark as Dispatched</h3>
    <form method="POST" action="/admin/orders" id="dispatch-form">
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="status" value="dispatched">
      <input type="hidden" name="order_id" id="dispatch-order-id">
      <div class="admin-form-group">
        <label class="admin-label">Tracking Number (optional)</label>
        <input class="admin-input" type="text" name="tracking" placeholder="Royal Mail / courier tracking ref">
      </div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" class="btn-admin btn-admin-primary">Confirm Dispatch</button>
        <button type="button" class="btn-admin btn-admin-outline" onclick="closeDispatchModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function showDispatchModal(orderId) {
  document.getElementById('dispatch-order-id').value = orderId;
  document.getElementById('dispatch-modal').style.display = 'flex';
}
function closeDispatchModal() {
  document.getElementById('dispatch-modal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
