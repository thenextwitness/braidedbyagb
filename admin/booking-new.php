<?php
// ============================================================
// BraidedbyAGB — Admin New Booking
// FILE: /admin/booking-new.php (routed via .htaccess)
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'New Booking';

// ── Load services & variants for the form ─────────────────
$services = $db->query("SELECT id, name, price_from, duration_mins FROM services WHERE is_active=1 ORDER BY display_order ASC")->fetchAll();
$variants = $db->query("SELECT id, service_id, variant_name, price FROM service_variants ORDER BY display_order ASC")->fetchAll();
$addons   = $db->query("SELECT id, service_id, name, price FROM service_addons WHERE is_active=1 ORDER BY id ASC")->fetchAll();

// ── Group variants and addons by service_id ────────────────
$variantsByService = [];
foreach ($variants as $v) { $variantsByService[$v['service_id']][] = $v; }
$addonsByService = [];
foreach ($addons as $a) { $addonsByService[$a['service_id']][] = $a; }

$errors = [];
$success = '';

// ── Handle POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -- Collect & sanitize inputs --
    $customerMode  = sanitize($_POST['customer_mode'] ?? 'existing'); // 'existing' or 'new'
    $customerId    = (int)($_POST['customer_id'] ?? 0);
    $newName       = sanitize($_POST['new_name'] ?? '');
    $newEmail      = sanitizeEmail($_POST['new_email'] ?? '');
    $newPhone      = sanitize($_POST['new_phone'] ?? '');
    $serviceId     = (int)($_POST['service_id'] ?? 0);
    $variantId     = (int)($_POST['variant_id'] ?? 0) ?: null;
    $bookedDate    = sanitize($_POST['booked_date'] ?? '');
    $bookedTime    = sanitize($_POST['booked_time'] ?? '');
    $paymentMethod        = sanitize($_POST['payment_method'] ?? 'bank_transfer');
    $paymentMethodAllowed = sanitize($_POST['payment_method_allowed'] ?? 'both');
    $depositPaid          = isset($_POST['deposit_paid']) ? 1 : 0;
    $clientNotes   = sanitize($_POST['client_notes'] ?? '');
    $adminNotes    = sanitize($_POST['admin_notes'] ?? '');
    $status        = sanitize($_POST['status'] ?? 'pending');
    $selectedAddons = array_map('intval', $_POST['addons'] ?? []);
    $manualPrice   = trim($_POST['manual_price'] ?? '');

    // -- Validate --
    if (!$serviceId) $errors[] = 'Please select a service.';
    if (!$bookedDate) $errors[] = 'Please select a date.';
    if (!$bookedTime) $errors[] = 'Please select a time.';
    if (!in_array($paymentMethod, ['stripe','bank_transfer','cash'])) $errors[] = 'Invalid payment method.';
    if (!in_array($paymentMethodAllowed, ['stripe','bank_transfer','both'])) $paymentMethodAllowed = 'both';
    if (!in_array($status, ['pending','confirmed','completed','cancelled'])) $errors[] = 'Invalid status.';

    // -- Resolve customer --
    if ($customerMode === 'new') {
        if (!$newName) $errors[] = 'Client name is required.';
        if (!$newEmail || !validateEmail($newEmail)) $errors[] = 'A valid client email is required.';

        if (empty($errors)) {
            // Check if customer already exists by email
            $existing = $db->prepare("SELECT id FROM customers WHERE email = ?");
            $existing->execute([$newEmail]);
            $existingRow = $existing->fetch();
            if ($existingRow) {
                $customerId = (int)$existingRow['id'];
                // Update phone if provided
                if ($newPhone) {
                    $db->prepare("UPDATE customers SET phone=? WHERE id=?")->execute([$newPhone, $customerId]);
                }
            } else {
                $db->prepare("INSERT INTO customers (name, email, phone) VALUES (?,?,?)")
                   ->execute([$newName, $newEmail, $newPhone ?: null]);
                $customerId = (int)$db->lastInsertId();
            }
        }
    } else {
        if (!$customerId) $errors[] = 'Please select an existing client or create a new one.';
    }

    // -- Calculate price --
    if (empty($errors)) {
        if ($manualPrice !== '' && is_numeric($manualPrice)) {
            $totalPrice = (float)$manualPrice;
        } elseif ($variantId) {
            $vRow = $db->prepare("SELECT price FROM service_variants WHERE id=?");
            $vRow->execute([$variantId]);
            $totalPrice = (float)($vRow->fetchColumn() ?: 0);
        } else {
            $sRow = $db->prepare("SELECT price_from FROM services WHERE id=?");
            $sRow->execute([$serviceId]);
            $totalPrice = (float)($sRow->fetchColumn() ?: 0);
        }

        // Add addon prices
        $addonTotal = 0;
        if (!empty($selectedAddons)) {
            $placeholders = implode(',', array_fill(0, count($selectedAddons), '?'));
            $addonRows = $db->prepare("SELECT id, price FROM service_addons WHERE id IN ($placeholders)");
            $addonRows->execute($selectedAddons);
            $addonRows = $addonRows->fetchAll();
            foreach ($addonRows as $ar) { $addonTotal += (float)$ar['price']; }
        }
        $totalPrice += $addonTotal;

        $depositAmount    = calculateDeposit($totalPrice);
        $remainingBalance = calculateRemainingBalance($totalPrice, $depositAmount);
        $bookingRef       = generateBookingRef();

        // Generate a unique payment token (only if deposit not already paid)
        $paymentToken = null;
        if (!$depositPaid) {
            $paymentToken = bin2hex(random_bytes(32)); // 64-char hex
        }

        // -- Insert booking --
        $db->prepare("
            INSERT INTO bookings
                (booking_ref, customer_id, service_id, variant_id, booked_date, booked_time,
                 status, payment_method, deposit_amount, deposit_paid, total_price,
                 remaining_balance, client_notes, admin_notes, policy_accepted,
                 payment_token, payment_method_allowed)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)
        ")->execute([
            $bookingRef, $customerId, $serviceId, $variantId,
            $bookedDate, $bookedTime, $status, $paymentMethod,
            $depositAmount, $depositPaid, $totalPrice,
            $remainingBalance, $clientNotes ?: null, $adminNotes ?: null,
            $paymentToken, $paymentMethodAllowed
        ]);
        $bookingId = (int)$db->lastInsertId();

        // -- Insert booking addons --
        if (!empty($addonRows)) {
            $addonStmt = $db->prepare("INSERT INTO booking_addons (booking_id, addon_id, price_charged) VALUES (?,?,?)");
            foreach ($addonRows as $ar) {
                $addonStmt->execute([$bookingId, $ar['id'], $ar['price']]);
            }
        }

        // -- Insert payment record if deposit marked paid --
        if ($depositPaid) {
            $db->prepare("
                INSERT INTO payments (booking_id, amount, currency, type, method, status, confirmed_by, confirmed_at)
                VALUES (?,?,'GBP','deposit',?,  'succeeded','admin',NOW())
            ")->execute([$bookingId, $depositAmount, $paymentMethod]);
        }

        // -- Send confirmation email if status confirmed --
        if ($status === 'confirmed') {
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $freshBk  = $db->query("SELECT b.*, s.name as s_name FROM bookings b JOIN services s ON s.id=b.service_id WHERE b.id=$bookingId")->fetch();
                $customer = $db->query("SELECT name, email FROM customers WHERE id=$customerId")->fetch();
                emailBookingApproved($freshBk, $customer, ['name' => $freshBk['s_name']]);
            } catch (Throwable $e) {
                error_log('New booking confirmation email error: ' . $e->getMessage());
            }
        }

        $redirectMsg = "Booking $bookingRef created successfully.";
        $redirectQs  = 'msg=' . urlencode($redirectMsg);
        if ($paymentToken) {
            $redirectQs .= '&show_payment_link=1';
        }
        header("Location: /admin/bookings/$bookingId?$redirectQs");
        exit;
    }
}

// ── Load existing customers for search ─────────────────────
$customers = $db->query("SELECT id, name, email, phone FROM customers ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/includes/layout.php';
?>

<style>
.new-booking-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
.new-booking-full { grid-column: 1 / -1; }
@media (max-width: 768px) {
  .new-booking-grid { grid-template-columns: 1fr; }
  .new-booking-full { grid-column: 1; }
}
.section-title {
  font-family: 'Montserrat', sans-serif;
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--admin-muted);
  margin-bottom: 14px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--admin-border);
}
.customer-mode-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 16px;
  border: 1px solid var(--admin-border);
  border-radius: var(--admin-radius);
  overflow: hidden;
}
.customer-mode-tab {
  flex: 1;
  padding: 8px 14px;
  font-size: 0.8rem;
  font-weight: 700;
  font-family: 'Montserrat', sans-serif;
  text-align: center;
  cursor: pointer;
  background: var(--admin-bg);
  color: var(--admin-muted);
  border: none;
  transition: var(--transition);
}
.customer-mode-tab.active {
  background: var(--admin-primary);
  color: #fff;
}
.price-preview {
  background: linear-gradient(135deg, #faf8fd 0%, #f3eeff 100%);
  border: 1px solid #d4b8f0;
  border-radius: var(--admin-radius);
  padding: 16px 18px;
  margin-top: 4px;
}
.price-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.82rem;
  padding: 4px 0;
  color: var(--admin-text);
}
.price-row.total {
  font-family: 'Montserrat', sans-serif;
  font-size: 1rem;
  font-weight: 800;
  color: var(--admin-primary);
  padding-top: 10px;
  margin-top: 6px;
  border-top: 1px solid #d4b8f0;
}
.price-row .label { color: var(--admin-muted); font-size: 0.78rem; }
.addons-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 8px;
}
.addon-check {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--admin-bg);
  border: 1px solid var(--admin-border);
  border-radius: var(--admin-radius);
  cursor: pointer;
  font-size: 0.8rem;
  transition: var(--transition);
}
.addon-check:hover { border-color: var(--admin-primary); }
.addon-check input[type=checkbox]:checked + .addon-label { color: var(--admin-primary); font-weight: 700; }
.time-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
  margin-top: 6px;
}
.time-btn {
  padding: 8px 4px;
  font-size: 0.75rem;
  font-weight: 600;
  text-align: center;
  background: var(--admin-bg);
  border: 1px solid var(--admin-border);
  border-radius: var(--admin-radius);
  cursor: pointer;
  transition: var(--transition);
  color: var(--admin-text);
}
.time-btn:hover { border-color: var(--admin-primary); color: var(--admin-primary); }
.time-btn.selected { background: var(--admin-primary); color: #fff; border-color: var(--admin-primary); }
</style>

<!-- Error display -->
<?php if (!empty($errors)): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;padding:12px 16px;border-radius:var(--admin-radius);margin-bottom:20px">
  <strong style="font-size:0.82rem;color:#991b1b">Please fix the following:</strong>
  <ul style="margin:6px 0 0 16px;font-size:0.8rem;color:#7f1d1d">
    <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
  <a href="/admin/bookings" class="btn-admin btn-admin-outline">← All Bookings</a>
  <h2 style="font-family:'Montserrat',sans-serif;font-size:1rem;font-weight:800;color:var(--admin-primary-dark);flex:1">
    Create New Booking
  </h2>
</div>

<form method="POST" id="newBookingForm">

  <div class="new-booking-grid">

    <!-- ── CLIENT ─────────────────────────────────────── -->
    <div class="detail-card new-booking-full">
      <div class="section-title">👤 Client</div>

      <div class="customer-mode-tabs">
        <button type="button" class="customer-mode-tab active" id="tabExisting" onclick="switchMode('existing')">
          Existing Client
        </button>
        <button type="button" class="customer-mode-tab" id="tabNew" onclick="switchMode('new')">
          + New Client
        </button>
      </div>
      <input type="hidden" name="customer_mode" id="customer_mode" value="existing">

      <!-- Existing client search -->
      <div id="paneExisting">
        <div class="admin-form-group">
          <label class="admin-label">Search client</label>
          <input type="text" id="customerSearch" class="admin-input admin-search"
                 placeholder="Type name or email…" autocomplete="off" style="max-width:340px">
          <div id="customerDropdown" style="display:none;position:absolute;z-index:200;background:#fff;border:1px solid var(--admin-border);border-radius:var(--admin-radius);box-shadow:var(--admin-shadow-md);max-height:220px;overflow-y:auto;width:340px"></div>
        </div>
        <input type="hidden" name="customer_id" id="customer_id" value="">
        <div id="selectedCustomer" style="display:none;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius);padding:10px 14px;font-size:0.82rem;margin-top:4px">
          <span id="selectedCustomerName" style="font-weight:700"></span>
          <span id="selectedCustomerEmail" style="color:var(--admin-muted);margin-left:8px"></span>
          <button type="button" onclick="clearCustomer()" style="float:right;background:none;border:none;color:var(--admin-muted);font-size:0.75rem;cursor:pointer">✕ Clear</button>
        </div>
      </div>

      <!-- New client fields -->
      <div id="paneNew" style="display:none">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
          <div class="admin-form-group">
            <label class="admin-label">Full Name *</label>
            <input type="text" name="new_name" class="admin-input"
                   value="<?= htmlspecialchars($_POST['new_name'] ?? '') ?>" placeholder="e.g. Amara Okafor">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Email *</label>
            <input type="email" name="new_email" class="admin-input"
                   value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>" placeholder="amara@email.com">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Phone</label>
            <input type="tel" name="new_phone" class="admin-input"
                   value="<?= htmlspecialchars($_POST['new_phone'] ?? '') ?>" placeholder="07700 000000">
          </div>
        </div>
      </div>
    </div>

    <!-- ── SERVICE ────────────────────────────────────── -->
    <div class="detail-card">
      <div class="section-title">✂️ Service</div>

      <div class="admin-form-group">
        <label class="admin-label">Service *</label>
        <select name="service_id" id="serviceSelect" class="admin-input admin-select" onchange="updateServiceOptions()">
          <option value="">— Select a service —</option>
          <?php foreach ($services as $svc): ?>
            <option value="<?= $svc['id'] ?>"
                    data-price="<?= $svc['price_from'] ?>"
                    data-variants='<?= htmlspecialchars(json_encode($variantsByService[$svc['id']] ?? []), ENT_QUOTES) ?>'
                    data-addons='<?= htmlspecialchars(json_encode($addonsByService[$svc['id']] ?? []), ENT_QUOTES) ?>'
                    <?= (int)($_POST['service_id'] ?? 0) === $svc['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($svc['name']) ?> — from <?= formatPrice($svc['price_from']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-form-group" id="variantGroup" style="display:none">
        <label class="admin-label">Variant / Length</label>
        <select name="variant_id" id="variantSelect" class="admin-input admin-select" onchange="updatePrice()">
          <option value="">— Select variant —</option>
        </select>
      </div>

      <div id="addonsGroup" style="display:none">
        <label class="admin-label" style="display:block;margin-bottom:6px">Add-ons</label>
        <div class="addons-grid" id="addonsContainer"></div>
      </div>
    </div>

    <!-- ── DATE & TIME ────────────────────────────────── -->
    <div class="detail-card">
      <div class="section-title">📅 Date & Time</div>

      <div class="admin-form-group">
        <label class="admin-label">Date *</label>
        <input type="date" name="booked_date" id="bookedDate" class="admin-input"
               value="<?= htmlspecialchars($_POST['booked_date'] ?? '') ?>"
               min="<?= date('Y-m-d') ?>" style="max-width:200px">
      </div>

      <div class="admin-form-group">
        <label class="admin-label">Time *</label>
        <div class="time-grid" id="timeGrid">
          <?php
          $times = ['08:00','08:30','09:00','09:30','10:00','10:30','11:00','11:30',
                    '12:00','12:30','13:00','13:30','14:00','14:30','15:00','15:30',
                    '16:00','16:30','17:00','17:30','18:00','18:30','19:00','19:30','20:00'];
          $selectedTime = $_POST['booked_time'] ?? '';
          foreach ($times as $t):
            $label = date('g:i A', strtotime($t));
          ?>
            <button type="button" class="time-btn <?= $selectedTime === $t ? 'selected' : '' ?>"
                    onclick="selectTime('<?= $t ?>')" data-time="<?= $t ?>">
              <?= $label ?>
            </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="booked_time" id="booked_time" value="<?= htmlspecialchars($selectedTime) ?>">
        <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:6px">
          Or enter manually: <input type="time" id="manualTime" class="admin-input" style="width:120px;display:inline-block;padding:4px 8px;font-size:0.8rem"
          value="<?= htmlspecialchars($selectedTime) ?>" oninput="selectTime(this.value, true)">
        </p>
      </div>
    </div>

    <!-- ── PAYMENT ────────────────────────────────────── -->
    <div class="detail-card">
      <div class="section-title">💳 Payment</div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="admin-form-group">
          <label class="admin-label">Payment Method</label>
          <select name="payment_method" class="admin-input admin-select">
            <option value="bank_transfer" <?= ($_POST['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
            <option value="stripe" <?= ($_POST['payment_method'] ?? '') === 'stripe' ? 'selected' : '' ?>>Card (Stripe)</option>
            <option value="cash">Cash</option>
          </select>
        </div>

        <div class="admin-form-group">
          <label class="admin-label">Booking Status</label>
          <select name="status" class="admin-input admin-select">
            <?php foreach (['pending','confirmed','completed','cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'pending') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Payment link options -->
      <div class="admin-form-group" style="margin-top:10px;padding:12px 14px;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius)">
        <label class="admin-label" style="margin-bottom:6px;display:flex;align-items:center;gap:6px">
          🔗 Payment Link Options
          <span style="font-size:0.7rem;color:var(--admin-muted);font-weight:400">— A direct payment link will be generated for you to send to the client</span>
        </label>
        <select name="payment_method_allowed" class="admin-input admin-select" style="max-width:280px">
          <option value="both" <?= ($_POST['payment_method_allowed'] ?? 'both') === 'both' ? 'selected' : '' ?>>Both — Card &amp; Bank Transfer</option>
          <option value="stripe" <?= ($_POST['payment_method_allowed'] ?? '') === 'stripe' ? 'selected' : '' ?>>Card only (Stripe)</option>
          <option value="bank_transfer" <?= ($_POST['payment_method_allowed'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer only</option>
        </select>
        <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:4px">The link will be shown after saving — copy it and send to your client via WhatsApp or email.</p>
      </div>

      <div class="admin-form-group" style="display:flex;align-items:center;gap:10px;margin-top:4px">
        <input type="checkbox" name="deposit_paid" id="depositPaid" value="1"
               <?= isset($_POST['deposit_paid']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--admin-primary)">
        <label for="depositPaid" class="admin-label" style="margin:0;cursor:pointer">Mark deposit as already paid</label>
      </div>

      <div class="admin-form-group" style="margin-top:12px">
        <label class="admin-label">Override Price (optional)</label>
        <div style="position:relative;max-width:160px">
          <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--admin-muted)">£</span>
          <input type="number" name="manual_price" id="manualPrice" class="admin-input"
                 style="padding-left:24px" min="0" step="0.01"
                 value="<?= htmlspecialchars($_POST['manual_price'] ?? '') ?>"
                 placeholder="Auto" oninput="updatePrice()">
        </div>
        <p style="font-size:0.72rem;color:var(--admin-muted);margin-top:4px">Leave blank to use service price</p>
      </div>

      <!-- Price preview -->
      <div class="price-preview" id="pricePreview" style="display:none">
        <div class="price-row"><span class="label">Service price</span><span id="previewService">—</span></div>
        <div class="price-row" id="previewAddonsRow" style="display:none"><span class="label">Add-ons</span><span id="previewAddons">—</span></div>
        <div class="price-row total"><span>Total</span><span id="previewTotal">—</span></div>
        <div class="price-row"><span class="label">Deposit (<?= getSetting('deposit_percent','30') ?>%)</span><span id="previewDeposit">—</span></div>
        <div class="price-row"><span class="label">Balance on day</span><span id="previewBalance">—</span></div>
      </div>
    </div>

    <!-- ── NOTES ──────────────────────────────────────── -->
    <div class="detail-card new-booking-full">
      <div class="section-title">📝 Notes</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="admin-form-group">
          <label class="admin-label">Client Notes</label>
          <textarea name="client_notes" class="admin-input admin-textarea" rows="3"
                    placeholder="Notes from the client about their style, hair type, preferences…"><?= htmlspecialchars($_POST['client_notes'] ?? '') ?></textarea>
        </div>
        <div class="admin-form-group">
          <label class="admin-label">Admin Notes (internal only)</label>
          <textarea name="admin_notes" class="admin-input admin-textarea" rows="3"
                    placeholder="Internal notes — not visible to client…"><?= htmlspecialchars($_POST['admin_notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

  </div><!-- /grid -->

  <!-- Submit -->
  <div style="display:flex;gap:12px;align-items:center;margin-top:24px;padding-top:20px;border-top:1px solid var(--admin-border)">
    <button type="submit" class="btn-admin btn-admin-primary" style="padding:12px 32px;font-size:0.9rem">
      ✓ Create Booking
    </button>
    <a href="/admin/bookings" class="btn-admin btn-admin-outline">Cancel</a>
  </div>

</form>

<script>
// ── Customer mode toggle ───────────────────────────────────
function switchMode(mode) {
  document.getElementById('customer_mode').value = mode;
  document.getElementById('paneExisting').style.display = mode === 'existing' ? '' : 'none';
  document.getElementById('paneNew').style.display      = mode === 'new'      ? '' : 'none';
  document.getElementById('tabExisting').classList.toggle('active', mode === 'existing');
  document.getElementById('tabNew').classList.toggle('active', mode === 'new');
}

// ── Customer search ────────────────────────────────────────
const customers = <?= json_encode(array_map(fn($c) => [
  'id'    => $c['id'],
  'name'  => $c['name'],
  'email' => $c['email'],
  'phone' => $c['phone'] ?? '',
], $customers)) ?>;

const searchInput   = document.getElementById('customerSearch');
const dropdown      = document.getElementById('customerDropdown');
const customerIdInput = document.getElementById('customer_id');

searchInput.addEventListener('input', function () {
  const q = this.value.toLowerCase().trim();
  dropdown.innerHTML = '';
  if (!q) { dropdown.style.display = 'none'; return; }
  const matches = customers.filter(c =>
    c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q)
  ).slice(0, 8);
  if (!matches.length) { dropdown.style.display = 'none'; return; }
  matches.forEach(c => {
    const div = document.createElement('div');
    div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:0.82rem;border-bottom:1px solid #f0e8ff';
    div.innerHTML = `<strong>${c.name}</strong> <span style="color:#6b5c78;font-size:0.75rem">${c.email}</span>`;
    div.addEventListener('mousedown', () => selectCustomer(c));
    dropdown.appendChild(div);
  });
  dropdown.style.display = 'block';
});

document.addEventListener('click', (e) => {
  if (!searchInput.contains(e.target)) dropdown.style.display = 'none';
});

function selectCustomer(c) {
  customerIdInput.value = c.id;
  searchInput.value = c.name;
  dropdown.style.display = 'none';
  document.getElementById('selectedCustomer').style.display = '';
  document.getElementById('selectedCustomerName').textContent  = c.name;
  document.getElementById('selectedCustomerEmail').textContent = c.email;
}

function clearCustomer() {
  customerIdInput.value = '';
  searchInput.value = '';
  document.getElementById('selectedCustomer').style.display = 'none';
}

// ── Service / variant / addon update ──────────────────────
function updateServiceOptions() {
  const sel = document.getElementById('serviceSelect');
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) {
    document.getElementById('variantGroup').style.display = 'none';
    document.getElementById('addonsGroup').style.display  = 'none';
    document.getElementById('pricePreview').style.display = 'none';
    return;
  }

  const variants = JSON.parse(opt.dataset.variants || '[]');
  const addons   = JSON.parse(opt.dataset.addons   || '[]');

  // Variants
  const variantSel = document.getElementById('variantSelect');
  variantSel.innerHTML = '<option value="">— Select variant —</option>';
  if (variants.length) {
    variants.forEach(v => {
      const o = document.createElement('option');
      o.value = v.id;
      o.dataset.price = v.price;
      o.textContent = v.variant_name + ' — £' + parseFloat(v.price).toFixed(2);
      variantSel.appendChild(o);
    });
    document.getElementById('variantGroup').style.display = '';
  } else {
    document.getElementById('variantGroup').style.display = 'none';
  }

  // Add-ons
  const container = document.getElementById('addonsContainer');
  container.innerHTML = '';
  if (addons.length) {
    addons.forEach(a => {
      const label = document.createElement('label');
      label.className = 'addon-check';
      label.innerHTML = `
        <input type="checkbox" name="addons[]" value="${a.id}" onchange="updatePrice()">
        <span class="addon-label">${a.name} <span style="color:var(--admin-muted)">+£${parseFloat(a.price).toFixed(2)}</span></span>`;
      container.appendChild(label);
    });
    document.getElementById('addonsGroup').style.display = '';
  } else {
    document.getElementById('addonsGroup').style.display = 'none';
  }

  updatePrice();
}

function updatePrice() {
  const sel       = document.getElementById('serviceSelect');
  const opt       = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) return;

  const manualEl  = document.getElementById('manualPrice');
  const variantSel = document.getElementById('variantSelect');
  const variantOpt = variantSel.options[variantSel.selectedIndex];

  let base = 0;
  if (manualEl.value && !isNaN(parseFloat(manualEl.value))) {
    base = parseFloat(manualEl.value);
  } else if (variantOpt && variantOpt.value && variantOpt.dataset.price) {
    base = parseFloat(variantOpt.dataset.price);
  } else {
    base = parseFloat(opt.dataset.price || 0);
  }

  let addonTotal = 0;
  document.querySelectorAll('input[name="addons[]"]:checked').forEach(cb => {
    const label = cb.closest('label');
    // Extract price from the addon data
    const addonsData = JSON.parse(document.getElementById('serviceSelect').options[document.getElementById('serviceSelect').selectedIndex].dataset.addons || '[]');
    const addon = addonsData.find(a => a.id == cb.value);
    if (addon) addonTotal += parseFloat(addon.price);
  });

  const total   = base + addonTotal;
  const deposit = Math.round(total * <?= (int)getSetting('deposit_percent','30') ?> / 100 * 100) / 100;
  const balance = Math.round((total - deposit) * 100) / 100;

  const fmt = v => '£' + v.toFixed(2);
  document.getElementById('previewService').textContent = fmt(base);
  document.getElementById('previewTotal').textContent   = fmt(total);
  document.getElementById('previewDeposit').textContent = fmt(deposit);
  document.getElementById('previewBalance').textContent = fmt(balance);

  if (addonTotal > 0) {
    document.getElementById('previewAddons').textContent = fmt(addonTotal);
    document.getElementById('previewAddonsRow').style.display = '';
  } else {
    document.getElementById('previewAddonsRow').style.display = 'none';
  }

  document.getElementById('pricePreview').style.display = total > 0 ? '' : 'none';
}

// ── Time selection ─────────────────────────────────────────
function selectTime(t, fromManual) {
  document.getElementById('booked_time').value = t;
  document.querySelectorAll('.time-btn').forEach(btn => {
    btn.classList.toggle('selected', btn.dataset.time === t);
  });
  if (!fromManual) document.getElementById('manualTime').value = t;
}

// Init if returning with POST errors
(function(){
  const mode = document.getElementById('customer_mode').value;
  if (mode === 'new') switchMode('new');
  const svcSel = document.getElementById('serviceSelect');
  if (svcSel.value) updateServiceOptions();
})();
</script>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
