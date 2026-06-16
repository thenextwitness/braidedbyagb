<?php
// ============================================================
// BraidedbyAGB — Service Row Partial
// FILE: /admin/includes/service-row.php
// Expects: $svc, $variantsByService, $categories
// ============================================================
$variants  = $variantsByService[$svc['id']] ?? [];
$svcNameJs = addslashes($svc['name']);
?>
<div class="admin-card" style="margin-bottom:8px;<?= !$svc['is_active'] ? 'opacity:0.7' : '' ?>">

  <!-- Collapsed header -->
  <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer"
       onclick="toggleService(<?= $svc['id'] ?>)">
    <span id="svc-arrow-<?= $svc['id'] ?>" style="font-size:1rem;color:var(--admin-muted);width:16px">▸</span>
    <div style="flex:1">
      <span style="font-family:'Montserrat',sans-serif;font-weight:800;font-size:0.88rem;color:var(--admin-primary-dark)">
        <?= htmlspecialchars($svc['name']) ?>
      </span>
      <?php if ($svc['category']): ?>
        <span style="font-size:0.68rem;color:var(--admin-muted);margin-left:8px"><?= htmlspecialchars($svc['category']) ?></span>
      <?php endif; ?>
    </div>
    <span style="font-size:0.8rem;font-weight:700;color:var(--admin-primary)">
      <?php if ((int)$svc['variant_count'] > 0): ?>
        £<?= number_format((float)$svc['min_price'],0) ?>–£<?= number_format((float)$svc['max_price'],0) ?>
        <span style="font-weight:400;color:var(--admin-muted)">(<?= $svc['variant_count'] ?> options)</span>
      <?php else: ?>
        From £<?= number_format((float)$svc['price_from'],0) ?>
      <?php endif; ?>
    </span>
    <?php if ($svc['is_new']): ?>
      <span class="status-badge status-pending">New</span>
    <?php endif; ?>
    <span class="status-badge <?= $svc['is_active'] ? 'status-confirmed' : 'status-cancelled' ?>">
      <?= $svc['is_active'] ? 'Active' : 'Hidden' ?>
    </span>
    <!-- Hide/Show button — separate form, stops click propagating to toggle -->
    <form method="POST" action="/admin/services" onclick="event.stopPropagation()">
      <input type="hidden" name="action" value="<?= $svc['is_active'] ? 'delete_service' : 'restore_service' ?>">
      <input type="hidden" name="id"     value="<?= $svc['id'] ?>">
      <button class="btn-admin btn-admin-outline btn-admin-sm" type="submit">
        <?= $svc['is_active'] ? 'Hide' : 'Show' ?>
      </button>
    </form>
  </div>

  <!-- Expanded edit panel -->
  <div id="svc-body-<?= $svc['id'] ?>" style="display:none;border-top:1px solid var(--admin-border);padding:16px">
    <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap">

      <!-- Edit form -->
      <form method="POST" action="/admin/services" enctype="multipart/form-data" style="flex:1;min-width:300px">
        <input type="hidden" name="action" value="update_service">
        <input type="hidden" name="id"     value="<?= $svc['id'] ?>">

        <div class="admin-form-row" style="margin-bottom:12px">
          <div class="admin-form-group">
            <label class="admin-label">Name</label>
            <input class="admin-input" type="text" name="name" value="<?= htmlspecialchars($svc['name']) ?>" required>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Category</label>
            <select class="admin-input" name="category">
              <option value="">— None —</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>" <?= $svc['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Duration (mins)</label>
            <input class="admin-input" type="number" name="duration_mins" value="<?= (int)$svc['duration_mins'] ?>" min="30" step="15">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Price From (£)</label>
            <input class="admin-input" type="number" name="price_from" value="<?= (float)$svc['price_from'] ?>" step="0.01" min="0">
          </div>
        </div>

        <div class="admin-form-row" style="margin-bottom:12px">
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Description</label>
            <textarea class="admin-input admin-textarea" name="description" rows="2"><?= htmlspecialchars($svc['description'] ?? '') ?></textarea>
          </div>
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Prep Notes (shown to client at booking)</label>
            <textarea class="admin-input admin-textarea" name="prep_notes" rows="2"><?= htmlspecialchars($svc['prep_notes'] ?? '') ?></textarea>
          </div>
        </div>

        <div style="display:flex;gap:16px;align-items:center;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
            <input type="hidden"   name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" <?= $svc['is_active'] ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
            Active (visible on site)
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
            <input type="hidden"   name="is_new" value="0">
            <input type="checkbox" name="is_new" value="1" <?= $svc['is_new'] ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
            Show &ldquo;New&rdquo; badge
          </label>
        </div>

        <div class="admin-form-group" style="margin-bottom:14px">
          <label class="admin-label">Service Image</label>
          <?php if (!empty($svc['image_url'])): ?>
            <div style="margin-bottom:6px">
              <img src="<?= htmlspecialchars($svc['image_url']) ?>" alt="Current"
                   style="height:64px;width:80px;object-fit:cover;border-radius:4px;border:1px solid var(--admin-border)">
              <span style="font-size:0.7rem;color:var(--admin-muted);display:block">Current image</span>
            </div>
          <?php endif; ?>
          <input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp"
                 style="padding:5px;cursor:pointer;font-size:0.8rem">
          <span style="font-size:0.7rem;color:var(--admin-muted)">JPG/PNG/WebP, max 5MB</span>
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Save Changes</button>
        </div>
      </form>

      <!-- Delete permanently — SEPARATE form, outside the save form above -->
      <form method="POST" action="/admin/services" style="margin-top:8px"
            onsubmit="return confirm('Permanently delete \'<?= $svcNameJs ?>\'?\n\nThis cannot be undone. If it has bookings, hide it instead.')">
        <input type="hidden" name="action" value="delete_service_permanent">
        <input type="hidden" name="id"     value="<?= $svc['id'] ?>">
        <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm">🗑 Delete Permanently</button>
      </form>

      <!-- Price variants panel -->
      <div style="width:340px;flex-shrink:0;background:#faf8fd;border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);padding:14px">
        <p style="font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">
          Price Variants
        </p>

        <?php if (empty($variants)): ?>
          <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:10px">No variants — uses &ldquo;Price From&rdquo; above. Add variants for different lengths/sizes.</p>
        <?php endif; ?>

        <?php foreach ($variants as $v): ?>
        <div style="display:flex;gap:5px;align-items:center;margin-bottom:6px">
          <form method="POST" action="/admin/services" style="display:flex;gap:5px;align-items:center;flex:1">
            <input type="hidden" name="action" value="update_variant">
            <input type="hidden" name="id"     value="<?= $v['id'] ?>">
            <input class="admin-input" type="text"   name="variant_name"  value="<?= htmlspecialchars($v['variant_name']) ?>" style="flex:1;font-size:0.75rem;padding:5px 7px" placeholder="Name">
            <input class="admin-input" type="number" name="price"         value="<?= $v['price'] ?>"         step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" title="Price £">
            <input class="admin-input" type="number" name="duration_mins" value="<?= $v['duration_mins'] ?>" min="15"    step="15" style="width:58px;font-size:0.75rem;padding:5px 7px" title="Mins">
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" title="Save">✓</button>
          </form>
          <form method="POST" action="/admin/services"
                onsubmit="return confirm('Delete variant \'<?= addslashes($v['variant_name']) ?>\'?')">
            <input type="hidden" name="action" value="delete_variant">
            <input type="hidden" name="id"     value="<?= $v['id'] ?>">
            <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm" title="Delete">✕</button>
          </form>
        </div>
        <?php endforeach; ?>

        <!-- Add variant -->
        <form method="POST" action="/admin/services"
              style="display:flex;gap:5px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--admin-border)">
          <input type="hidden" name="action"     value="add_variant">
          <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
          <input class="admin-input" type="text"   name="variant_name"  placeholder="e.g. Small" style="flex:1;font-size:0.75rem;padding:5px 7px" required>
          <input class="admin-input" type="number" name="price"         placeholder="£"  step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" required>
          <input class="admin-input" type="number" name="duration_mins" value="<?= (int)$svc['duration_mins'] ?>" min="15" step="15" style="width:58px;font-size:0.75rem;padding:5px 7px">
          <button type="submit" class="btn-admin btn-admin-gold btn-admin-sm">+ Add</button>
        </form>
      </div>

    </div>
  </div>
</div>
