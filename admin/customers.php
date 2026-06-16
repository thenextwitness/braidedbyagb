<?php
// ============================================================
// BraidedbyAGB — Admin Customers
// FILE: /admin/customers.php
// ============================================================
$pageTitle = 'Customers';
require_once __DIR__ . '/includes/layout.php';

$search = sanitize($_GET['search'] ?? '');

$where = $search ? "WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?" : '';
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

$stmt = $db->prepare("
    SELECT c.*,
           COUNT(DISTINCT b.id) as total_bookings,
           COUNT(DISTINCT o.id) as total_orders,
           COALESCE(SUM(b.total_price),0) + COALESCE(SUM(o.total),0) as lifetime_value,
           MAX(b.booked_date) as last_booking
    FROM customers c
    LEFT JOIN bookings b ON b.customer_id = c.id AND b.status NOT IN ('cancelled','late_cancelled','no_show')
    LEFT JOIN orders   o ON o.customer_id = c.id AND o.status != 'cancelled'
    $where
    GROUP BY c.id
    ORDER BY c.created_at DESC
");
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>

<div class="page-header">
  <div>
    <h2 class="section-heading">All Customers</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= count($customers) ?> total customers</p>
  </div>
</div>

<!-- Search -->
<form method="GET" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap">
  <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
         placeholder="Search by name, email or phone..."
         class="admin-input" style="flex:1;max-width:400px">
  <button class="btn-admin btn-admin-primary">Search</button>
  <?php if ($search): ?>
    <a href="/admin/customers" class="btn-admin btn-admin-outline">Clear</a>
  <?php endif; ?>
</form>

<?php if (empty($customers)): ?>
  <div class="table-empty">
    <div class="table-empty-icon">👥</div>
    <p><?= $search ? 'No customers match your search.' : 'No customers yet.' ?></p>
  </div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Customer</th>
        <th>Phone</th>
        <th>Bookings</th>
        <th>Orders</th>
        <th>Lifetime Value</th>
        <th>Loyalty</th>
        <th>Last Booking</th>
        <th>Joined</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
    <tr <?= $c['is_blocked'] ? 'style="opacity:0.65"' : '' ?>>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <a href="/admin/customers/<?= $c['id'] ?>" style="font-weight:700;color:var(--admin-text);text-decoration:none">
            <?= htmlspecialchars($c['name']) ?>
          </a>
          <?php if ($c['is_blocked']): ?>
            <span style="font-size:0.68rem;font-weight:700;color:#dc2626;background:#fef2f2;padding:1px 6px;border-radius:10px">BLOCKED</span>
          <?php endif; ?>
        </div>
        <div style="font-size:0.75rem;color:var(--admin-muted)"><?= htmlspecialchars($c['email']) ?></div>
        <?php if (!empty($c['tags'])): ?>
          <div style="margin-top:3px">
            <?php foreach (array_filter(explode(',', $c['tags'])) as $tag): ?>
              <span style="font-size:0.65rem;font-weight:700;color:var(--admin-primary);background:#faf5ff;padding:1px 7px;border-radius:10px;margin-right:3px"><?= htmlspecialchars(trim($tag)) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </td>
      <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
      <td><span class="admin-badge"><?= (int)$c['total_bookings'] ?></span></td>
      <td><span class="admin-badge"><?= (int)$c['total_orders'] ?></span></td>
      <td style="font-weight:700;color:var(--admin-primary)">
        £<?= number_format((float)$c['lifetime_value'], 2) ?>
      </td>
      <td style="font-size:0.82rem">
        <?php if ((int)$c['loyalty_points'] > 0): ?>
          <span style="font-weight:700;color:#ca8a04">⭐ <?= (int)$c['loyalty_points'] ?></span>
        <?php else: ?>
          <span style="color:var(--admin-muted)">0</span>
        <?php endif; ?>
      </td>
      <td><?= $c['last_booking'] ? date('j M Y', strtotime($c['last_booking'])) : '—' ?></td>
      <td style="font-size:0.78rem;color:var(--admin-muted)"><?= date('j M Y', strtotime($c['created_at'])) ?></td>
      <td>
        <a href="/admin/customers/<?= $c['id'] ?>" class="btn-admin btn-admin-primary btn-admin-sm">👤 Profile</a>
        <a href="https://wa.me/44<?= ltrim(preg_replace('/\s+/', '', $c['phone'] ?? ''), '0') ?>"
           target="_blank" class="btn-admin btn-admin-outline btn-admin-sm">💬</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
