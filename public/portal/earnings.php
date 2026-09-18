<?php
// ============================================================
// BraidedbyAGB — Stylist portal: earnings & payouts
// FILE: /public/portal/earnings.php  →  /portal/earnings
//
// Read-only view of what the stylist has earned, what is owed (earned but not
// yet paid), and their payout history. Figures are DERIVED from
// booking_assignments / stylist_payouts — the stylist can see but not change.
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireStylist();
$db  = getDB();
$sid = currentStylistId();

$stylist = $db->prepare("SELECT * FROM stylists WHERE id = ?");
$stylist->execute([$sid]);
$stylist = $stylist->fetch();
if (!$stylist) { portalLogout(); header('Location: /login'); exit; }

// Earned but not yet in a payout — what the owner currently owes.
$owedRows = $db->prepare("
    SELECT ba.assign_role, ba.pay_model, ba.earnings_amount, ba.commission_pct,
           ba.hourly_rate, ba.hours_worked, ba.hours_planned, ba.earnings_base,
           b.booked_date, b.booking_ref,
           COALESCE(NULLIF(b.custom_style_name,''), s.name) AS service_name
    FROM booking_assignments ba
    JOIN bookings b ON b.id = ba.booking_id
    JOIN services s ON s.id = b.service_id
    WHERE ba.stylist_id = ? AND ba.earnings_status = 'earned' AND ba.payout_id IS NULL
    ORDER BY b.booked_date DESC
");
$owedRows->execute([$sid]);
$owed = $owedRows->fetchAll();
$owedTotal = array_sum(array_map(fn($r) => (float)$r['earnings_amount'], $owed));

// Pending — booking not completed yet (projected, not owed).
$pend = $db->prepare("
    SELECT ba.earnings_amount, b.booked_date, b.booking_ref,
           COALESCE(NULLIF(b.custom_style_name,''), s.name) AS service_name
    FROM booking_assignments ba
    JOIN bookings b ON b.id = ba.booking_id
    JOIN services s ON s.id = b.service_id
    WHERE ba.stylist_id = ? AND ba.earnings_status = 'pending'
    ORDER BY b.booked_date DESC
");
$pend->execute([$sid]);
$pending = $pend->fetchAll();
$pendingTotal = array_sum(array_map(fn($r) => (float)$r['earnings_amount'], $pending));

// Payout history.
$pays = $db->prepare("SELECT * FROM stylist_payouts WHERE stylist_id = ? ORDER BY payout_date DESC, id DESC");
$pays->execute([$sid]);
$payouts = $pays->fetchAll();
$paidTotal = array_sum(array_map(fn($r) => (float)$r['amount'], $payouts));

/** Human line explaining how one earned row was calculated. */
function earnBasis(array $r): string {
    if ($r['pay_model'] === 'commission') {
        $pct = rtrim(rtrim(number_format((float)($r['commission_pct'] ?? 0), 2), '0'), '.');
        return ($pct !== '' ? $pct : '0') . '% of ' . formatPrice($r['earnings_base'] ?? 0);
    }
    if ($r['pay_model'] === 'hourly') {
        $h = rtrim(rtrim(number_format((float)($r['hours_worked'] ?? $r['hours_planned'] ?? 0), 2), '0'), '.');
        return $h . 'h × £' . number_format((float)($r['hourly_rate'] ?? 0), 2) . '/hr';
    }
    return '—';
}

$pageTitle = 'Earnings';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">

  <div class="account-head">
    <h1>Earnings</h1>
    <p>What you've earned, what's owed, and your payout history.</p>
  </div>

  <?php $activeTab = '/portal/earnings'; require __DIR__ . '/../../includes/portal-tabs.php'; ?>

  <div class="account-grid" style="margin-bottom:30px;">
    <div class="account-card">
      <h3>Owed now</h3>
      <div class="account-stat"><?= formatPrice($owedTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">earned, awaiting payout</p>
    </div>
    <div class="account-card">
      <h3>Projected</h3>
      <div class="account-stat"><?= formatPrice($pendingTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">from upcoming work</p>
    </div>
    <div class="account-card">
      <h3>Paid to date</h3>
      <div class="account-stat"><?= formatPrice($paidTotal) ?></div>
      <p style="margin:6px 0 0;color:#8A7595;font-size:0.85rem;">lifetime payouts</p>
    </div>
  </div>

  <!-- Owed now -->
  <h2 style="font-family:'Montserrat',sans-serif;color:var(--color-deep-purple,#7A0050);font-size:1.15rem;margin:0 0 14px;">Owed to you (<?= formatPrice($owedTotal) ?>)</h2>
  <?php if (!$owed): ?>
    <div class="account-card account-empty">Nothing owed right now.</div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($owed as $r): ?>
        <div class="account-row">
          <div>
            <div class="title"><?= htmlspecialchars($r['service_name']) ?><?= $r['assign_role']==='assist' ? ' · assisting' : '' ?></div>
            <div class="meta"><?= date('D j M Y', strtotime($r['booked_date'])) ?> · <?= htmlspecialchars($r['booking_ref']) ?> · <?= earnBasis($r) ?></div>
          </div>
          <div style="font-weight:700;color:#7A0050"><?= formatPrice($r['earnings_amount']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Payout history -->
  <h2 style="font-family:'Montserrat',sans-serif;color:var(--color-deep-purple,#7A0050);font-size:1.15rem;margin:28px 0 14px;">Payout history</h2>
  <?php if (!$payouts): ?>
    <div class="account-card account-empty">No payouts yet.</div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($payouts as $p):
        $period = ($p['period_start'] && $p['period_end'])
                ? date('j M', strtotime($p['period_start'])) . ' – ' . date('j M Y', strtotime($p['period_end']))
                : ''; ?>
        <div class="account-row">
          <div>
            <div class="title"><?= formatPrice($p['amount']) ?> · <?= htmlspecialchars(ucwords(str_replace('_',' ',$p['method']))) ?></div>
            <div class="meta">
              <?= date('D j M Y', strtotime($p['payout_date'])) ?>
              <?= $period ? ' · covers ' . $period : '' ?>
              <?php if ($p['reference']): ?> · ref <?= htmlspecialchars($p['reference']) ?><?php endif; ?>
            </div>
          </div>
          <div style="text-align:right;font-size:0.72rem;color:#8A7595">
            <?php if ((float)$p['commission_total'] > 0): ?>comm <?= formatPrice($p['commission_total']) ?><br><?php endif; ?>
            <?php if ((float)$p['hourly_total']   > 0): ?>hours <?= formatPrice($p['hourly_total']) ?><br><?php endif; ?>
            <?php if ((float)$p['adjustment'] != 0):   ?>adj <?= formatPrice($p['adjustment']) ?><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Projected -->
  <?php if ($pending): ?>
  <h2 style="font-family:'Montserrat',sans-serif;color:var(--color-deep-purple,#7A0050);font-size:1.15rem;margin:28px 0 14px;">Projected (upcoming, not yet earned)</h2>
  <div class="account-list">
    <?php foreach ($pending as $r): ?>
      <div class="account-row">
        <div>
          <div class="title"><?= htmlspecialchars($r['service_name']) ?></div>
          <div class="meta"><?= date('D j M Y', strtotime($r['booked_date'])) ?> · <?= htmlspecialchars($r['booking_ref']) ?></div>
        </div>
        <div style="font-weight:700;color:#8A7595"><?= formatPrice($r['earnings_amount']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
