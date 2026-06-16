<?php
// ============================================================
// BraidedbyAGB — Admin Discount Codes
// FILE: /admin/discounts.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Discount Codes';

$success = ''; $error = '';

// ── Handle form submissions ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'create') {
        $code      = strtoupper(trim(sanitize($_POST['code'] ?? '')));
        $type      = in_array($_POST['type'] ?? '', ['percent','fixed']) ? $_POST['type'] : 'percent';
        $value     = (float)($_POST['value'] ?? 0);
        $limit     = !empty($_POST['uses_limit']) ? (int)$_POST['uses_limit'] : null;
        $expiry    = !empty($_POST['expiry_date']) ? sanitize($_POST['expiry_date']) : null;
        $customer  = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;

        if (!$code || $value <= 0) {
            $error = 'Code and value are required.';
        } elseif ($type === 'percent' && $value > 100) {
            $error = 'Percentage discount cannot exceed 100%.';
        } else {
            // Check uniqueness
            $exists = $db->prepare("SELECT id FROM discount_codes WHERE code = ?");
            $exists->execute([$code]);
            if ($exists->fetch()) {
                $error = 'A code with this name already exists.';
            } else {
                $db->prepare("
                    INSERT INTO discount_codes (code, type, value, uses_limit, expiry_date, customer_id, is_active)
                    VALUES (?,?,?,?,?,?,1)
                ")->execute([$code, $type, $value, $limit, $expiry, $customer]);
                $success = "Discount code <strong>$code</strong> created successfully.";
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE discount_codes SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        $success = 'Code updated.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM discount_codes WHERE id = ?")->execute([$id]);
        $success = 'Code deleted.';
    }
}


$pageTitle = $pageTitle ?? 'Discounts';
require_once __DIR__ . '/includes/layout.php';

// ── Fetch codes ───────────────────────────────────────────
$codes = $db->query("
    SELECT dc.*, c.name as customer_name
    FROM discount_codes dc
    LEFT JOIN customers c ON c.id = dc.customer_id
    ORDER BY dc.created_at DESC
")->fetchAll();

// For customer dropdown in create form
$customers = $db->query("SELECT id, name, email FROM customers ORDER BY name ASC")->fetchAll();
?>

<div class="page-header">
  <h2 class="section-heading">Discount Codes</h2>
</div>

<?php if ($success): ?><div class="alert-success"><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Create new code -->
<div class="admin-card" style="margin-bottom:24px">
  <div class="admin-card-header">
    <span class="admin-card-title">Create New Discount Code</span>
  </div>
  <div class="admin-card-body">
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;align-items:end">
      <input type="hidden" name="action" value="create">
      <div>
        <label class="admin-label">Code *</label>
        <input type="text" name="code" class="admin-input" placeholder="e.g. SUMMER20" required
               style="text-transform:uppercase" maxlength="40">
      </div>
      <div>
        <label class="admin-label">Type *</label>
        <select name="type" class="admin-input">
          <option value="percent">Percentage (%)</option>
          <option value="fixed">Fixed Amount (£)</option>
        </select>
      </div>
      <div>
        <label class="admin-label">Value *</label>
        <input type="number" name="value" class="admin-input" placeholder="e.g. 10" min="1" step="0.01" required>
      </div>
      <div>
        <label class="admin-label">Usage Limit</label>
        <input type="number" name="uses_limit" class="admin-input" placeholder="Unlimited" min="1">
      </div>
      <div>
        <label class="admin-label">Expiry Date</label>
        <input type="date" name="expiry_date" class="admin-input"
               min="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="admin-label">Specific Customer (optional)</label>
        <select name="customer_id" class="admin-input">
          <option value="">Any customer</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn-admin btn-admin-primary w-full">Create Code</button>
      </div>
    </form>
  </div>
</div>

<!-- Existing codes -->
<?php if (empty($codes)): ?>
  <div class="table-empty">
    <div class="table-empty-icon">🏷️</div>
    <p>No discount codes yet.</p>
  </div>
<?php else: ?>
<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Discount</th>
        <th>Used / Limit</th>
        <th>Expiry</th>
        <th>Customer</th>
        <th>Status</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($codes as $dc):
      $isExpired = $dc['expiry_date'] && $dc['expiry_date'] < date('Y-m-d');
      $isExhausted = $dc['uses_limit'] && $dc['times_used'] >= $dc['uses_limit'];
    ?>
    <tr>
      <td>
        <code style="font-size:0.9rem;font-weight:700;letter-spacing:0.05em;color:var(--admin-primary)">
          <?= htmlspecialchars($dc['code']) ?>
        </code>
      </td>
      <td>
        <?php if ($dc['type'] === 'percent'): ?>
          <span style="font-weight:700"><?= (float)$dc['value'] ?>% off</span>
        <?php else: ?>
          <span style="font-weight:700">£<?= number_format((float)$dc['value'], 2) ?> off</span>
        <?php endif; ?>
      </td>
      <td>
        <?= (int)$dc['times_used'] ?> /
        <?= $dc['uses_limit'] ? $dc['uses_limit'] : '∞' ?>
      </td>
      <td>
        <?php if ($dc['expiry_date']): ?>
          <span style="<?= $isExpired ? 'color:var(--admin-danger)' : '' ?>">
            <?= date('j M Y', strtotime($dc['expiry_date'])) ?>
            <?= $isExpired ? '(expired)' : '' ?>
          </span>
        <?php else: ?>
          <span style="color:var(--admin-muted)">Never</span>
        <?php endif; ?>
      </td>
      <td><?= $dc['customer_name'] ? htmlspecialchars($dc['customer_name']) : '<span style="color:var(--admin-muted)">Any</span>' ?></td>
      <td>
        <?php if ($isExpired || $isExhausted): ?>
          <span class="status-badge status-cancelled">Exhausted</span>
        <?php elseif ($dc['is_active']): ?>
          <span class="status-badge status-confirmed">Active</span>
        <?php else: ?>
          <span class="status-badge status-cancelled">Inactive</span>
        <?php endif; ?>
      </td>
      <td style="font-size:0.75rem;color:var(--admin-muted)"><?= date('j M Y', strtotime($dc['created_at'])) ?></td>
      <td style="display:flex;gap:6px;flex-wrap:nowrap">
        <form method="POST" style="display:inline">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $dc['id'] ?>">
          <button class="btn-admin btn-admin-outline btn-admin-sm">
            <?= $dc['is_active'] ? 'Deactivate' : 'Activate' ?>
          </button>
        </form>
        <form method="POST" onsubmit="return confirm('Delete this code?')" style="display:inline">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $dc['id'] ?>">
          <button class="btn-admin btn-admin-danger btn-admin-sm">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
