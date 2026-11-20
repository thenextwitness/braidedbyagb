<?php
// ============================================================
// BraidedbyAGB — Client account dashboard
// FILE: /public/account/index.php  →  /account
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

$cust = $db->prepare("SELECT * FROM customers WHERE id = ?");
$cust->execute([$cid]);
$cust = $cust->fetch();
if (!$cust) { portalLogout(); header('Location: /login'); exit; }

// Summary figures.
$upcoming = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND booked_date >= CURDATE() AND status IN ('pending','confirmed')");
$upcoming->execute([$cid]);
$upcomingCount = (int)$upcoming->fetchColumn();

$completed = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status = 'completed'");
$completed->execute([$cid]);
$completedCount = (int)$completed->fetchColumn();

// Recent bookings (any status), newest appointment first.
$recent = $db->prepare("
    SELECT b.booking_ref, b.booked_date, b.booked_time, b.status, b.total_price, b.confirm_token,
           COALESCE(NULLIF(b.custom_style_name,''), s.name) AS service_name
    FROM bookings b
    JOIN services s ON s.id = b.service_id
    WHERE b.customer_id = ?
    ORDER BY b.booked_date DESC, b.booked_time DESC
    LIMIT 6
");
$recent->execute([$cid]);
$recentBookings = $recent->fetchAll();

function acctPill(string $status): string {
    $known = ['completed','confirmed','pending','incomplete','cancelled'];
    $cls = in_array($status, $known, true) ? $status : 'pending';
    return 'account-pill pill-' . $cls;
}

// "Book again" = their most recent completed appointment, one tap (else plain booking).
$lastDone = $db->prepare("SELECT id FROM bookings WHERE customer_id = ? AND status = 'completed' ORDER BY booked_date DESC, booked_time DESC LIMIT 1");
$lastDone->execute([$cid]);
$lastDoneId = (int)$lastDone->fetchColumn();
$bookAgainUrl = ($lastDoneId ? rebookUrl($db, $lastDoneId) : '') ?: '/booking';

$firstName = trim(explode(' ', (string)$cust['name'])[0]) ?: 'there';
$pageTitle = 'My Account';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">

  <div class="account-head">
    <h1>Hi <?= htmlspecialchars($firstName) ?> 👋</h1>
    <p>Welcome back to your BraidedbyAGB account.</p>
  </div>

  <?php $activeTab = '/account'; require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <div data-agb-install style="margin:-8px 0 22px;display:flex;align-items:center;gap:12px;background:#F9EEF9;border:1px solid #E8D8EE;border-radius:12px;padding:12px 16px;">
    <span style="font-size:1.4rem">📲</span>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;color:#7A0050;font-size:0.95rem">Install the app</div>
      <div style="color:#6B5575;font-size:0.82rem">Add BraidedbyAGB to your home screen for one-tap booking.</div>
    </div>
    <button type="button" data-agb-install class="auth-btn" style="width:auto;margin:0;padding:10px 18px;font-size:0.9rem">Install</button>
  </div>

  <div class="account-grid" style="margin-bottom:30px;">
    <div class="account-card">
      <h3>Upcoming</h3>
      <div class="account-stat"><?= $upcomingCount ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">appointment<?= $upcomingCount === 1 ? '' : 's' ?> booked</p>
    </div>
    <div class="account-card">
      <h3>Completed</h3>
      <div class="account-stat"><?= $completedCount ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">visit<?= $completedCount === 1 ? '' : 's' ?> with us</p>
    </div>
    <div class="account-card">
      <h3>Loyalty points</h3>
      <div class="account-stat"><?= (int)($cust['loyalty_points'] ?? 0) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">earned so far</p>
    </div>
    <div class="account-card" style="display:flex;flex-direction:column;justify-content:center;">
      <a href="<?= htmlspecialchars($bookAgainUrl) ?>" class="auth-btn" style="text-decoration:none;text-align:center;margin:0;">Book again</a>
    </div>
  </div>

  <?php
  // ── "AGB bring your bestie" ──
  $bestie = null;
  if (referralEnabled()) {
      try {
          $amts  = referralAmounts();
          $link  = referralLink($db, (int)$cid);
          $credit = customerCreditBalance($db, (int)$cid);
          $cnt = $db->prepare("SELECT SUM(status='completed') AS done, SUM(status='pending') AS waiting FROM referrals WHERE referrer_id = ?");
          $cnt->execute([(int)$cid]);
          $c = $cnt->fetch() ?: [];
          $money = fn($v) => '£' . rtrim(rtrim(number_format((float)$v, 2), '0'), '.');
          if ($link !== '') $bestie = ['link' => $link, 'friend' => $money($amts['friend']), 'you' => $money($amts['referrer']),
                                        'credit' => $credit, 'creditTxt' => $money($credit),
                                        'done' => (int)($c['done'] ?? 0), 'waiting' => (int)($c['waiting'] ?? 0)];
      } catch (Throwable $e) { error_log('account bestie card: ' . $e->getMessage()); }
  }
  ?>
  <?php if ($bestie): ?>
  <div class="account-card" style="margin-bottom:30px;border:2px solid var(--color-primary,#CC1A8A)">
    <h3 style="margin-top:0">💕 AGB bring your bestie</h3>
    <p style="margin:0 0 12px;color:#4B3A55">Share your link: your bestie gets <strong><?= $bestie['friend'] ?> off</strong> their first appointment, and you get <strong><?= $bestie['you'] ?> off</strong> your next one once they've been in.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="text" id="bestieLink" value="<?= htmlspecialchars($bestie['link']) ?>" readonly
             style="flex:1;min-width:0;padding:10px 12px;border:1px solid #E8D8EE;border-radius:8px;font-size:0.85rem;background:#fff">
      <button type="button" class="auth-btn" style="width:auto;margin:0;padding:10px 16px" onclick="copyBestie(this)">Copy link</button>
      <a class="auth-btn" style="width:auto;margin:0;padding:10px 16px;background:#25D366;text-decoration:none"
         target="_blank" href="https://wa.me/?text=<?= rawurlencode("Book your braids with BraidedbyAGB and get {$bestie['friend']} off your first appointment 💕 " . $bestie['link']) ?>">Share on WhatsApp</a>
    </div>
    <p style="margin:12px 0 0;color:#8A7595;font-size:0.85rem">
      <?php if ($bestie['credit'] > 0): ?><strong style="color:#7A0050">You have <?= $bestie['creditTxt'] ?> credit</strong>: it comes off your balance on the day of your next booking automatically. <?php endif; ?>
      <?= $bestie['done'] ?> bestie<?= $bestie['done'] === 1 ? '' : 's' ?> booked so far<?= $bestie['waiting'] ? ', ' . $bestie['waiting'] . ' with an appointment coming up' : '' ?>.
    </p>
  </div>
  <script>
  function copyBestie(btn) {
    const i = document.getElementById('bestieLink');
    (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).catch(() => { i.select(); document.execCommand('copy'); })
      .finally(() => { btn.textContent = 'Copied ✓'; setTimeout(() => btn.textContent = 'Copy link', 2000); });
  }
  </script>
  <?php endif; ?>

  <h2 style="font-family:'Montserrat',sans-serif;color:var(--color-deep-purple,#7A0050);font-size:1.15rem;margin:0 0 14px;">Recent bookings</h2>
  <?php if (!$recentBookings): ?>
    <div class="account-card account-empty">You have no bookings yet. <a class="auth-link" href="/booking">Book your first appointment »</a></div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($recentBookings as $b):
        $date = date('D j M Y', strtotime($b['booked_date']));
        $time = substr((string)$b['booked_time'], 0, 5);
        $link = '/account/bookings/' . urlencode($b['booking_ref']); ?>
        <a class="account-row" style="text-decoration:none" href="<?= htmlspecialchars($link) ?>">
          <div>
            <div class="title"><?= htmlspecialchars($b['service_name']) ?></div>
            <div class="meta"><?= $date ?> · <?= $time ?> · <?= htmlspecialchars($b['booking_ref']) ?></div>
          </div>
          <span class="<?= acctPill($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <p style="margin-top:16px;"><a class="auth-link" href="/account/bookings">View all bookings »</a></p>
  <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
