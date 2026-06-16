<?php
// ============================================================
// BraidedbyAGB — Admin Services & Prices
// FILE: /admin/services.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    try {
        // ── Create new service ──────────────────────────────
        if ($action === 'create_service') {
            $name     = sanitize($_POST['name'] ?? '');
            $desc     = sanitize($_POST['description'] ?? '');
            $duration = (int)($_POST['duration_mins'] ?? 120);
            $price    = (float)($_POST['price_from'] ?? 0);
            $category = sanitize($_POST['category'] ?? '');
            $prep     = sanitize($_POST['prep_notes'] ?? '');
            if (!$name) { $error = 'Service name is required.'; }
            else {
                // Generate slug
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));
                $slug = trim($slug, '-');
                // Ensure unique slug
                $existing = $db->prepare("SELECT id FROM services WHERE slug=?");
                $existing->execute([$slug]);
                if ($existing->fetch()) $slug .= '-' . time();

                // Handle image upload
                $imageUrl = '';
                if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $uploaded = uploadImage($_FILES['image_file'], 'services');
                    if ($uploaded) $imageUrl = $uploaded;
                    else $error = 'Image upload failed — check file is JPG/PNG/WebP under 5MB.';
                }

                if (!$error) {
                    $maxOrder = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM services")->fetchColumn();
                    $db->prepare("INSERT INTO services (name,slug,description,duration_mins,price_from,category,prep_notes,image_url,is_active,display_order) VALUES (?,?,?,?,?,?,?,?,1,?)")
                       ->execute([$name,$slug,$desc,$duration,$price,$category,$prep,$imageUrl,$maxOrder+1]);
                    $msg = "Service <strong>$name</strong> created.";
                }
            }

        // ── Update service ──────────────────────────────────
        } elseif ($action === 'update_service') {
            $id = (int)$_POST['id'];

            // Image: file upload takes priority, then URL field, then keep existing
            $img = sanitize($_POST['image_url'] ?? '');
            if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $up = uploadImage($_FILES['image_file'], 'services');
                if ($up) { $img = $up; }
                else { $error = 'Image upload failed — use JPG/PNG/WebP max 5MB.'; }
            }

            if (!$error) {
                $db->prepare("UPDATE services SET name=?,description=?,duration_mins=?,price_from=?,category=?,prep_notes=?,image_url=?,is_new=?,is_active=? WHERE id=?")
                   ->execute([
                       sanitize($_POST['name']),
                       sanitize($_POST['description'] ?? ''),
                       (int)$_POST['duration_mins'],
                       (float)$_POST['price_from'],
                       sanitize($_POST['category'] ?? ''),
                       sanitize($_POST['prep_notes'] ?? ''),
                       $img,
                       isset($_POST['is_new'])    ? 1 : 0,
                       isset($_POST['is_active']) ? 1 : 0,
                       $id,
                   ]);
                $msg = 'Service updated.';
            }

        // ── Delete service ──────────────────────────────────
        } elseif ($action === 'delete_service') {
            $id = (int)$_POST['id'];
            // Soft delete — just hide it (preserve booking history)
            $db->prepare("UPDATE services SET is_active=0 WHERE id=?")->execute([$id]);
            $msg = 'Service hidden from website.';

        // ── Permanently delete ──────────────────────────────
        } elseif ($action === 'delete_service_permanent') {
            $id = (int)$_POST['id'];
            $inUse = (int)$db->prepare("SELECT COUNT(*) FROM bookings WHERE service_id=?")->execute([$id]) ?
                     $db->query("SELECT COUNT(*) FROM bookings WHERE service_id=$id")->fetchColumn() : 0;
            if ($inUse > 0) {
                $error = 'Cannot delete — this service has existing bookings. Hide it instead.';
            } else {
                $db->prepare("DELETE FROM service_variants WHERE service_id=?")->execute([$id]);
                $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
                $msg = 'Service permanently deleted.';
            }

        // ── Variant actions ─────────────────────────────────
        } elseif ($action === 'add_variant') {
            $sid  = (int)$_POST['service_id'];
            $max  = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM service_variants WHERE service_id=$sid")->fetchColumn();
            $db->prepare("INSERT INTO service_variants (service_id,variant_name,price,duration_mins,display_order) VALUES (?,?,?,?,?)")
               ->execute([$sid, sanitize($_POST['variant_name']), (float)$_POST['price'], (int)$_POST['duration_mins'], $max+1]);
            $msg = 'Variant added.';

        } elseif ($action === 'update_variant') {
            $db->prepare("UPDATE service_variants SET variant_name=?,price=?,duration_mins=? WHERE id=?")
               ->execute([sanitize($_POST['variant_name']), (float)$_POST['price'], (int)$_POST['duration_mins'], (int)$_POST['id']]);
            $msg = 'Variant updated.';

        } elseif ($action === 'delete_variant') {
            $db->prepare("DELETE FROM service_variants WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Variant deleted.';

        // ── Add-on actions ──────────────────────────────────
        } elseif ($action === 'add_addon') {
            $sid  = (int)$_POST['service_id'];
            $aname = sanitize($_POST['addon_name'] ?? '');
            $aprice = (float)($_POST['addon_price'] ?? 0);
            if ($aname) {
                $db->prepare("INSERT INTO service_addons (service_id, name, price, is_active) VALUES (?,?,?,1)")
                   ->execute([$sid, $aname, $aprice]);
                $msg = 'Add-on added.';
            }

        } elseif ($action === 'update_addon') {
            $db->prepare("UPDATE service_addons SET name=?, price=?, is_active=? WHERE id=?")
               ->execute([
                   sanitize($_POST['addon_name'] ?? ''),
                   (float)($_POST['addon_price'] ?? 0),
                   isset($_POST['addon_active']) ? 1 : 0,
                   (int)$_POST['id'],
               ]);
            $msg = 'Add-on updated.';

        } elseif ($action === 'delete_addon') {
            $db->prepare("DELETE FROM service_addons WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Add-on deleted.';
        }

    } catch (Exception $e) {
        error_log('Services admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Services & Prices';
require_once __DIR__ . '/includes/layout.php';

// Fetch all services (including hidden, so admin can re-enable them)
try {
    $services = $db->query("
        SELECT s.*, COUNT(sv.id) as variant_count,
               MIN(sv.price) as min_price, MAX(sv.price) as max_price
        FROM services s
        LEFT JOIN service_variants sv ON sv.service_id = s.id
        GROUP BY s.id ORDER BY s.is_active DESC, s.display_order ASC
    ")->fetchAll();

    $variantsByService = [];
    foreach ($db->query("SELECT * FROM service_variants ORDER BY service_id, display_order ASC")->fetchAll() as $v) {
        $variantsByService[$v['service_id']][] = $v;
    }

    $addonsByService = [];
    foreach ($db->query("SELECT * FROM service_addons ORDER BY service_id, id ASC")->fetchAll() as $a) {
        $addonsByService[$a['service_id']][] = $a;
    }
} catch (Exception $e) {
    $services = []; $variantsByService = [];
    $error = 'Could not load services. Check database tables are set up.';
}

$categories = ['Locs', 'Braids', 'Cornrows', 'Twists', 'Extensions', 'Other'];
?>

<?php if ($msg):   ?><div class="alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── Page header with Create button ──────────────────── -->
<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Services & Prices</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= count($services) ?> services · click a service to expand and edit</p>
  </div>
  <button class="btn-admin btn-admin-primary" onclick="toggleCreateForm()">+ New Service</button>
</div>

<!-- ── Create new service (collapsed by default) ────────── -->
<div id="create-form-wrap" style="display:none;margin-bottom:24px">
  <div class="admin-card">
    <div class="admin-card-header">
      <span class="admin-card-title">✦ Create New Service</span>
      <button class="btn-admin btn-admin-outline btn-admin-sm" onclick="toggleCreateForm()">✕ Cancel</button>
    </div>
    <div class="admin-card-body">
      <form method="POST" action="/admin/services" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_service">
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Service Name *</label>
            <input class="admin-input" type="text" name="name" placeholder="e.g. Knotless Braids" required autofocus>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Category</label>
            <select class="admin-input" name="category">
              <option value="">— Select —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Duration *</label>
            <div class="dur-picker" style="display:flex;gap:6px;align-items:center">
              <select class="admin-input admin-select dur-h" style="width:72px" onchange="durPickerSync(this)">
                <?php for ($h = 0; $h <= 8; $h++): ?>
                  <option value="<?= $h ?>" <?= $h === 3 ? 'selected' : '' ?>><?= $h ?>h</option>
                <?php endfor; ?>
              </select>
              <select class="admin-input admin-select dur-m" style="width:72px" onchange="durPickerSync(this)">
                <?php foreach ([0,15,30,45] as $m): ?>
                  <option value="<?= $m ?>"><?= sprintf('%02d', $m) ?>m</option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="duration_mins" class="dur-mins-val" value="180">
              <span class="dur-preview" style="font-size:0.75rem;color:var(--admin-muted)">3h</span>
            </div>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Price From (£) *</label>
            <input class="admin-input" type="number" name="price_from" placeholder="e.g. 95" step="0.01" min="0" required>
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Service Image</label>
            <input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="padding:6px;cursor:pointer">
            <small style="color:var(--admin-muted);font-size:0.72rem">JPG, PNG or WebP · max 5MB</small>
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Description</label>
            <textarea class="admin-input admin-textarea" name="description" rows="2" placeholder="Brief description shown on services page..."></textarea>
          </div>
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Prep Notes (shown to client during booking)</label>
            <textarea class="admin-input admin-textarea" name="prep_notes" rows="2" placeholder="e.g. Hair must be freshly washed and fully detangled..."></textarea>
          </div>
        </div>
        <button type="submit" class="btn-admin btn-admin-primary">Create Service</button>
      </form>
    </div>
  </div>
</div>

<!-- ── Services list ─────────────────────────────────────── -->
<?php if (empty($services)): ?>
  <div class="table-empty">
    <div class="table-empty-icon">✂️</div>
    <p>No services yet. Create your first one above.</p>
  </div>
<?php else: ?>

<?php
$activeServices = array_filter($services, fn($s) => $s['is_active']);
$hiddenServices = array_filter($services, fn($s) => !$s['is_active']);
?>

<?php if (!empty($activeServices)): ?>
<div style="margin-bottom:8px;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted)">
  Active Services (<?= count($activeServices) ?>)
</div>
<?php foreach ($activeServices as $svc): ?>
  <?php include __DIR__ . '/includes/service-row.php'; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($hiddenServices)): ?>
<div style="margin:20px 0 8px;font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted)">
  Hidden Services (<?= count($hiddenServices) ?>) — not visible on website
</div>
<?php foreach ($hiddenServices as $svc): ?>
  <?php include __DIR__ . '/includes/service-row.php'; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<script>
function toggleCreateForm() {
  const el = document.getElementById('create-form-wrap');
  const visible = el.style.display !== 'none';
  el.style.display = visible ? 'none' : 'block';
  if (!visible) el.querySelector('input[name="name"]').focus();
}
function toggleService(id) {
  const body = document.getElementById('svc-body-' + id);
  const arrow = document.getElementById('svc-arrow-' + id);
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  arrow.textContent = open ? '▸' : '▾';
}
// Duration picker — shared by create-service form and each service-row edit form.
// Called onchange from any <select> inside a .dur-picker wrapper.
function durPickerSync(el) {
  var picker  = el.closest('.dur-picker');
  var h       = parseInt(picker.querySelector('.dur-h').value)  || 0;
  var m       = parseInt(picker.querySelector('.dur-m').value)  || 0;
  var total   = h * 60 + m;
  picker.querySelector('.dur-mins-val').value = total;
  var preview = picker.querySelector('.dur-preview');
  if (preview) {
    if (total === 0)    preview.textContent = '0m';
    else if (h === 0)   preview.textContent = m + 'm';
    else if (m === 0)   preview.textContent = h + 'h';
    else                preview.textContent = h + 'h ' + m + 'm';
  }
}
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>