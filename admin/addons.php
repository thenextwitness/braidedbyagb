<?php
// ============================================================
// BraidedbyAGB — Admin Service Add-ons
// FILE: /admin/addons.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

// Ensure exclusions table exists (zero-config, no separate migration step needed)
$db->exec("CREATE TABLE IF NOT EXISTS service_addon_exclusions (
    service_id INT NOT NULL,
    addon_id   INT NOT NULL,
    PRIMARY KEY (service_id, addon_id)
)");

// ── Handle POST BEFORE any output ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $serviceId = (int)$_POST['service_id'];
            $name      = sanitize($_POST['name'] ?? '');
            $price     = (float)($_POST['price'] ?? 0);
            if (!$name) { header('Location: /admin/addons?error=Addon+name+required.'); exit; }
            $db->prepare("INSERT INTO service_addons (service_id,name,price,is_active) VALUES (?,?,?,1)")
               ->execute([$serviceId, $name, $price]);
            header('Location: /admin/addons?msg=' . urlencode("Add-on \"$name\" added.")); exit;

        } elseif ($action === 'update') {
            $db->prepare("UPDATE service_addons SET name=?,price=? WHERE id=?")
               ->execute([sanitize($_POST['name']), (float)$_POST['price'], (int)$_POST['id']]);
            header('Location: /admin/addons?msg=Add-on+updated.'); exit;

        } elseif ($action === 'toggle') {
            $db->prepare("UPDATE service_addons SET is_active = NOT is_active WHERE id=?")->execute([(int)$_POST['id']]);
            header('Location: /admin/addons?msg=Add-on+toggled.'); exit;

        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM service_addons WHERE id=?")->execute([(int)$_POST['id']]);
            header('Location: /admin/addons?msg=Add-on+deleted.'); exit;

        } elseif ($action === 'exclude') {
            // Exclude a global add-on from a specific service
            $serviceId = (int)$_POST['service_id'];
            $addonId   = (int)$_POST['addon_id'];
            $db->prepare("INSERT IGNORE INTO service_addon_exclusions (service_id, addon_id) VALUES (?,?)")
               ->execute([$serviceId, $addonId]);
            header('Location: /admin/addons?msg=' . urlencode("Add-on excluded from service.")); exit;

        } elseif ($action === 'unexclude') {
            // Re-include a previously excluded add-on for a specific service
            $serviceId = (int)$_POST['service_id'];
            $addonId   = (int)$_POST['addon_id'];
            $db->prepare("DELETE FROM service_addon_exclusions WHERE service_id=? AND addon_id=?")
               ->execute([$serviceId, $addonId]);
            header('Location: /admin/addons?msg=' . urlencode("Add-on restored for service.")); exit;
        }
    } catch (Exception $e) {
        error_log('Addons error: '.$e->getMessage());
        header('Location: /admin/addons?error=' . urlencode($e->getMessage())); exit;
    }
}

$services = $db->query("SELECT * FROM services WHERE is_active=1 ORDER BY display_order")->fetchAll();
$addons   = $db->query("
    SELECT sa.*, COALESCE(s.name, '(Global)') as s_name
    FROM service_addons sa
    LEFT JOIN services s ON s.id=sa.service_id
    ORDER BY COALESCE(s.display_order,999), sa.name
")->fetchAll();

// Group per-service addons (non-global)
$addonsByService = [];
foreach ($addons as $a) {
    if (!$a['is_global']) $addonsByService[$a['service_id']][] = $a;
}

// All global add-ons
$globalAddons = array_values(array_filter($addons, fn($a) => $a['is_global']));

// Current exclusions — [service_id => [addon_id, ...]]
$excRows = $db->query("SELECT service_id, addon_id FROM service_addon_exclusions")->fetchAll();
$exclusions = [];
foreach ($excRows as $r) $exclusions[$r['service_id']][] = $r['addon_id'];

$msg   = htmlspecialchars($_GET['msg']   ?? '');
$error = htmlspecialchars($_GET['error'] ?? '');
$pageTitle = 'Service Add-ons';
require_once __DIR__ . '/includes/layout.php';
?>

<?php if ($msg):   ?><div style="background:#d1fae5;border:1px solid #6ee7b7;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#065f46">✓ <?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div style="background:#fee2e2;border:1px solid #fca5a5;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:#991b1b">✗ <?= $error ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Service Add-ons</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">Add-ons appear as optional extras during Step 2 of the booking flow for each service.</p>
  </div>
</div>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

  <!-- Add new addon -->
  <div class="admin-form-card" style="width:300px;flex-shrink:0">
    <div class="admin-form-title">+ Add New Add-on</div>
    <form method="POST" action="/admin/addons">
      <input type="hidden" name="action" value="add">
      <div class="admin-form-group">
        <label class="admin-label">Service *</label>
        <select name="service_id" class="admin-input" required>
          <option value="">— Select service —</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form-group">
        <label class="admin-label">Add-on Name *</label>
        <input class="admin-input" type="text" name="name" placeholder="e.g. Scalp treatment, Beads" required>
      </div>
      <div class="admin-form-group">
        <label class="admin-label">Extra Price (£)</label>
        <input class="admin-input" type="number" name="price" value="0" step="0.01" min="0" placeholder="0 = free">
      </div>
      <button type="submit" class="btn-admin btn-admin-primary" style="width:100%;justify-content:center">Add Add-on</button>
    </form>
  </div>

  <!-- Current addons grouped by service -->
  <div style="flex:1;min-width:300px">
    <?php if (empty($addons)): ?>
      <div class="table-empty">
        <div class="table-empty-icon">✨</div>
        <p>No add-ons yet. Use the form to add extras for each service.</p>
      </div>
    <?php else: ?>
      <?php foreach ($services as $svc):
        $svcAddons = $addonsByService[$svc['id']] ?? [];
        if (empty($svcAddons)) continue;
      ?>
      <div class="admin-card" style="margin-bottom:12px">
        <div class="admin-card-header">
          <span class="admin-card-title">✂️ <?= htmlspecialchars($svc['name']) ?></span>
          <span style="font-size:0.7rem;color:var(--admin-muted)"><?= count($svcAddons) ?> add-on<?= count($svcAddons)!=1?'s':'' ?></span>
        </div>
        <div class="admin-card-body" style="padding:0">
          <table class="admin-table">
            <thead>
              <tr><th>Name</th><th>Price</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($svcAddons as $addon): ?>
            <tr>
              <td>
                <form method="POST" action="/admin/addons" style="display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= $addon['id'] ?>">
                  <input class="admin-input" type="text" name="name" value="<?= htmlspecialchars($addon['name']) ?>"
                         style="font-size:0.8rem;padding:4px 8px" required>
                  <span style="font-size:0.75rem;color:var(--admin-muted)">£</span>
                  <input class="admin-input" type="number" name="price" value="<?= $addon['price'] ?>"
                         step="0.01" min="0" style="width:72px;font-size:0.8rem;padding:4px 8px">
                  <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">✓</button>
              </td>
              <td class="td-price">£<?= number_format($addon['price'],2) ?></td>
              <td>
                <span class="status-badge <?= $addon['is_active'] ? 'status-confirmed' : 'status-cancelled' ?>">
                  <?= $addon['is_active'] ? 'Active' : 'Hidden' ?>
                </span>
                </form>
              </td>
              <td>
                <div style="display:flex;gap:4px">
                  <form method="POST" action="/admin/addons">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $addon['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm">
                      <?= $addon['is_active'] ? 'Hide' : 'Show' ?>
                    </button>
                  </form>
                  <form method="POST" action="/admin/addons"
                        onsubmit="return confirm('Delete add-on \'<?= addslashes($addon['name']) ?>\'?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $addon['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($globalAddons) && !empty($services)): ?>
<div style="margin-top:32px">
  <h3 style="font-size:1rem;font-weight:700;color:var(--admin-primary);margin-bottom:4px">Global Add-on Exclusions</h3>
  <p style="font-size:0.8rem;color:var(--admin-muted);margin-bottom:16px">
    Global add-ons apply to every service by default. Use this table to remove a global add-on from services it doesn't apply to.
  </p>
  <div style="overflow-x:auto">
  <table class="admin-table" style="min-width:600px">
    <thead>
      <tr>
        <th style="width:200px">Service</th>
        <?php foreach ($globalAddons as $ga): ?>
          <th style="text-align:center;font-size:0.75rem"><?= htmlspecialchars($ga['name']) ?><br><span style="color:var(--admin-muted);font-weight:400">£<?= number_format($ga['price'],2) ?></span></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($services as $svc):
        $svcExclusions = $exclusions[$svc['id']] ?? [];
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($svc['name']) ?></strong></td>
        <?php foreach ($globalAddons as $ga):
          $isExcluded = in_array($ga['id'], $svcExclusions);
        ?>
        <td style="text-align:center">
          <?php if ($isExcluded): ?>
            <form method="POST" action="/admin/addons" style="display:inline">
              <input type="hidden" name="action"     value="unexclude">
              <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
              <input type="hidden" name="addon_id"   value="<?= $ga['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm"
                      style="background:#fee2e2;border-color:#fca5a5;color:#991b1b"
                      title="Currently excluded — click to restore">✗ Off</button>
            </form>
          <?php else: ?>
            <form method="POST" action="/admin/addons" style="display:inline">
              <input type="hidden" name="action"     value="exclude">
              <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
              <input type="hidden" name="addon_id"   value="<?= $ga['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-sm"
                      style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46"
                      title="Currently applied — click to exclude">✓ On</button>
            </form>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
