<?php
// ============================================================
// BraidedbyAGB — Admin Team / Stylists
// FILE: /admin/stylists.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Manages the people who work bookings. A stylist is a SEPARATE identity from
// an admin_user (admins run this panel; stylists get the client-style portal).
// Creating an active, portal-enabled stylist is all that's needed for them to
// sign in at /login via an emailed code — no invite step.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$msg = ''; $error = '';

/** Clamp a commission percent to the DECIMAL(5,2) 0–100 range. */
function clampPct($v): float { return max(0.0, min(100.0, round((float)$v, 2))); }
function clampRate($v): float { return max(0.0, round((float)$v, 2)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    try {
        // ── Create stylist ──────────────────────────────────
        if ($action === 'create_stylist') {
            $name  = sanitize($_POST['name'] ?? '');
            $email = strtolower(trim(sanitize($_POST['email'] ?? '')));
            $phone = sanitize($_POST['phone'] ?? '');
            $type  = in_array($_POST['stylist_type'] ?? '', ['braider','barber','both'], true) ? $_POST['stylist_type'] : 'braider';
            $pct   = clampPct($_POST['default_commission_pct'] ?? 0);
            $rate  = clampRate($_POST['default_hourly_rate'] ?? 0);
            $bio   = sanitize($_POST['bio'] ?? '');
            $portal = isset($_POST['portal_enabled']) ? 1 : 0;

            if ($name === '' || !validateEmail($email)) {
                $error = 'A name and a valid email are required.';
            } else {
                $dupe = $db->prepare("SELECT id FROM stylists WHERE email = ?");
                $dupe->execute([$email]);
                if ($dupe->fetch()) {
                    $error = 'A stylist with that email already exists.';
                } else {
                    $db->prepare("INSERT INTO stylists
                        (name,email,phone,stylist_type,default_commission_pct,default_hourly_rate,bio,portal_enabled,is_active)
                        VALUES (?,?,?,?,?,?,?,?,1)")
                       ->execute([$name,$email,$phone,$type,$pct,$rate,$bio,$portal]);
                    $msg = "Stylist <strong>" . htmlspecialchars($name) . "</strong> added." .
                           ($portal ? " They can sign in at /login with an emailed code." : "");
                }
            }

        // ── Update stylist ──────────────────────────────────
        } elseif ($action === 'update_stylist') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare("SELECT * FROM stylists WHERE id = ?");
            $st->execute([$id]);
            $stylist = $st->fetch();
            if (!$stylist) { $error = 'Stylist not found.'; }
            else {
                $name  = sanitize($_POST['name'] ?? '');
                $email = strtolower(trim(sanitize($_POST['email'] ?? '')));
                $phone = sanitize($_POST['phone'] ?? '');
                $type  = in_array($_POST['stylist_type'] ?? '', ['braider','barber','both'], true) ? $_POST['stylist_type'] : $stylist['stylist_type'];
                $pct   = clampPct($_POST['default_commission_pct'] ?? 0);
                $rate  = clampRate($_POST['default_hourly_rate'] ?? 0);
                $bio   = sanitize($_POST['bio'] ?? '');
                // The owner must always stay active and stays off the portal.
                $isOwner = (int)$stylist['is_owner'] === 1;
                $active  = $isOwner ? 1 : (isset($_POST['is_active']) ? 1 : 0);
                $portal  = $isOwner ? 0 : (isset($_POST['portal_enabled']) ? 1 : 0);

                if ($name === '' || !validateEmail($email)) {
                    $error = 'A name and a valid email are required.';
                } else {
                    $dupe = $db->prepare("SELECT id FROM stylists WHERE email = ? AND id <> ?");
                    $dupe->execute([$email, $id]);
                    if ($dupe->fetch()) {
                        $error = 'Another stylist already uses that email.';
                    } else {
                        $db->prepare("UPDATE stylists SET
                            name=?,email=?,phone=?,stylist_type=?,default_commission_pct=?,
                            default_hourly_rate=?,bio=?,is_active=?,portal_enabled=? WHERE id=?")
                           ->execute([$name,$email,$phone,$type,$pct,$rate,$bio,$active,$portal,$id]);

                        // Skills allow-list (stylist_services): replace wholesale.
                        $db->prepare("DELETE FROM stylist_services WHERE stylist_id=?")->execute([$id]);
                        $svcIds = array_map('intval', (array)($_POST['services'] ?? []));
                        if ($svcIds) {
                            $ins = $db->prepare("INSERT IGNORE INTO stylist_services (stylist_id,service_id) VALUES (?,?)");
                            foreach ($svcIds as $sid) { if ($sid > 0) $ins->execute([$id, $sid]); }
                        }
                        $msg = 'Stylist updated.';
                    }
                }
            }

        // ── Toggle active (quick action from the list) ──────
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare("SELECT is_owner, is_active FROM stylists WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch();
            if ($row && (int)$row['is_owner'] === 1) {
                $error = 'The owner account cannot be deactivated.';
            } elseif ($row) {
                $new = (int)$row['is_active'] === 1 ? 0 : 1;
                $db->prepare("UPDATE stylists SET is_active=? WHERE id=?")->execute([$new, $id]);
                $msg = $new ? 'Stylist reactivated.' : 'Stylist deactivated — hidden from new assignments.';
            }
        }

    } catch (Exception $e) {
        error_log('Stylists admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Team & Stylists';
require_once __DIR__ . '/includes/layout.php';

// ── Load data ─────────────────────────────────────────────
try {
    $stylists = $db->query("SELECT * FROM stylists ORDER BY is_owner DESC, is_active DESC, name ASC")->fetchAll();

    // Active services for the skills allow-list.
    $services = $db->query("SELECT id, name, category, service_type FROM services WHERE is_active=1 ORDER BY category, name")->fetchAll();

    // Which services each stylist can do (for pre-checking boxes).
    $skillsByStylist = [];
    foreach ($db->query("SELECT stylist_id, service_id FROM stylist_services")->fetchAll() as $r) {
        $skillsByStylist[$r['stylist_id']][$r['service_id']] = true;
    }

    // Lifetime earned per stylist (context; payouts come later).
    $earnedByStylist = [];
    foreach ($db->query("SELECT stylist_id,
                                COALESCE(SUM(CASE WHEN earnings_status='earned' THEN earnings_amount END),0) AS earned,
                                COUNT(*) AS jobs
                         FROM booking_assignments GROUP BY stylist_id")->fetchAll() as $r) {
        $earnedByStylist[$r['stylist_id']] = $r;
    }
} catch (Exception $e) {
    $stylists = []; $services = []; $skillsByStylist = []; $earnedByStylist = [];
    $error = $error ?: 'Could not load stylists. Run the database migration first.';
}

$activeCount = count(array_filter($stylists, fn($s) => (int)$s['is_active'] === 1));

/** Small helper: render the create/edit form fields (shared markup). */
function typeLabel(string $t): string {
    return ['braider' => 'Braider', 'barber' => 'Barber', 'both' => 'Braider & Barber'][$t] ?? ucfirst($t);
}
?>

<?php if ($msg):   ?><div class="alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Team &amp; Stylists</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">
      <?= $activeCount ?> active · click a stylist to edit rates, skills and portal access
    </p>
  </div>
  <button class="btn-admin btn-admin-primary" onclick="toggleCreateForm()">+ New Stylist</button>
</div>

<!-- ── Create new stylist ─────────────────────────────────── -->
<div id="create-form-wrap" style="display:none;margin-bottom:24px">
  <div class="admin-card">
    <div class="admin-card-header">
      <span class="admin-card-title">✦ Add a Stylist</span>
      <button class="btn-admin btn-admin-outline btn-admin-sm" onclick="toggleCreateForm()">✕ Cancel</button>
    </div>
    <div class="admin-card-body">
      <form method="POST" action="/admin/stylists">
        <input type="hidden" name="action" value="create_stylist">
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Name *</label>
            <input class="admin-input" type="text" name="name" placeholder="e.g. Amara" required>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Email *</label>
            <input class="admin-input" type="email" name="email" placeholder="stylist@example.com" required>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Phone</label>
            <input class="admin-input" type="text" name="phone" placeholder="Optional">
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Type</label>
            <select class="admin-input" name="stylist_type">
              <option value="braider">Braider</option>
              <option value="barber">Barber</option>
              <option value="both">Braider &amp; Barber</option>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Default commission %</label>
            <input class="admin-input" type="number" name="default_commission_pct" min="0" max="100" step="0.01" value="50" placeholder="e.g. 50">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Default hourly rate (£)</label>
            <input class="admin-input" type="number" name="default_hourly_rate" min="0" step="0.01" value="0" placeholder="e.g. 15">
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Bio (optional)</label>
            <textarea class="admin-input admin-textarea" name="bio" rows="2" placeholder="Short note shown internally..."></textarea>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer">
          <input type="checkbox" name="portal_enabled" checked>
          <span>Give portal access — they can sign in at <code>/login</code> with an emailed code</span>
        </label>
        <button type="submit" class="btn-admin btn-admin-primary">Add Stylist</button>
      </form>
    </div>
  </div>
</div>

<!-- ── Stylist list ───────────────────────────────────────── -->
<?php if (empty($stylists)): ?>
  <div class="table-empty">
    <div class="table-empty-icon">💇</div>
    <p>No stylists yet. Add your first team member above.</p>
  </div>
<?php else: foreach ($stylists as $s):
    $id      = (int)$s['id'];
    $isOwner = (int)$s['is_owner'] === 1;
    $active  = (int)$s['is_active'] === 1;
    $stats   = $earnedByStylist[$id] ?? ['earned' => 0, 'jobs' => 0];
?>
  <div class="admin-card" style="margin-bottom:12px;<?= $active ? '' : 'opacity:.65' ?>">
    <div class="admin-card-header" style="cursor:pointer" onclick="toggleStylist(<?= $id ?>)">
      <span class="admin-card-title">
        <span id="stl-arrow-<?= $id ?>">▸</span>
        <?= htmlspecialchars($s['name']) ?>
        <?php if ($isOwner): ?><span class="admin-badge" style="background:var(--admin-gold,#D4AF37);color:#000">Owner</span><?php endif; ?>
        <?php if (!$active): ?><span class="admin-badge" style="background:#6b7280">Inactive</span><?php endif; ?>
      </span>
      <span style="font-size:0.75rem;color:var(--admin-muted)">
        <?= htmlspecialchars(typeLabel($s['stylist_type'])) ?> ·
        <?= rtrim(rtrim(number_format((float)$s['default_commission_pct'], 2), '0'), '.') ?>% comm
        <?php if ((float)$s['default_hourly_rate'] > 0): ?> · £<?= number_format((float)$s['default_hourly_rate'], 2) ?>/hr<?php endif; ?>
      </span>
    </div>

    <div id="stl-body-<?= $id ?>" class="admin-card-body" style="display:none">
      <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:14px">
        <?= (int)$stats['jobs'] ?> assignment<?= (int)$stats['jobs'] === 1 ? '' : 's' ?> ·
        £<?= number_format((float)$stats['earned'], 2) ?> earned to date
        <?php if ($isOwner): ?> · uses the admin panel, not the stylist portal<?php endif; ?>
      </p>

      <form method="POST" action="/admin/stylists">
        <input type="hidden" name="action" value="update_stylist">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Name *</label>
            <input class="admin-input" type="text" name="name" value="<?= htmlspecialchars($s['name']) ?>" required>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Email *</label>
            <input class="admin-input" type="email" name="email" value="<?= htmlspecialchars($s['email']) ?>" required>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Phone</label>
            <input class="admin-input" type="text" name="phone" value="<?= htmlspecialchars($s['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group">
            <label class="admin-label">Type</label>
            <select class="admin-input" name="stylist_type">
              <?php foreach (['braider','barber','both'] as $t): ?>
                <option value="<?= $t ?>" <?= $s['stylist_type'] === $t ? 'selected' : '' ?>><?= typeLabel($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Default commission %</label>
            <input class="admin-input" type="number" name="default_commission_pct" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string)$s['default_commission_pct']) ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Default hourly rate (£)</label>
            <input class="admin-input" type="number" name="default_hourly_rate" min="0" step="0.01" value="<?= htmlspecialchars((string)$s['default_hourly_rate']) ?>">
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:14px">
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Bio</label>
            <textarea class="admin-input admin-textarea" name="bio" rows="2"><?= htmlspecialchars($s['bio'] ?? '') ?></textarea>
          </div>
        </div>

        <?php if (!empty($services)): ?>
        <div style="margin-bottom:14px">
          <label class="admin-label">Skills — services this stylist can be assigned to</label>
          <p style="font-size:0.7rem;color:var(--admin-muted);margin:2px 0 8px">Advisory only — the assignment screen warns but never blocks. Leave all unticked to allow any service.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px 16px">
            <?php foreach ($services as $svc):
                $checked = isset($skillsByStylist[$id][$svc['id']]); ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:0.82rem;cursor:pointer">
                <input type="checkbox" name="services[]" value="<?= (int)$svc['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($svc['name']) ?><?php if ($svc['category']): ?> <span style="color:var(--admin-muted)">· <?= htmlspecialchars($svc['category']) ?></span><?php endif; ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!$isOwner): ?>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;cursor:pointer">
          <input type="checkbox" name="portal_enabled" <?= (int)$s['portal_enabled'] === 1 ? 'checked' : '' ?>>
          <span>Portal access (sign in at <code>/login</code>)</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer">
          <input type="checkbox" name="is_active" <?= $active ? 'checked' : '' ?>>
          <span>Active — available for new assignments</span>
        </label>
        <?php endif; ?>

        <div style="display:flex;gap:10px;align-items:center">
          <button type="submit" class="btn-admin btn-admin-primary">Save Changes</button>
          <?php if (!$isOwner): ?>
            <button type="submit" form="toggle-<?= $id ?>" class="btn-admin btn-admin-outline">
              <?= $active ? 'Deactivate' : 'Reactivate' ?>
            </button>
          <?php endif; ?>
        </div>
      </form>
      <?php if (!$isOwner): ?>
      <form id="toggle-<?= $id ?>" method="POST" action="/admin/stylists" style="display:none">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="id" value="<?= $id ?>">
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
function toggleCreateForm() {
  const el = document.getElementById('create-form-wrap');
  const visible = el.style.display !== 'none';
  el.style.display = visible ? 'none' : 'block';
  if (!visible) el.querySelector('input[name="name"]').focus();
}
function toggleStylist(id) {
  const body = document.getElementById('stl-body-' + id);
  const arrow = document.getElementById('stl-arrow-' + id);
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  arrow.textContent = open ? '▸' : '▾';
}
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
