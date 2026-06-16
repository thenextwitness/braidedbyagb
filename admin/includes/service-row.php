<?php
// ============================================================
// BraidedbyAGB — Service Row Partial
// FILE: /admin/includes/service-row.php
// Expects: $svc, $variantsByService, $categories
// ============================================================
$variants = $variantsByService[$svc['id']] ?? [];
$addons   = $addonsByService[$svc['id']] ?? [];
?>
<div class="admin-card" style="margin-bottom:8px;<?= !$svc['is_active'] ? 'opacity:0.7' : '' ?>">

  <!-- Collapsed header (click to expand) -->
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
    <!-- Price summary -->
    <span style="font-size:0.8rem;font-weight:700;color:var(--admin-primary)">
      <?php if ($svc['variant_count'] > 0): ?>
        £<?= number_format((float)$svc['min_price'],0) ?>–£<?= number_format((float)$svc['max_price'],0) ?>
        <span style="font-weight:400;color:var(--admin-muted)">(<?= $svc['variant_count'] ?> options)</span>
      <?php else: ?>
        From £<?= number_format((float)$svc['price_from'],0) ?>
      <?php endif; ?>
    </span>
    <!-- Badges -->
    <?php if ($svc['is_new']): ?>
      <span class="status-badge status-pending">New</span>
    <?php endif; ?>
    <span class="status-badge <?= $svc['is_active'] ? 'status-confirmed' : 'status-cancelled' ?>">
      <?= $svc['is_active'] ? 'Active' : 'Hidden' ?>
    </span>
    <!-- Quick delete -->
    <form method="POST" action="/admin/services" onclick="event.stopPropagation()">
      <input type="hidden" name="action" value="delete_service">
      <input type="hidden" name="id"     value="<?= $svc['id'] ?>">
      <button class="btn-admin btn-admin-danger btn-admin-sm"
              onclick="return confirm('Hide \'<?= addslashes($svc['name']) ?>\' from the website? (You can re-enable it by editing the service)')"
              title="Hide service">
        <?= $svc['is_active'] ? 'Hide' : 'Unhide' ?>
      </button>
    </form>
  </div>

  <!-- Expanded edit panel (hidden by default) -->
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
            <label class="admin-label">Duration</label>
            <?php
              $durMins = max(0, (int)$svc['duration_mins']);
              $durH    = intdiv($durMins, 60);
              $durM    = $durMins % 60;
              $mOpts   = [0, 15, 30, 45];
              if (!in_array($durM, $mOpts)) { $mOpts[] = $durM; sort($mOpts); }
            ?>
            <div class="dur-picker" style="display:flex;gap:6px;align-items:center">
              <select class="admin-input admin-select dur-h" style="width:72px" onchange="durPickerSync(this)">
                <?php for ($h = 0; $h <= 8; $h++): ?>
                  <option value="<?= $h ?>" <?= $h === $durH ? 'selected' : '' ?>><?= $h ?>h</option>
                <?php endfor; ?>
              </select>
              <select class="admin-input admin-select dur-m" style="width:72px" onchange="durPickerSync(this)">
                <?php foreach ($mOpts as $mo): ?>
                  <option value="<?= $mo ?>" <?= $mo === $durM ? 'selected' : '' ?>><?= sprintf('%02d', $mo) ?>m</option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="duration_mins" class="dur-mins-val" value="<?= $durMins ?>">
              <span class="dur-preview" style="font-size:0.75rem;color:var(--admin-muted)"><?= formatDuration($durMins) ?></span>
            </div>
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
            <label class="admin-label">Prep Notes</label>
            <textarea class="admin-input admin-textarea" name="prep_notes" rows="2"><?= htmlspecialchars($svc['prep_notes'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="admin-form-group" style="margin-bottom:12px">
          <label class="admin-label">Service Image</label>
          <?php if (!empty($svc['image_url'])): ?>
            <div style="margin-bottom:6px">
              <img src="<?= htmlspecialchars($svc['image_url']) ?>" style="height:70px;width:70px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border)">
            </div>
          <?php endif; ?>
          <input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="padding:6px;cursor:pointer">
          <span style="font-size:0.7rem;color:var(--admin-muted)">Upload JPG/PNG/WebP (max 5MB) — or paste URL below:</span>
          <input class="admin-input" type="url" name="image_url" value="<?= htmlspecialchars($svc['image_url'] ?? '') ?>" placeholder="https://… (optional if uploading above)" style="margin-top:6px">
        </div>

        <div style="display:flex;gap:16px;align-items:center;margin-bottom:14px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
            <input type="checkbox" name="is_active" <?= $svc['is_active'] ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
            Active (visible on site)
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.8rem">
            <input type="checkbox" name="is_new" <?= $svc['is_new'] ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
            Show "New" badge
          </label>
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Save Changes</button>
          <form method="POST" action="/admin/services" style="display:inline">
            <input type="hidden" name="action" value="delete_service_permanent">
            <input type="hidden" name="id"     value="<?= $svc['id'] ?>">
            <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                    onclick="return confirm('PERMANENTLY delete <?= addslashes($svc['name']) ?>? This cannot be undone.\n\nIf it has existing bookings, this will fail — hide it instead.')">
              Delete Permanently
            </button>
          </form>
        </div>
      </form>

      <!-- Price variants panel -->
      <div style="width:340px;flex-shrink:0;background:#faf8fd;border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);padding:14px">
        <p style="font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">
          Price Variants
        </p>

        <?php if (empty($variants)): ?>
          <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:10px">No variants — service uses "Price From" above.</p>
        <?php endif; ?>

        <?php foreach ($variants as $v): ?>
        <div style="display:flex;gap:5px;align-items:center;margin-bottom:6px">
          <form method="POST" action="/admin/services" style="display:flex;gap:5px;align-items:center;flex:1">
            <input type="hidden" name="action" value="update_variant">
            <input type="hidden" name="id"     value="<?= $v['id'] ?>">
            <input class="admin-input" type="text"   name="variant_name"  value="<?= htmlspecialchars($v['variant_name']) ?>" style="flex:1;font-size:0.75rem;padding:5px 7px" placeholder="Name">
            <input class="admin-input" type="number" name="price"         value="<?= $v['price'] ?>"        step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" title="Price £">
            <?php $vDur = (int)($v['duration_mins'] ?? 0); ?>
            <div class="dur-picker" style="display:flex;gap:3px;align-items:center">
              <input type="number" class="admin-input dur-h" min="0" max="8" step="1"
                     style="width:36px;font-size:0.72rem;padding:4px 5px;text-align:center"
                     value="<?= intdiv($vDur, 60) ?>" title="Hours"
                     oninput="durPickerSync(this)">
              <span style="font-size:0.7rem;color:var(--admin-muted)">h</span>
              <input type="number" class="admin-input dur-m" min="0" max="59" step="15"
                     style="width:36px;font-size:0.72rem;padding:4px 5px;text-align:center"
                     value="<?= $vDur % 60 ?>" title="Minutes"
                     oninput="durPickerSync(this)">
              <span style="font-size:0.7rem;color:var(--admin-muted)">m</span>
              <input type="hidden" name="duration_mins" class="dur-mins-val" value="<?= $vDur ?>">
            </div>
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" title="Save">✓</button>
          </form>
          <form method="POST" action="/admin/services">
            <input type="hidden" name="action" value="delete_variant">
            <input type="hidden" name="id"     value="<?= $v['id'] ?>">
            <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                    onclick="return confirm('Delete variant \'<?= addslashes($v['variant_name']) ?>\'?')" title="Delete">✕</button>
          </form>
        </div>
        <?php endforeach; ?>

        <!-- Add variant -->
        <form method="POST" action="/admin/services"
              style="display:flex;gap:5px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--admin-border)">
          <input type="hidden" name="action"     value="add_variant">
          <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
          <input class="admin-input" type="text"   name="variant_name"  placeholder="e.g. Small" style="flex:1;font-size:0.75rem;padding:5px 7px" required>
          <input class="admin-input" type="number" name="price"         placeholder="£"    step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" required>
          <?php $addDur = (int)$svc['duration_mins']; ?>
          <div class="dur-picker" style="display:flex;gap:3px;align-items:center">
            <input type="number" class="admin-input dur-h" min="0" max="8" step="1"
                   style="width:36px;font-size:0.72rem;padding:4px 5px;text-align:center"
                   value="<?= intdiv($addDur, 60) ?>" title="Hours"
                   oninput="durPickerSync(this)">
            <span style="font-size:0.7rem;color:var(--admin-muted)">h</span>
            <input type="number" class="admin-input dur-m" min="0" max="59" step="15"
                   style="width:36px;font-size:0.72rem;padding:4px 5px;text-align:center"
                   value="<?= $addDur % 60 ?>" title="Minutes"
                   oninput="durPickerSync(this)">
            <span style="font-size:0.7rem;color:var(--admin-muted)">m</span>
            <input type="hidden" name="duration_mins" class="dur-mins-val" value="<?= $addDur ?>">
          </div>
          <button type="submit" class="btn-admin btn-admin-gold btn-admin-sm">+ Add</button>
        </form>
      </div>

      <!-- Add-ons panel -->
      <div style="width:340px;flex-shrink:0;background:#fdf8ff;border:1px solid var(--admin-border);border-radius:var(--admin-radius-lg);padding:14px">
        <p style="font-family:'Montserrat',sans-serif;font-size:0.65rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--admin-muted);margin-bottom:10px">
          Optional Add-ons
        </p>

        <?php if (empty($addons)): ?>
          <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:10px">No add-ons yet.</p>
        <?php endif; ?>

        <?php foreach ($addons as $addon): ?>
        <div style="display:flex;gap:5px;align-items:center;margin-bottom:6px">
          <form method="POST" action="/admin/services" style="display:flex;gap:5px;align-items:center;flex:1">
            <input type="hidden" name="action" value="update_addon">
            <input type="hidden" name="id"     value="<?= $addon['id'] ?>">
            <input class="admin-input" type="text"   name="addon_name"  value="<?= htmlspecialchars($addon['name']) ?>"  style="flex:1;font-size:0.75rem;padding:5px 7px" placeholder="Add-on name">
            <input class="admin-input" type="number" name="addon_price" value="<?= $addon['price'] ?>" step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" title="Price £">
            <label title="Active" style="display:flex;align-items:center;gap:3px;font-size:0.7rem;cursor:pointer">
              <input type="checkbox" name="addon_active" value="1" <?= $addon['is_active'] ? 'checked' : '' ?> style="accent-color:var(--admin-primary)">
              On
            </label>
            <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm" title="Save">✓</button>
          </form>
          <form method="POST" action="/admin/services">
            <input type="hidden" name="action" value="delete_addon">
            <input type="hidden" name="id"     value="<?= $addon['id'] ?>">
            <button type="submit" class="btn-admin btn-admin-danger btn-admin-sm"
                    onclick="return confirm('Delete add-on \'<?= addslashes($addon['name']) ?>\'?')" title="Delete">✕</button>
          </form>
        </div>
        <?php endforeach; ?>

        <!-- Add new add-on -->
        <form method="POST" action="/admin/services"
              style="display:flex;gap:5px;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--admin-border)">
          <input type="hidden" name="action"     value="add_addon">
          <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
          <input class="admin-input" type="text"   name="addon_name"  placeholder="e.g. Beads" style="flex:1;font-size:0.75rem;padding:5px 7px" required>
          <input class="admin-input" type="number" name="addon_price" placeholder="£" step="0.01" min="0" style="width:66px;font-size:0.75rem;padding:5px 7px" required>
          <button type="submit" class="btn-admin btn-admin-gold btn-admin-sm">+ Add</button>
        </form>
      </div>

    </div>
  </div>
</div>
