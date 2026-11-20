<?php
// ============================================================
// BraidedbyAGB — Admin Customers
// FILE: /admin/customers.php
// ============================================================
$pageTitle = 'Customers';
require_once __DIR__ . '/includes/layout.php';

$search = sanitize($_GET['search'] ?? '');
$view   = ($_GET['view'] ?? '') === 'lapsed' ? 'lapsed' : 'all';

// ── "Haven't booked lately" (2/3/6/12 months), with ticks for batch messages ──
$lapseKey  = array_key_exists($_GET['m'] ?? '', LAPSE_FILTERS) ? $_GET['m'] : '2';
$lapseDays = LAPSE_FILTERS[$lapseKey][1];
$lapsedStmt = $db->prepare("
    SELECT c.id, c.name, c.phone, c.email, c.email_optin, c.winback_sent_at,
           MAX(b.booked_date) AS last_visit, COUNT(b.id) AS visits,
           (SELECT MAX(r.sent_at) FROM message_batch_recipients r
             WHERE r.customer_id = c.id AND r.status IN ('sent','opened')) AS last_messaged
    FROM customers c
    JOIN bookings b ON b.customer_id = c.id AND b.status = 'completed'
    WHERE COALESCE(c.is_blocked, 0) = 0
      AND NOT EXISTS (SELECT 1 FROM bookings f WHERE f.customer_id = c.id AND f.status IN ('pending','confirmed'))
    GROUP BY c.id, c.name, c.phone, c.email, c.email_optin, c.winback_sent_at
    HAVING last_visit <= DATE_SUB(CURDATE(), INTERVAL {$lapseDays} DAY)
    ORDER BY last_visit DESC
");
$lapsedStmt->execute();
$lapsed = $lapsedStmt->fetchAll();

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

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="/admin/customers" class="btn-admin <?= $view === 'all' ? 'btn-admin-primary' : 'btn-admin-outline' ?>">All customers</a>
  <a href="/admin/customers?view=lapsed" class="btn-admin <?= $view === 'lapsed' ? 'btn-admin-primary' : 'btn-admin-outline' ?>">💤 Haven't booked lately (<?= count($lapsed) ?>)</a>
</div>

<?php if ($view === 'lapsed'): ?>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
  <span style="color:var(--admin-muted);font-size:0.8rem">Not booked for</span>
  <?php foreach (LAPSE_FILTERS as $k => [$lbl]): ?>
    <a href="/admin/customers?view=lapsed&amp;m=<?= $k ?>" class="btn-admin btn-admin-sm <?= $k === $lapseKey ? 'btn-admin-primary' : 'btn-admin-outline' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
  <a href="/admin/messages.php?view=history" class="btn-admin btn-admin-sm btn-admin-outline" style="margin-left:auto">✉️ Message history</a>
</div>
<p style="color:var(--admin-muted);font-size:0.8rem;margin-bottom:14px">
  Clients whose last appointment was <?= $lapseDays ?>+ days ago with nothing booked since. Tick clients and press <strong>Message selected</strong> to email or WhatsApp them all at once.
</p>
<?php if (empty($lapsed)): ?>
  <div class="table-empty"><div class="table-empty-icon">🎉</div><p>Everyone has been in recently.</p></div>
<?php else: ?>
<form method="POST" action="/admin/messages.php?view=compose" id="lapsedForm">
<input type="hidden" name="audience" value="Haven't booked for <?= htmlspecialchars(LAPSE_FILTERS[$lapseKey][0]) ?>+">
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th><input type="checkbox" id="selAll" title="Select all" style="accent-color:var(--admin-primary);width:16px;height:16px"></th><th>Client</th><th>Last visit</th><th>Visits</th><th>Emails</th><th>Last messaged</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lapsed as $c):
      $days  = (int)floor((time() - strtotime($c['last_visit'])) / 86400);
      $first = trim(explode(' ', (string)$c['name'])[0]) ?: 'there';
      $wa    = waClientLink($c['phone'], "Hi {$first}! It's been a little while 💕 I'd love to see you again. Here are my available times: " . rtrim(SITE_URL, '/') . '/booking');
      $sent  = !empty($c['winback_sent_at']) && $c['winback_sent_at'] >= $c['last_visit'];
      $recent = !empty($c['last_messaged']) && strtotime($c['last_messaged']) > strtotime('-14 days');
    ?>
    <tr>
      <td><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" class="selOne" data-email="<?= ((int)$c['email_optin'] && !empty($c['email'])) ? 1 : 0 ?>" style="accent-color:var(--admin-primary);width:16px;height:16px"></td>
      <td><a href="/admin/customers/<?= (int)$c['id'] ?>" style="font-weight:700;color:var(--admin-text);text-decoration:none"><?= htmlspecialchars($c['name']) ?></a>
          <div class="td-muted" style="font-size:0.72rem"><?= htmlspecialchars((string)$c['phone']) ?></div></td>
      <td><?= formatDate($c['last_visit'], 'j M Y') ?><div class="td-muted" style="font-size:0.72rem"><?= $days ?> days ago</div></td>
      <td><?= (int)$c['visits'] ?></td>
      <td style="font-size:0.78rem">
        <?php if (!(int)$c['email_optin'] || empty($c['email'])): ?><span class="status-badge status-cancelled">Turned off</span>
        <?php else: ?><span class="status-badge status-confirmed">Allowed</span><?php endif; ?>
      </td>
      <td style="font-size:0.75rem">
        <?php if ($recent): ?><span class="status-badge status-pending">Messaged <?= date('j M', strtotime($c['last_messaged'])) ?></span>
        <?php elseif (!empty($c['last_messaged'])): ?><span class="td-muted">Batch, <?= date('j M Y', strtotime($c['last_messaged'])) ?></span>
        <?php elseif ($sent): ?><span class="td-muted">"We miss you" auto-email, <?= date('j M', strtotime($c['winback_sent_at'])) ?></span>
        <?php else: ?><span class="td-muted">—</span><?php endif; ?>
      </td>
      <td><?php if ($wa): ?><a href="<?= htmlspecialchars($wa) ?>" target="_blank" class="btn-admin btn-admin-outline btn-admin-sm" title="WhatsApp just this client">💬</a><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div id="selBar" style="position:sticky;bottom:12px;display:none;align-items:center;gap:12px;flex-wrap:wrap;background:#4B0082;color:#fff;border-radius:10px;padding:10px 14px;margin-top:12px;font-size:0.85rem;z-index:5">
  <strong style="color:#D4AF37" id="selCount">0 selected</strong>
  <span id="selInfo" style="opacity:.85"></span>
  <button type="submit" class="btn-admin btn-admin-sm" style="margin-left:auto;background:#D4AF37;color:#4B0082;border-color:#D4AF37;font-weight:800">Message selected →</button>
</div>
</form>
<script>
(function () {
  const boxes = [...document.querySelectorAll('.selOne')], all = document.getElementById('selAll');
  function upd() {
    const on = boxes.filter(b => b.checked), em = on.filter(b => b.dataset.email === '1').length;
    document.getElementById('selBar').style.display = on.length ? 'flex' : 'none';
    document.getElementById('selCount').textContent = on.length + ' selected';
    document.getElementById('selInfo').textContent = em + ' can be emailed' + (on.length - em ? ' · ' + (on.length - em) + ' have emails turned off (they can still be messaged on WhatsApp)' : '');
    all.checked = on.length === boxes.length && boxes.length > 0;
  }
  boxes.forEach(b => b.addEventListener('change', upd));
  all.addEventListener('change', () => { boxes.forEach(b => b.checked = all.checked); upd(); });
})();
</script>
<?php endif; ?>
<?php else: ?>

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
<?php endif; /* view */ ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
