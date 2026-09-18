<?php
// ============================================================
// BraidedbyAGB — Admin Service Gallery
// FILE: /admin/gallery.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Manage the customer-facing gallery (/gallery). Each image can be tagged to a
// service (or left general) and carries a caption. Uploads reuse uploadImage().
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
        if ($action === 'create_image') {
            $serviceId = ($_POST['service_id'] ?? '') === '' ? null : (int)$_POST['service_id'];
            $caption   = sanitize($_POST['caption'] ?? '');
            if (empty($_FILES['image_file']['name']) || $_FILES['image_file']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Choose an image to upload (JPG/PNG/WebP, max 5MB).';
            } else {
                $url = uploadImage($_FILES['image_file'], 'gallery');
                if (!$url) {
                    $error = 'Image upload failed — check it is JPG/PNG/WebP under 5MB.';
                } else {
                    $max = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM gallery_images")->fetchColumn();
                    $db->prepare("INSERT INTO gallery_images (service_id, image_url, caption, display_order, is_active)
                                  VALUES (?,?,?,?,1)")
                       ->execute([$serviceId, $url, $caption, $max + 1]);
                    $msg = 'Image added to the gallery.';
                }
            }

        } elseif ($action === 'update_image') {
            $id        = (int)($_POST['id'] ?? 0);
            $serviceId = ($_POST['service_id'] ?? '') === '' ? null : (int)$_POST['service_id'];
            $caption   = sanitize($_POST['caption'] ?? '');
            $order     = (int)($_POST['display_order'] ?? 0);
            $active    = isset($_POST['is_active']) ? 1 : 0;
            $db->prepare("UPDATE gallery_images SET service_id=?, caption=?, display_order=?, is_active=? WHERE id=?")
               ->execute([$serviceId, $caption, $order, $active, $id]);
            $msg = 'Image updated.';

        } elseif ($action === 'delete_image') {
            $db->prepare("DELETE FROM gallery_images WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'Image deleted.';
        }
    } catch (Exception $e) {
        error_log('Gallery admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Service Gallery';
require_once __DIR__ . '/includes/layout.php';

try {
    $services = $db->query("SELECT id, name FROM services WHERE is_active=1 ORDER BY name")->fetchAll();
    $images = $db->query("SELECT g.*, s.name AS service_name
                          FROM gallery_images g LEFT JOIN services s ON s.id = g.service_id
                          ORDER BY g.is_active DESC, g.display_order ASC, g.id DESC")->fetchAll();
} catch (Exception $e) {
    $services = []; $images = [];
    $error = $error ?: 'Could not load the gallery. Run the database migration first.';
}
$activeImgs = count(array_filter($images, fn($i) => (int)$i['is_active'] === 1));
?>

<?php if ($msg):   ?><div class="alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Service Gallery</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= $activeImgs ?> live · shown on <a href="/gallery" target="_blank">/gallery</a></p>
  </div>
  <button class="btn-admin btn-admin-primary" onclick="document.getElementById('gallery-upload').classList.toggle('hidden')">+ Add Image</button>
</div>

<!-- Upload -->
<div id="gallery-upload" class="hidden" style="margin-bottom:24px">
  <div class="admin-card">
    <div class="admin-card-header"><span class="admin-card-title">✦ Add Gallery Image</span></div>
    <div class="admin-card-body">
      <form method="POST" action="/admin/gallery" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_image">
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Image *</label>
            <input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" required style="padding:6px">
            <small style="color:var(--admin-muted);font-size:0.72rem">JPG, PNG or WebP · max 5MB</small>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Service (optional)</label>
            <select class="admin-input" name="service_id">
              <option value="">General (no service)</option>
              <?php foreach ($services as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Caption</label>
            <input class="admin-input" type="text" name="caption" placeholder="e.g. Knotless braids, waist length">
          </div>
        </div>
        <button type="submit" class="btn-admin btn-admin-primary">Upload</button>
      </form>
    </div>
  </div>
</div>

<!-- Grid -->
<?php if (empty($images)): ?>
  <div class="table-empty"><div class="table-empty-icon">🖼️</div><p>No gallery images yet. Add your first above.</p></div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px">
    <?php foreach ($images as $img): ?>
      <div class="admin-card" style="<?= (int)$img['is_active'] ? '' : 'opacity:.6' ?>">
        <img src="<?= htmlspecialchars($img['image_url']) ?>" alt="<?= htmlspecialchars($img['caption'] ?? '') ?>"
             style="width:100%;height:180px;object-fit:cover;border-radius:var(--admin-radius) var(--admin-radius) 0 0;display:block">
        <div class="admin-card-body">
          <form method="POST" action="/admin/gallery">
            <input type="hidden" name="action" value="update_image">
            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
            <div class="admin-form-group" style="margin-bottom:8px">
              <label class="admin-label" style="font-size:0.7rem">Caption</label>
              <input class="admin-input" type="text" name="caption" value="<?= htmlspecialchars($img['caption'] ?? '') ?>">
            </div>
            <div class="admin-form-row" style="margin-bottom:8px;gap:8px">
              <div class="admin-form-group" style="margin:0">
                <label class="admin-label" style="font-size:0.7rem">Service</label>
                <select class="admin-input" name="service_id">
                  <option value="">General</option>
                  <?php foreach ($services as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$img['service_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="admin-form-group" style="margin:0;width:90px">
                <label class="admin-label" style="font-size:0.7rem">Order</label>
                <input class="admin-input" type="number" name="display_order" value="<?= (int)$img['display_order'] ?>">
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:6px;font-size:0.8rem;margin-bottom:10px;cursor:pointer">
              <input type="checkbox" name="is_active" <?= (int)$img['is_active'] ? 'checked' : '' ?>> Live on website
            </label>
            <div style="display:flex;gap:8px">
              <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Save</button>
              <button type="submit" form="del-<?= (int)$img['id'] ?>" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Delete</button>
            </div>
          </form>
          <form id="del-<?= (int)$img['id'] ?>" method="POST" action="/admin/gallery" style="display:none"
                onsubmit="return confirm('Delete this image from the gallery?')">
            <input type="hidden" name="action" value="delete_image">
            <input type="hidden" name="id" value="<?= (int)$img['id'] ?>">
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<style>#gallery-upload.hidden{display:none}</style>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
