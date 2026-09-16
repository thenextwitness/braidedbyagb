<?php
// ============================================================
// BraidedbyAGB — Client payment history
// FILE: /public/account/payments.php  →  /account/payments
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

// Successful payments tied to this client's bookings or orders.
$rows = $db->prepare("
    SELECT p.amount, p.type, p.method, p.status, p.created_at, p.confirmed_at,
           b.booking_ref, o.order_ref
    FROM payments p
    LEFT JOIN bookings b ON b.id = p.booking_id
    LEFT JOIN orders   o ON o.id = p.order_id
    WHERE (b.customer_id = ? OR o.customer_id = ?)
      AND p.status = 'succeeded'
    ORDER BY p.created_at DESC
");
$rows->execute([$cid, $cid]);
$payments = $rows->fetchAll();

function paymentMethodLabel(string $m): string {
    return match ($m) {
        'stripe'          => 'Card',
        'stripe_terminal' => 'Card (in person)',
        'bank_transfer'   => 'Bank transfer',
        default           => ucfirst($m),
    };
}

$pageTitle = 'My Payments';
$activeTab = '/account/payments';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head"><h1>My payments</h1><p>A record of what you've paid us.</p></div>
  <?php require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <?php if (!$payments): ?>
    <div class="account-card account-empty">No payments recorded yet.</div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($payments as $p):
        $when = date('D j M Y', strtotime($p['confirmed_at'] ?: $p['created_at']));
        $ref  = $p['booking_ref'] ?: $p['order_ref'] ?: '';
        $forWhat = $p['booking_ref'] ? 'Appointment' : ($p['order_ref'] ? 'Shop order' : 'Payment');
        $typeLabel = ucfirst((string)$p['type']); ?>
        <div class="account-row">
          <div>
            <div class="title"><?= formatPrice((float)$p['amount']) ?> · <?= htmlspecialchars($typeLabel) ?></div>
            <div class="meta">
              <?= $when ?> · <?= htmlspecialchars(paymentMethodLabel($p['method'])) ?>
              · <?= htmlspecialchars($forWhat) ?><?= $ref ? ' ' . htmlspecialchars($ref) : '' ?>
            </div>
          </div>
          <span class="account-pill pill-completed">Paid</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
