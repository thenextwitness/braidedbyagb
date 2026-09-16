<?php
// ============================================================
// BraidedbyAGB — Client shop orders
// FILE: /public/account/orders.php  →  /account/orders
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/portal-auth.php';

requireClient();
$db  = getDB();
$cid = currentClientId();

$rows = $db->prepare("
    SELECT o.order_ref, o.total, o.status, o.payment_method, o.payment_confirmed, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.customer_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$rows->execute([$cid]);
$orders = $rows->fetchAll();

function orderPill(string $status): string {
    $map = ['delivered'=>'completed','collected'=>'completed','dispatched'=>'confirmed','processing'=>'confirmed','pending'=>'pending','cancelled'=>'cancelled'];
    return 'account-pill pill-' . ($map[$status] ?? 'pending');
}

$pageTitle = 'My Orders';
$activeTab = '/account/orders';
require_once __DIR__ . '/../../includes/account-head.php';
?>
<div class="account-wrap">
  <div class="account-head"><h1>My orders</h1><p>Your BraidedbyAGB shop purchases.</p></div>
  <?php require __DIR__ . '/../../includes/account-tabs.php'; ?>

  <?php if (!$orders): ?>
    <div class="account-card account-empty">No orders yet. <a class="auth-link" href="/shop">Browse the shop »</a></div>
  <?php else: ?>
    <div class="account-list">
      <?php foreach ($orders as $o):
        $date = date('D j M Y', strtotime($o['created_at'])); ?>
        <div class="account-row">
          <div>
            <div class="title"><?= htmlspecialchars($o['order_ref']) ?></div>
            <div class="meta">
              <?= $date ?> · <?= (int)$o['item_count'] ?> item<?= (int)$o['item_count'] === 1 ? '' : 's' ?>
              · <?= formatPrice((float)$o['total']) ?>
              <?= (int)$o['payment_confirmed'] ? '· paid' : '· awaiting payment' ?>
            </div>
          </div>
          <span class="<?= orderPill($o['status']) ?>"><?= htmlspecialchars(ucfirst($o['status'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/account-foot.php'; ?>
