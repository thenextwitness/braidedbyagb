<?php
// ============================================================
// BraidedbyAGB — Admin Service Add-ons (per-service)
// FILE: /admin/addons.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

// ── Handle POST BEFORE any output ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $name      = sanitize($_POST['name'] ?? '');
            $price     = (float)($_POST['price'] ?? 0);
            if (!$serviceId) { header('Location: /admin/addons?error=Please+pick+a+service.'); exit; }
            if (!$name)      { header('Location: /admin/addons?error=Addon+name+required.'); exit; }
            $db->prepare("INSERT INTO service_addons (service_id,name,price,is_active,is_global) VALUES (?,?,?,1,0)")
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
            $id = (int)$_POST['id'];
            try {
                $db->prepare("DELETE FROM service_addons WHERE id=?")->execute([$id]);
                header('Location: /admin/addons?msg=Add-on+deleted.'); exit;
            } catch (PDOException $e) {
                // Referenced by past bookings (booking_addons FK) — hide instead of delete.
                $db->prepare("UPDATE service_addons SET is_active=0 WHERE id=?")->execute([$id]);
                header('Location: /admin/addons?msg=' . urlencode("Add-on is used by past bookings — hidden instead of deleted.")); exit;
            }
        }
    } catch (Exception $e) {
        error_log('Addons error: '.$e->getMessage());
        header('Location: /admin/addons?error=' . urlencode($e->getMessage())); exit;
    }
}

$services = $db->query("SELECT * FROM services WHERE is_active=1 ORDER BY display_order")->fetchAll();

// Per-service add-ons (retired global rows have service_id = NULL and are excluded by the JOIN).
$addons = $db->query("
    SELECT sa.*, s.name AS s_name
    FROM service_addons sa
    JOIN services s ON s.id = sa.service_id
    WHERE sa.service_id IS NOT NULL
    ORDER BY s.display_order, sa.id
")->fetchAll();

$addonsByService = [];
foreach ($addons as $a) $addonsByService[$a['service_id']][] = $a;

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
    <p style="color:var(--admin-muted);font-size:0.8rem">Each add-on belongs to a single service and has its own price. They appear as optional extras during Step 2 of the booking flow for that service. Edit a name or price inline and press ✓ to save — no need to delete first.</p>
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
              <tr><th>Name &amp; Price (editable)</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($svcAddons as $addon): ?>
            <tr>
              <td>
                <!-- Inline edit form, fully contained in this cell -->
                <form method="POST" action="/admin/addons" style="display:flex;gap:6px;align-items:center;margin:0">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= $addon['id'] ?>">
                  <input class="admin-input" type="text" name="name" value="<?= htmlspecialchars($addon['name']) ?>"
                         style="font-size:0.8rem;padding:4px 8px" required>
                  <span style="font-size:0.75rem;color:var(--admin-muted)">£</span>
                  <input class="admin-input" type="number" name="price" value="<?= htmlspecialchars($addon['price']) ?>"
                         step="0.01" min="0" style="width:80px;font-size:0.8rem;padding:4px 8px" required>
                  <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" title="Save changes">✓</button>
                </form>
              </td>
              <td>
                <span class="status-badge <?= $addon['is_active'] ? 'status-confirmed' : 'status-cancelled' ?>">
                  <?= $addon['is_active'] ? 'Active' : 'Hidden' ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:4px">
                  <form method="POST" action="/admin/addons" style="margin:0">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $addon['id'] ?>">
                    <button type="submit" class="btn-admin btn-admin-outline btn-admin-sm">
                      <?= $addon['is_active'] ? 'Hide' : 'Show' ?>
                    </button>
                  </form>
                  <form method="POST" action="/admin/addons" style="margin:0"
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

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
