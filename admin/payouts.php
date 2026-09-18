<?php
// ============================================================
// BraidedbyAGB — Admin Stylist Payouts
// FILE: /admin/payouts.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Pay stylists what they have EARNED (Phase C3) but not yet been paid. Creating
// a payout locks the settled assignments and posts the wage expense to the
// ledger (5300/5310) via includes/payouts.php. Voiding fully reverses it.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payouts.php';
requireAdmin();
$db = getDB();

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'create_payout') {
            $sid = (int)($_POST['stylist_id'] ?? 0);
            $res = createStylistPayout($db, $sid, [
                'method'       => $_POST['method'] ?? 'bank_transfer',
                'reference'    => sanitize($_POST['reference'] ?? ''),
                'adjustment'   => (float)($_POST['adjustment'] ?? 0),
                'notes'        => sanitize($_POST['notes'] ?? ''),
                'payout_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['payout_date'] ?? '') ? $_POST['payout_date'] : date('Y-m-d'),
                'period_start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['period_start'] ?? '') ? $_POST['period_start'] : null,
                'period_end'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['period_end'] ?? '') ? $_POST['period_end'] : null,
                'created_by'   => (int)($_SESSION['admin_id'] ?? 0),
            ]);
            if ($res['ok']) $msg = 'Payout of ' . formatPrice($res['amount']) . ' recorded.';
            else            $error = $res['error'] ?? 'Could not record payout.';

        } elseif ($action === 'void_payout') {
            $res = voidStylistPayout($db, (int)($_POST['payout_id'] ?? 0));
            if ($res['ok']) $msg = 'Payout voided — those earnings are owed again.';
            else            $error = $res['error'] ?? 'Could not void payout.';
        }
    } catch (Exception $e) {
        error_log('Payouts admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Stylist Payouts';
require_once __DIR__ . '/includes/layout.php';

// ── Data ──────────────────────────────────────────────────
try {
    $stylists = $db->query("SELECT id, name, is_owner FROM stylists WHERE is_active = 1 ORDER BY is_owner DESC, name")->fetchAll();
    $owedByStylist = [];
    foreach ($stylists as $s) $owedByStylist[(int)$s['id']] = stylistOwed($db, (int)$s['id']);

    $payouts = $db->query("SELECT p.*, s.name AS stylist_name
                           FROM stylist_payouts p JOIN stylists s ON s.id = p.stylist_id
                           ORDER BY p.payout_date DESC, p.id DESC LIMIT 100")->fetchAll();
} catch (Exception $e) {
    $stylists = []; $owedByStylist = []; $payouts = [];
    $error = $error ?: 'Could not load payouts. Run the database migration first.';
}

$readyToPay = array_filter($stylists, fn($s) => ($owedByStylist[(int)$s['id']]['total'] ?? 0) > 0.005);
$grandOwed  = array_sum(array_map(fn($o) => $o['total'], $owedByStylist));
?>

<?php if ($msg):   ?><div class="alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Stylist Payouts</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem">
      <?= formatPrice($grandOwed) ?> owed across <?= count($readyToPay) ?> stylist<?= count($readyToPay) === 1 ? '' : 's' ?>
    </p>
  </div>
</div>

<!-- ── Ready to pay ───────────────────────────────────────── -->
<h3 style="font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted);margin:0 0 10px">Owed now</h3>
<?php if (empty($readyToPay)): ?>
  <div class="table-empty"><div class="table-empty-icon">💸</div><p>No stylist has earnings owed right now. Earnings appear here once their bookings are marked completed.</p></div>
<?php else: foreach ($readyToPay as $s):
    $id = (int)$s['id']; $o = $owedByStylist[$id]; ?>
  <div class="admin-card" style="margin-bottom:12px">
    <div class="admin-card-header" style="cursor:pointer" onclick="document.getElementById('pay-<?= $id ?>').classList.toggle('hidden')">
      <span class="admin-card-title"><?= htmlspecialchars($s['name']) ?><?= $s['is_owner'] ? ' (owner)' : '' ?></span>
      <span style="font-size:0.82rem;color:var(--admin-primary);font-weight:700">
        <?= formatPrice($o['total']) ?> owed
        <span style="color:var(--admin-muted);font-weight:400">· <?= $o['count'] ?> job<?= $o['count'] === 1 ? '' : 's' ?></span>
      </span>
    </div>
    <div id="pay-<?= $id ?>" class="admin-card-body hidden">
      <p style="font-size:0.75rem;color:var(--admin-muted);margin-bottom:12px">
        Commission <?= formatPrice($o['commission']) ?> · Hourly <?= formatPrice($o['hourly']) ?>.
        Recording a payout locks these <?= $o['count'] ?> job<?= $o['count'] === 1 ? '' : 's' ?> and posts the wage expense to the books.
      </p>
      <form method="POST" onsubmit="return confirm('Record this payout? It locks the settled jobs and posts to the ledger.')">
        <input type="hidden" name="action" value="create_payout">
        <input type="hidden" name="stylist_id" value="<?= $id ?>">
        <div class="admin-form-row" style="margin-bottom:12px">
          <div class="admin-form-group">
            <label class="admin-label">Method</label>
            <select class="admin-input" name="method">
              <option value="bank_transfer">Bank transfer</option>
              <option value="cash">Cash</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Payout date</label>
            <input class="admin-input" type="date" name="payout_date" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Reference</label>
            <input class="admin-input" type="text" name="reference" placeholder="e.g. bank ref / note">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Adjustment (£)</label>
            <input class="admin-input" type="number" name="adjustment" step="0.01" value="0"
                   title="Add a bonus (+) or deduct (−) from this payout">
          </div>
        </div>
        <div class="admin-form-row" style="margin-bottom:12px">
          <div class="admin-form-group">
            <label class="admin-label">Period from (optional)</label>
            <input class="admin-input" type="date" name="period_start">
          </div>
          <div class="admin-form-group">
            <label class="admin-label">Period to (optional)</label>
            <input class="admin-input" type="date" name="period_end">
          </div>
          <div class="admin-form-group" style="flex:2">
            <label class="admin-label">Notes</label>
            <input class="admin-input" type="text" name="notes" placeholder="Internal note (optional)">
          </div>
        </div>
        <button type="submit" class="btn-admin btn-admin-primary">Record payout of <?= formatPrice($o['total']) ?> (before adjustment)</button>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<!-- ── Payout history ─────────────────────────────────────── -->
<h3 style="font-family:'Montserrat',sans-serif;font-size:0.7rem;font-weight:700;letter-spacing:0.15em;text-transform:uppercase;color:var(--admin-muted);margin:26px 0 10px">Payout history</h3>
<?php if (empty($payouts)): ?>
  <p style="font-size:0.82rem;color:var(--admin-muted)">No payouts recorded yet.</p>
<?php else: ?>
  <div class="admin-card">
    <table class="admin-table" style="width:100%;border-collapse:collapse;font-size:0.82rem">
      <thead>
        <tr style="text-align:left;color:var(--admin-muted)">
          <th style="padding:8px 10px">Date</th>
          <th style="padding:8px 10px">Stylist</th>
          <th style="padding:8px 10px">Commission</th>
          <th style="padding:8px 10px">Hourly</th>
          <th style="padding:8px 10px">Adj.</th>
          <th style="padding:8px 10px">Total</th>
          <th style="padding:8px 10px">Method</th>
          <th style="padding:8px 10px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payouts as $p): ?>
        <tr style="border-top:1px solid var(--admin-border)">
          <td style="padding:8px 10px"><?= date('j M Y', strtotime($p['payout_date'])) ?></td>
          <td style="padding:8px 10px"><?= htmlspecialchars($p['stylist_name']) ?></td>
          <td style="padding:8px 10px"><?= formatPrice($p['commission_total']) ?></td>
          <td style="padding:8px 10px"><?= formatPrice($p['hourly_total']) ?></td>
          <td style="padding:8px 10px"><?= (float)$p['adjustment'] != 0 ? formatPrice($p['adjustment']) : '—' ?></td>
          <td style="padding:8px 10px;font-weight:700"><?= formatPrice($p['amount']) ?></td>
          <td style="padding:8px 10px"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $p['method']))) ?></td>
          <td style="padding:8px 10px">
            <form method="POST" style="margin:0" onsubmit="return confirm('Void this payout? The earnings become owed again and the ledger entry is reversed.')">
              <input type="hidden" name="action" value="void_payout">
              <input type="hidden" name="payout_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Void</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<style>.admin-card-body.hidden,[id^="pay-"].hidden{display:none}</style>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
