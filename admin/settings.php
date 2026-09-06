<?php
// ============================================================
// BraidedbyAGB — Admin Settings
// FILE: /admin/settings.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$pageTitle = 'Settings';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $fields = [
            'site_name', 'site_email', 'site_phone', 'site_address',
            'deposit_percent', 'booking_buffer_hours',
            'bank_account_name', 'bank_sort_code', 'bank_account_number',
            'bank_transfer_hold_hours', 'instagram_url', 'tiktok_url', 'facebook_url',
            'smtp_host', 'smtp_user', 'smtp_port', 'smtp_from_name',
            'review_incentive_enabled', 'review_incentive_type', 'review_incentive_value',
            'auto_cancel_bank_transfer_hours',
            'loyalty_earn_rate', 'loyalty_redeem_rate', 'loyalty_min_redeem',
            'home_service_min', 'travel_fee_farnborough', 'travel_fee_camberley_aldershot', 'travel_fee_further',
        ];
        foreach ($fields as $key) {
            $val = sanitize($_POST[$key] ?? '');
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
               ->execute([$key, $val]);
        }
        // Handle checkboxes separately
        $checkboxes = [
            'review_incentive_enabled',
            'admin_notify_morning',
            'admin_notify_evening',
            'admin_notify_30min',
            'loyalty_enabled',
        ];
        foreach ($checkboxes as $key) {
            $val = isset($_POST[$key]) ? '1' : '0';
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
               ->execute([$key, $val]);
        }
        // Business address — multiline; stored raw (newlines preserved), HTML-escaped
        // at render time by appointmentLocationBlock(). Don't run through sanitize()
        // (which HTML-encodes) or it would double-encode on output.
        $bizAddr = trim(strip_tags($_POST['business_address'] ?? ''));
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('business_address',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
           ->execute([$bizAddr]);
        $msg = 'Settings saved.';

    } elseif ($action === 'save_brand') {
        $brandFields = [
            'brand_color_primary', 'brand_color_primary_dark', 'brand_color_deep_purple',
            'brand_color_gold', 'brand_color_bg', 'brand_color_text', 'brand_color_text_muted',
            'brand_font_primary', 'brand_font_body',
        ];
        foreach ($brandFields as $key) {
            $val = sanitize($_POST[$key] ?? '');
            $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
               ->execute([$key, $val ?: null]);
        }
        // Logo upload
        if (!empty($_FILES['brand_logo']['name']) && $_FILES['brand_logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg','image/png','image/webp','image/svg+xml'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $_FILES['brand_logo']['tmp_name']);
            finfo_close($finfo);
            if (in_array($mime, $allowed) && $_FILES['brand_logo']['size'] < 2 * 1024 * 1024) {
                $ext  = pathinfo($_FILES['brand_logo']['name'], PATHINFO_EXTENSION);
                $dir  = __DIR__ . '/../uploads/brand/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $dest = $dir . 'logo.' . strtolower($ext);
                if (move_uploaded_file($_FILES['brand_logo']['tmp_name'], $dest)) {
                    $url = '/uploads/brand/logo.' . strtolower($ext);
                    $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('brand_logo_url',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$url]);
                }
            }
        }
        // Reset to defaults
        if (isset($_POST['reset_brand'])) {
            foreach (array_merge($brandFields, ['brand_logo_url']) as $key) {
                $db->prepare("DELETE FROM settings WHERE setting_key=?")->execute([$key]);
            }
            $msg = 'Brand reset to defaults.';
        } else {
            $msg = 'Brand settings saved.';
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $admin   = $db->query("SELECT * FROM admin_users LIMIT 1")->fetch();
        if (!$admin || !password_verify($current, $admin['password_hash'])) {
            $msg = 'ERROR: Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $msg = 'ERROR: New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $msg = 'ERROR: Passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")->execute([$hash, $admin['id']]);
            $msg = 'Password updated successfully.';
        }
    }
}


$pageTitle = $pageTitle ?? 'Settings';
require_once __DIR__ . '/includes/layout.php';

// Fetch all settings
$settingsRaw = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$S = [];
foreach ($settingsRaw as $row) $S[$row['setting_key']] = $row['setting_value'];
$g = fn($key, $default='') => $S[$key] ?? $default;

$msg = $msg ?: sanitize($_GET['msg'] ?? '');
$isError = str_starts_with($msg, 'ERROR:');
?>

<?php if ($msg): ?>
<div style="background:<?= $isError ? '#fee2e2' : '#d1fae5' ?>;border:1px solid <?= $isError ? '#fca5a5' : '#6ee7b7' ?>;padding:10px 16px;border-radius:var(--admin-radius);margin-bottom:16px;font-size:0.82rem;color:<?= $isError ? '#991b1b' : '#065f46' ?>">
  <?= $isError ? '✕' : '✓' ?> <?= htmlspecialchars(ltrim($msg, 'ERROR: ')) ?>
</div>
<?php endif; ?>

<div class="settings-grid">

  <!-- Nav -->
  <nav class="settings-nav">
    <a href="#general"        class="settings-nav-link active">General</a>
    <a href="#banking"        class="settings-nav-link">Banking</a>
    <a href="#bookings"       class="settings-nav-link">Bookings</a>
    <a href="#homeservice"    class="settings-nav-link">Home Service</a>
    <a href="#loyalty"        class="settings-nav-link">Loyalty</a>
    <a href="#email"          class="settings-nav-link">Email / SMTP</a>
    <a href="#reviews"        class="settings-nav-link">Reviews</a>
    <a href="#notifications"  class="settings-nav-link">Notifications</a>
    <a href="#social"         class="settings-nav-link">Social Links</a>
    <a href="#security"       class="settings-nav-link">Security</a>
  </nav>

  <!-- Forms -->
  <div>
    <form method="POST" action="/admin/settings">
      <input type="hidden" name="action" value="save_settings">

      <!-- General -->
      <div class="settings-section admin-form-card" id="general">
        <div class="settings-section-title">🌐 General</div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Business Name</label>
            <input class="admin-input" type="text" name="site_name" value="<?= htmlspecialchars($g('site_name','BraidedbyAGB')) ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Contact Email</label>
            <input class="admin-input" type="email" name="site_email" value="<?= htmlspecialchars($g('site_email','hello@braidedbyagb.co.uk')) ?>">
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Phone / WhatsApp</label>
            <input class="admin-input" type="text" name="site_phone" value="<?= htmlspecialchars($g('site_phone','07769 064 971')) ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Studio Location (public — town only)</label>
            <input class="admin-input" type="text" name="site_address" value="<?= htmlspecialchars($g('site_address','Farnborough, Hampshire')) ?>">
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-form-group" style="flex:1">
            <label class="admin-label">Full Salon Address (shown publicly across the site)</label>
            <textarea class="admin-input admin-textarea" name="business_address" rows="3"
                      placeholder="Unit 4, Selnews Business Centre, Peabody Road, Farnborough, GU14 6GX"><?= htmlspecialchars($g('business_address','Unit 4, Selnews Business Centre, Peabody Road, Farnborough, GU14 6GX')) ?></textarea>
            <small style="color:var(--admin-muted);font-size:0.72rem">Shown in the footer, contact page, chat and confirmation emails. Edit here to change it everywhere.</small>
          </div>
        </div>
      </div>

      <!-- Banking -->
      <div class="settings-section admin-form-card" id="banking">
        <div class="settings-section-title">🏦 Bank Transfer Details</div>
        <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:14px">Shown to clients who choose bank transfer at checkout.</p>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Account Name</label>
            <input class="admin-input" type="text" name="bank_account_name" value="<?= htmlspecialchars($g('bank_account_name','')) ?>" placeholder="Name on account">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Sort Code</label>
            <input class="admin-input" type="text" name="bank_sort_code" value="<?= htmlspecialchars($g('bank_sort_code','')) ?>" placeholder="XX-XX-XX">
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Account Number</label>
            <input class="admin-input" type="text" name="bank_account_number" value="<?= htmlspecialchars($g('bank_account_number','')) ?>" placeholder="8-digit account number">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Payment Hold (hours)</label>
            <input class="admin-input" type="number" name="bank_transfer_hold_hours" value="<?= htmlspecialchars($g('bank_transfer_hold_hours','24')) ?>" min="1" max="72">
          </div>
        </div>
      </div>

      <!-- Booking policy -->
      <div class="settings-section admin-form-card" id="bookings">
        <div class="settings-section-title">📅 Booking Policy</div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Deposit Percentage (%)</label>
            <input class="admin-input" type="number" name="deposit_percent" value="<?= htmlspecialchars($g('deposit_percent','30')) ?>" min="1" max="100">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Booking Buffer (hours notice required)</label>
            <input class="admin-input" type="number" name="booking_buffer_hours" value="<?= htmlspecialchars($g('booking_buffer_hours','2')) ?>" min="0" max="72">
          </div>
        </div>
        <div class="admin-form-group">
          <label class="admin-label">Auto-cancel unpaid bank transfer bookings after (hours)</label>
          <input class="admin-input" type="number" name="auto_cancel_bank_transfer_hours" value="<?= htmlspecialchars($g('auto_cancel_bank_transfer_hours','24')) ?>" min="1" max="72" style="max-width:120px">
        </div>
      </div>

      <!-- Home Service -->
      <div class="settings-section admin-form-card" id="homeservice">
        <div class="settings-section-title">🚗 Home Service (Mobile)</div>
        <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:14px">
          Clients can choose a home visit only when their combined booking total meets the minimum below.
          The travel fee is paid in full online, on top of the deposit.
        </p>
        <div class="admin-form-group" style="max-width:280px">
          <label class="admin-label">Minimum booking total for home service (£)</label>
          <input class="admin-input" type="number" name="home_service_min" value="<?= htmlspecialchars($g('home_service_min','70')) ?>" min="0" step="1">
        </div>
        <div class="admin-form-row" style="margin-top:12px">
          <div class="admin-form-group">
            <label class="admin-label">Farnborough — travel fee (£)</label>
            <input class="admin-input" type="number" name="travel_fee_farnborough" value="<?= htmlspecialchars($g('travel_fee_farnborough','25')) ?>" min="0" step="0.01">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Camberley / Aldershot — travel fee (£)</label>
            <input class="admin-input" type="number" name="travel_fee_camberley_aldershot" value="<?= htmlspecialchars($g('travel_fee_camberley_aldershot','30')) ?>" min="0" step="0.01">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Further locations — travel fee (£)</label>
            <input class="admin-input" type="number" name="travel_fee_further" value="<?= htmlspecialchars($g('travel_fee_further','45')) ?>" min="0" step="0.01">
          </div>
        </div>
        <small style="color:var(--admin-muted);font-size:0.72rem">These update the booking page and are re-checked when a client pays, so they can't be bypassed.</small>
      </div>

      <!-- SMTP -->
      <div class="settings-section admin-form-card" id="email">
        <div class="settings-section-title">✉️ Email / SMTP</div>
        <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:14px">
          These override the values in <code>config/database.php</code>. Leave blank to use config file defaults.
        </p>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">SMTP Host</label>
            <input class="admin-input" type="text" name="smtp_host" value="<?= htmlspecialchars($g('smtp_host','')) ?>" placeholder="mail.braidedbyagb.co.uk">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">SMTP Port</label>
            <input class="admin-input" type="number" name="smtp_port" value="<?= htmlspecialchars($g('smtp_port','465')) ?>" placeholder="465">
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">SMTP Username</label>
            <input class="admin-input" type="text" name="smtp_user" value="<?= htmlspecialchars($g('smtp_user','')) ?>" placeholder="hello@braidedbyagb.co.uk">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">From Name</label>
            <input class="admin-input" type="text" name="smtp_from_name" value="<?= htmlspecialchars($g('smtp_from_name','BraidedbyAGB')) ?>">
          </div>
        </div>
        <p style="font-size:0.72rem;color:var(--admin-muted)">⚠️ SMTP password is set in <code>config/database.php</code> only — not editable here for security.</p>
      </div>

      <!-- Reviews -->
      <div class="settings-section admin-form-card" id="reviews">
        <div class="settings-section-title">⭐ Review Incentives</div>
        <div class="admin-form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.85rem">
            <input type="checkbox" name="review_incentive_enabled" value="1"
                   <?= $g('review_incentive_enabled','0') === '1' ? 'checked' : '' ?>
                   style="accent-color:var(--admin-primary);width:16px;height:16px">
            <span>Auto-generate discount code when a review is submitted</span>
          </label>
        </div>
        <div class="admin-form-row" style="margin-top:12px">
          <div class="admin-form-group">
            <label class="admin-label">Incentive Type</label>
            <select name="review_incentive_type" class="admin-input admin-select">
              <option value="percent" <?= $g('review_incentive_type')=='percent'?'selected':'' ?>>Percent off (%)</option>
              <option value="fixed"   <?= $g('review_incentive_type')=='fixed'  ?'selected':'' ?>>Fixed amount (£)</option>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Value</label>
            <input class="admin-input" type="number" name="review_incentive_value"
                   value="<?= htmlspecialchars($g('review_incentive_value','10')) ?>" step="0.01" min="0">
          </div>
        </div>
      </div>

      <!-- Admin Notifications -->
      <div class="settings-section admin-form-card" id="notifications">
        <div class="settings-section-title">🔔 Admin Notifications</div>
        <p style="font-size:0.78rem;color:var(--admin-muted);margin-bottom:16px">
          These emails are sent to <strong><?= htmlspecialchars(SITE_EMAIL) ?></strong> (your admin email) so you stay on top of your schedule without opening the app.
        </p>

        <!-- Morning brief -->
        <div style="padding:14px 16px;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius);margin-bottom:12px">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" name="admin_notify_morning" value="1"
                   <?= $g('admin_notify_morning','1') === '1' ? 'checked' : '' ?>
                   style="accent-color:var(--admin-primary);width:16px;height:16px;margin-top:2px;flex-shrink:0">
            <div>
              <span style="font-size:0.85rem;font-weight:700;color:var(--admin-text)">☀️ Morning Daily Brief — 7:30 AM</span>
              <p style="font-size:0.75rem;color:var(--admin-muted);margin:3px 0 0">
                Emails you a full list of today's confirmed appointments with client names, WhatsApp links, and deposit status — so you start each day prepared.
              </p>
            </div>
          </label>
        </div>

        <!-- Evening preview -->
        <div style="padding:14px 16px;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius);margin-bottom:12px">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" name="admin_notify_evening" value="1"
                   <?= $g('admin_notify_evening','1') === '1' ? 'checked' : '' ?>
                   style="accent-color:var(--admin-primary);width:16px;height:16px;margin-top:2px;flex-shrink:0">
            <div>
              <span style="font-size:0.85rem;font-weight:700;color:var(--admin-text)">🌙 Evening Tomorrow Preview — 8:00 PM</span>
              <p style="font-size:0.75rem;color:var(--admin-muted);margin:3px 0 0">
                Emails you tomorrow's full appointment list each evening — including services, variants, add-ons, and any unpaid deposits to chase.
              </p>
            </div>
          </label>
        </div>

        <!-- 30-min pre-appointment -->
        <div style="padding:14px 16px;background:var(--admin-bg);border:1px solid var(--admin-border);border-radius:var(--admin-radius);margin-bottom:4px">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
            <input type="checkbox" name="admin_notify_30min" value="1"
                   <?= $g('admin_notify_30min','1') === '1' ? 'checked' : '' ?>
                   style="accent-color:var(--admin-primary);width:16px;height:16px;margin-top:2px;flex-shrink:0">
            <div>
              <span style="font-size:0.85rem;font-weight:700;color:var(--admin-text)">⏰ 30-Minute Pre-Appointment Alert</span>
              <p style="font-size:0.75rem;color:var(--admin-muted);margin:3px 0 0">
                Sends an email 30 minutes before each individual appointment with the client's name, phone number, WhatsApp link, service, and any notes — so you're always ready.
              </p>
            </div>
          </label>
        </div>

        <p style="font-size:0.7rem;color:var(--admin-muted);margin-top:10px">
          ⓘ The morning brief and evening preview are each sent <strong>once per day</strong> — the cron job runs every 30 minutes and checks whether it's already been sent today.
        </p>
      </div>

      <!-- Loyalty Programme -->
      <div class="settings-section admin-form-card" id="loyalty">
        <div class="settings-section-title">🎁 Loyalty Programme</div>
        <div class="admin-form-row" style="align-items:center;margin-bottom:12px">
          <div class="admin-form-group" style="flex:0 0 auto">
            <label class="admin-label">Enable Loyalty Scheme</label>
            <label style="display:flex;align-items:center;gap:8px;margin-top:4px;cursor:pointer">
              <input type="checkbox" name="loyalty_enabled" value="1" <?= $g('loyalty_enabled','1') === '1' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--admin-primary)">
              <span style="font-size:0.82rem;color:var(--admin-text-muted)">Customers earn and redeem points on bookings</span>
            </label>
          </div>
        </div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Points Earned per £1 Spent</label>
            <input class="admin-input" type="number" name="loyalty_earn_rate" min="0" step="1"
                   value="<?= htmlspecialchars($g('loyalty_earn_rate','1')) ?>"
                   placeholder="1">
            <small style="color:var(--admin-text-muted);font-size:0.75rem">e.g. 1 = clients earn 1 point for every £1 they pay</small>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Points Needed per £1 Discount</label>
            <input class="admin-input" type="number" name="loyalty_redeem_rate" min="1" step="1"
                   value="<?= htmlspecialchars($g('loyalty_redeem_rate','100')) ?>"
                   placeholder="100">
            <small style="color:var(--admin-text-muted);font-size:0.75rem">e.g. 100 = 100 points = £1 off</small>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Minimum Points to Redeem</label>
            <input class="admin-input" type="number" name="loyalty_min_redeem" min="0" step="50"
                   value="<?= htmlspecialchars($g('loyalty_min_redeem','500')) ?>"
                   placeholder="500">
            <small style="color:var(--admin-text-muted);font-size:0.75rem">e.g. 500 = clients need at least 500 pts (= £5 off) before they can redeem</small>
          </div>
        </div>
      </div>

      <!-- Social -->
      <div class="settings-section admin-form-card" id="social">
        <div class="settings-section-title">📱 Social Links</div>
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Instagram URL</label>
            <input class="admin-input" type="url" name="instagram_url" value="<?= htmlspecialchars($g('instagram_url','https://instagram.com/BraidedbyAGB')) ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">TikTok URL</label>
            <input class="admin-input" type="url" name="tiktok_url" value="<?= htmlspecialchars($g('tiktok_url','')) ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Facebook URL</label>
            <input class="admin-input" type="url" name="facebook_url" value="<?= htmlspecialchars($g('facebook_url','')) ?>" placeholder="https://facebook.com/YourPage">
          </div>
        </div>
      </div>

      <button type="submit" class="btn-admin btn-admin-primary" style="padding:10px 24px;font-size:0.85rem">
        Save All Settings
      </button>
    </form>

    <!-- Brand & Theme -->
    <div class="settings-section admin-form-card" id="brand" style="margin-top:20px">
      <div class="settings-section-title">🎨 Brand &amp; Theme</div>
      <form method="POST" action="/admin/settings" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_brand">

        <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:16px">
          Changes apply instantly to the whole site — public pages and admin panel.
          Leave a field blank to keep the default from <code>brand.css</code>.
        </p>

        <!-- Colour pickers -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:20px">
          <?php
          $colorFields = [
            'brand_color_primary'      => 'Primary (buttons, accents)',
            'brand_color_primary_dark' => 'Primary Dark (hover)',
            'brand_color_deep_purple'  => 'Deep Purple (headings)',
            'brand_color_gold'         => 'Gold (accents)',
            'brand_color_bg'           => 'Page Background',
            'brand_color_text'         => 'Body Text',
            'brand_color_text_muted'   => 'Muted Text',
          ];
          $colorDefaults = [
            'brand_color_primary'      => '#CC1A8A',
            'brand_color_primary_dark' => '#A8146E',
            'brand_color_deep_purple'  => '#7A0050',
            'brand_color_gold'         => '#F0C030',
            'brand_color_bg'           => '#F0D6F5',
            'brand_color_text'         => '#2A0020',
            'brand_color_text_muted'   => '#7A4A70',
          ];
          foreach ($colorFields as $key => $label):
            $val = $g($key, $colorDefaults[$key]);
          ?>
          <div class="admin-form-group" style="margin:0">
            <label class="admin-label" style="font-size:0.72rem"><?= $label ?></label>
            <div style="display:flex;align-items:center;gap:8px">
              <input type="color" name="<?= $key ?>" value="<?= htmlspecialchars($val) ?>"
                     style="width:44px;height:36px;padding:2px;border:1px solid var(--admin-border);border-radius:6px;cursor:pointer"
                     oninput="this.nextElementSibling.value=this.value">
              <input type="text" value="<?= htmlspecialchars($val) ?>"
                     style="width:90px;font-size:0.78rem;padding:6px 8px;border:1px solid var(--admin-border);border-radius:6px;font-family:monospace"
                     oninput="this.previousElementSibling.value=this.value;this.previousElementSibling.dispatchEvent(new Event('change'))"
                     onchange="document.querySelector('[name=<?= $key ?>]').value=this.value">
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Font dropdowns -->
        <div class="admin-form-row" style="margin-bottom:16px">
          <?php
          $fonts = ['Montserrat','Lato','Poppins','Raleway','Nunito','Open Sans','Playfair Display','Inter','DM Sans','Josefin Sans'];
          foreach (['brand_font_primary' => 'Heading Font', 'brand_font_body' => 'Body Font'] as $key => $label):
            $val = $g($key, $key === 'brand_font_primary' ? 'Montserrat' : 'Lato');
          ?>
          <div class="admin-form-group">
            <label class="admin-label"><?= $label ?></label>
            <select name="<?= $key ?>" class="admin-input admin-select">
              <?php foreach ($fonts as $f): ?>
                <option value="<?= $f ?>" <?= $val === $f ? 'selected' : '' ?>><?= $f ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Logo upload -->
        <div class="admin-form-group" style="margin-bottom:16px">
          <label class="admin-label">Logo (JPG, PNG, WebP, SVG — max 2MB)</label>
          <?php $logoUrl = $g('brand_logo_url',''); ?>
          <?php if ($logoUrl): ?>
            <div style="margin-bottom:8px">
              <img src="<?= htmlspecialchars($logoUrl) ?>" style="max-height:48px;max-width:200px;object-fit:contain;border:1px solid var(--admin-border);border-radius:4px;padding:4px">
            </div>
          <?php endif; ?>
          <input type="file" name="brand_logo" class="admin-input" accept="image/*" style="padding:6px">
        </div>

        <!-- Live preview -->
        <div style="background:#f8f5ff;border:1px solid var(--admin-border);border-radius:var(--admin-radius);padding:16px;margin-bottom:16px">
          <p style="font-size:0.72rem;font-weight:700;color:var(--admin-muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:10px">Preview</p>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button type="button" id="previewBtn" style="background:var(--color-primary,#CC1A8A);color:#fff;border:none;padding:8px 20px;border-radius:6px;font-family:var(--font-primary,'Montserrat'),sans-serif;font-weight:700;cursor:default">Primary Button</button>
            <span id="previewText" style="color:var(--color-text,#2A0020);font-family:var(--font-body,'Lato'),sans-serif;font-size:0.9rem">Sample body text in brand colour</span>
            <span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:var(--color-gold,#F0C030)"></span>
          </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <button type="submit" class="btn-admin btn-admin-primary">💾 Save Brand Settings</button>
          <button type="submit" name="reset_brand" value="1" class="btn-admin btn-admin-outline"
                  onclick="return confirm('Reset all brand settings to defaults?')">↺ Reset to Defaults</button>
        </div>
      </form>
    </div>

    <!-- Password change -->
    <div class="settings-section admin-form-card" id="security" style="margin-top:20px">
      <div class="settings-section-title">🔒 Change Admin Password</div>
      <form method="POST" action="/admin/settings">
        <input type="hidden" name="action" value="change_password">
        <div class="admin-form-row">
          <div class="admin-form-group">
            <label class="admin-label">Current Password</label>
            <input class="admin-input" type="password" name="current_password" autocomplete="current-password">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">New Password (min 8 chars)</label>
            <input class="admin-input" type="password" name="new_password" autocomplete="new-password">
          </div>
        </div>
        <div class="admin-form-group" style="max-width:340px">
          <label class="admin-label">Confirm New Password</label>
          <input class="admin-input" type="password" name="confirm_password" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-admin btn-admin-warning" style="margin-top:4px">Update Password</button>
      </form>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
