<?php
// ============================================================
// BraidedbyAGB — Admin Pipeline Management
// FILE: /admin/pipeline.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Pipeline';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add_link') {
        $serviceId = (int)$_POST['service_id'];
        $productId = (int)$_POST['product_id'];
        $label     = sanitize($_POST['display_label'] ?? '');
        $order     = (int)($_POST['display_order'] ?? 1);

        // Check not already linked
        $exists = $db->prepare("SELECT id FROM service_product_links WHERE service_id=? AND product_id=?");
        $exists->execute([$serviceId, $productId]);
        if ($exists->fetch()) {
            $msg = 'This product is already linked to that service.';
        } else {
            $db->prepare("INSERT INTO service_product_links (service_id,product_id,display_label,display_order,is_active) VALUES (?,?,?,?,1)")
               ->execute([$serviceId,$productId,$label,$order]);
            $msg = 'Link added.';
        }

    } elseif ($action === 'remove_link') {
        $id = (int)$_POST['link_id'];
        $db->prepare("DELETE FROM service_product_links WHERE id=?")->execute([$id]);
        $msg = 'Link removed.';

    } elseif ($action === 'toggle_link') {
        $id = (int)$_POST['link_id'];
        $db->prepare("UPDATE service_product_links SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        $msg = 'Link toggled.';

    } elseif ($action === 'update_label') {
        $id    = (int)$_POST['link_id'];
        $label = sanitize($_POST['display_label'] ?? '');
        $order = (int)$_POST['display_order'];
        $db->prepare("UPDATE service_product_links SET display_label=?,display_order=? WHERE id=?")->execute([$label,$order,$id]);
        $msg = 'Updated.';
    }
}


$pageTitle = $pageTitle ?? 'Pipeline';
require_once __DIR__ . '/includes/layout.php';

// Fetch all services
$services = $db->query("SELECT * FROM services WHERE is_active=1 ORDER BY display_order")->fetchAll();
// Fetch all products
$products = $db->query("SELECT id,name,price FROM products WHERE is_active=1 ORDER BY display_order")->fetchAll();

// Fetch all links with service + product names
$links = $db->query("
    SELECT spl.*, s.name as s_name, p.name as p_name, p.price as p_price
    FROM service_product_links spl
    JOIN services s ON s.id=spl.service_id
    JOIN products p ON p.id=spl.product_id
    ORDER BY s.display_order, spl.display_order
")->fetchAll();

// Group by service
$linksByService = [];
foreach ($links as $l) $linksByService[$l['service_id']][] = $l;

$msg = $msg ?: sanitize($_GET['msg'] ?? '');
?>

<?php if ($msg): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">
  ✓ <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:flex-start;gap:24px;flex-wrap:wrap">

  <!-- Add link form -->
  <div class="admin-form-card" style="width:320px;flex-shrink:0">
    <div class="admin-form-title">🔗 Add Pipeline Link</div>
    <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:16px">
      Link a product to a service so it's recommended during Step 4 of the booking flow.
    </p>
    <form method="POST" action="/admin/pipeline">
      <input type="hidden" name="action" value="add_link">

      <div class="admin-form-group">
        <label class="admin-label">Service</label>
        <select name="service_id" class="admin-input admin-select" required>
          <option value="">Select service…</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-form-group">
        <label class="admin-label">Product to Recommend</label>
        <select name="product_id" class="admin-input admin-select" required>
          <option value="">Select product…</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> — £<?= number_format($p['price'],2) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-form-group">
        <label class="admin-label">Display Label (optional)</label>
        <input class="admin-input" type="text" name="display_label"
               placeholder="e.g. Essential for this style">
      </div>

      <div class="admin-form-group">
        <label class="admin-label">Display Order</label>
        <input class="admin-input" type="number" name="display_order" value="1" min="1" max="10">
      </div>

      <button type="submit" class="btn-admin btn-admin-primary" style="width:100%;justify-content:center">
        + Add Link
      </button>
    </form>
  </div>

  <!-- Current pipeline -->
  <div style="flex:1;min-width:320px">
    <h3 style="font-family:'Montserrat',sans-serif;font-size:0.85rem;font-weight:800;color:var(--admin-primary-dark);margin-bottom:16px">
      Current Pipeline Links
    </h3>

    <?php if (empty($links)): ?>
      <div style="text-align:center;padding:48px;color:var(--admin-muted);background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg)">
        <div style="font-size:2rem;margin-bottom:12px">🔗</div>
        <p>No pipeline links yet. Add your first link on the left.</p>
      </div>
    <?php else: ?>
      <?php foreach ($services as $svc):
        $svcLinks = $linksByService[$svc['id']] ?? [];
        if (empty($svcLinks)) continue;
      ?>
      <div class="pipeline-card" style="margin-bottom:14px">
        <div class="pipeline-service-name">✂️ <?= htmlspecialchars($svc['name']) ?></div>
        <p style="font-size:0.7rem;color:var(--admin-muted);margin-bottom:10px"><?= count($svcLinks) ?> product<?= count($svcLinks)!=1?'s':'' ?> recommended</p>

        <?php foreach ($svcLinks as $link): ?>
        <div class="pipeline-linked-product">
          <span style="font-size:0.75rem;<?= !$link['is_active'] ? 'opacity:0.45;text-decoration:line-through' : '' ?>">
            🛍️ <strong><?= htmlspecialchars($link['p_name']) ?></strong>
            <span style="color:var(--admin-muted)"> — £<?= number_format($link['p_price'],2) ?></span>
            <?php if ($link['display_label']): ?>
              <span style="color:var(--admin-primary);font-style:italic;font-size:0.68rem"> · "<?= htmlspecialchars($link['display_label']) ?>"</span>
            <?php endif; ?>
          </span>

          <!-- Inline edit -->
          <form method="POST" action="/admin/pipeline" style="display:flex;gap:4px;align-items:center;margin-left:auto">
            <input type="hidden" name="action" value="update_label">
            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
            <input class="admin-input" type="text" name="display_label"
                   value="<?= htmlspecialchars($link['display_label'] ?? '') ?>"
                   placeholder="Label" style="width:140px;font-size:0.68rem;padding:3px 6px">
            <input class="admin-input" type="number" name="display_order"
                   value="<?= $link['display_order'] ?>" min="1" max="10"
                   style="width:46px;font-size:0.68rem;padding:3px 6px" title="Order">
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" title="Save">✓</button>
          </form>

          <!-- Toggle / remove -->
          <form method="POST" action="/admin/pipeline" style="display:flex;gap:4px;margin-left:6px">
            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
            <button name="action" value="toggle_link" type="submit"
                    class="btn-admin btn-admin-outline btn-admin-sm"
                    title="<?= $link['is_active'] ? 'Disable' : 'Enable' ?>">
              <?= $link['is_active'] ? '⏸' : '▶' ?>
            </button>
            <button name="action" value="remove_link" type="submit"
                    class="btn-admin btn-admin-danger btn-admin-sm"
                    onclick="return confirm('Remove this pipeline link?')"
                    title="Remove">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Performance box -->
<div class="admin-form-card" style="margin-top:20px">
  <div class="admin-form-title">📊 Pipeline Performance</div>
  <?php
  $stats = $db->query("
      SELECT s.name as service_name,
             COUNT(DISTINCT b.id) as total_bookings,
             COUNT(DISTINCT o.id) as pipeline_orders,
             COALESCE(SUM(o.total),0) as pipeline_revenue
      FROM services s
      LEFT JOIN bookings b ON b.service_id=s.id AND b.status!='cancelled'
      LEFT JOIN orders o ON o.booking_id=b.id AND o.from_pipeline=1
      WHERE s.is_active=1
      GROUP BY s.id
      ORDER BY pipeline_revenue DESC
  ")->fetchAll();
  ?>
  <table class="admin-table">
    <thead>
      <tr>
        <th>Service</th>
        <th>Total Bookings</th>
        <th>Pipeline Orders</th>
        <th>Conversion Rate</th>
        <th>Pipeline Revenue</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stats as $stat): ?>
      <tr>
        <td class="td-name"><?= htmlspecialchars($stat['service_name']) ?></td>
        <td><?= $stat['total_bookings'] ?></td>
        <td><?= $stat['pipeline_orders'] ?></td>
        <td>
          <?php $rate = $stat['total_bookings'] > 0 ? round(($stat['pipeline_orders'] / $stat['total_bookings']) * 100) : 0; ?>
          <span style="font-weight:700;color:<?= $rate >= 30 ? 'var(--admin-success)' : ($rate > 0 ? 'var(--admin-warning)' : 'var(--admin-muted)') ?>">
            <?= $rate ?>%
          </span>
        </td>
        <td class="td-price"><?= formatPrice($stat['pipeline_revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
