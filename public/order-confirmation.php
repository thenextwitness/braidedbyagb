<?php
// ============================================================
// BraidedbyAGB — Order Confirmation Page
// FILE: /public/order-confirmation.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$db  = getDB();
$ref = sanitize($_GET['ref'] ?? '');

$order = null;
if ($ref) {
    $stmt = $db->prepare("
        SELECT o.*, c.name as c_name, c.email as c_email
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        WHERE o.order_ref = ?
        LIMIT 1
    ");
    $stmt->execute([$ref]);
    $order = $stmt->fetch();
}
if (!$order) { header('Location: /shop'); exit; }

$items = $db->prepare("
    SELECT oi.*, p.name as p_name, p.slug as p_slug,
           pv.colour, pv.size
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    LEFT JOIN product_variants pv ON pv.id = oi.variant_id
    WHERE oi.order_id = ?
");
$items->execute([$order['id']]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="robots" content="noindex,nofollow">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Confirmed — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <link rel="stylesheet" href="/assets/css/shop.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
<!-- Event snippet for order conversion -->
<script>gtag('event', 'conversion', {'send_to': 'AW-17393399906/YhPWCPDLmaocEOLw6OVA'});</script>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>
<main class="page-content" style="background:var(--color-bg-light);min-height:100vh;padding:var(--space-20) 0">
  <div style="max-width:640px;margin:0 auto;padding:0 var(--space-6)">

    <!-- Success card -->
    <div style="background:var(--color-white);border-radius:var(--border-radius-xl);padding:var(--space-10);box-shadow:var(--shadow-xl);text-align:center;margin-bottom:var(--space-6)">
      <div style="width:72px;height:72px;background:linear-gradient(135deg,var(--color-primary),var(--color-deep-purple));border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-5);font-size:2rem;color:white">
        ✓
      </div>
      <h1 style="font-size:var(--text-3xl);color:var(--color-deep-purple);margin-bottom:var(--space-2)">
        <?= $order['payment_method'] === 'bank_transfer' ? 'Order Placed!' : 'Order Confirmed!' ?>
      </h1>
      <p style="color:var(--color-text-muted);font-size:var(--text-md);line-height:1.7">
        Thank you, <strong><?= htmlspecialchars(explode(' ', $order['c_name'])[0]) ?>!</strong>
        <?php if ($order['payment_method'] === 'bank_transfer'): ?>
          Your order is held for 24 hours while we await your bank transfer.
        <?php else: ?>
          Your payment was successful and your order is confirmed.
        <?php endif; ?>
      </p>
    </div>

    <!-- Order summary -->
    <div class="booking-summary" style="margin-bottom:var(--space-6)">
      <div class="booking-summary-header">Order Details — <?= htmlspecialchars($order['order_ref']) ?></div>
      <div class="booking-summary-body">

        <?php foreach ($items as $item): ?>
        <div class="summary-row">
          <span class="label">
            <?= htmlspecialchars($item['p_name']) ?>
            <?= $item['colour'] ? ' (' . htmlspecialchars($item['colour']) : '' ?>
            <?= $item['size']   ? ' ' . htmlspecialchars($item['size']) : '' ?>
            <?= ($item['colour'] || $item['size']) ? ')' : '' ?>
            × <?= (int)$item['quantity'] ?>
          </span>
          <span class="value">£<?= number_format((float)$item['price_charged'] * $item['quantity'], 2) ?></span>
        </div>
        <?php endforeach; ?>

        <?php if ((float)$order['discount_amount'] > 0): ?>
        <div class="summary-row">
          <span class="label" style="color:var(--color-success)">Discount</span>
          <span class="value" style="color:var(--color-success)">−£<?= number_format((float)$order['discount_amount'], 2) ?></span>
        </div>
        <?php endif; ?>

        <div class="summary-row">
          <span class="label">Delivery</span>
          <span class="value">
            <?= $order['delivery_type'] === 'local_pickup' ? 'Local Pickup — Farnborough' : 'UK Standard Delivery' ?>
          </span>
        </div>
        <?php if ((float)$order['shipping_cost'] > 0): ?>
        <div class="summary-row">
          <span class="label">Shipping Cost</span>
          <span class="value">£<?= number_format((float)$order['shipping_cost'], 2) ?></span>
        </div>
        <?php endif; ?>

        <div class="summary-row total">
          <span class="label">Total <?= $order['payment_method'] === 'stripe' ? 'Paid' : 'Due' ?></span>
          <span class="value">£<?= number_format((float)$order['total'], 2) ?></span>
        </div>

        <div class="summary-row">
          <span class="label">Status</span>
          <span class="value">
            <?php
            $labels = ['pending'=>'🕐 Pending Payment','processing'=>'🔄 Processing','dispatched'=>'🚚 Dispatched','delivered'=>'✅ Delivered'];
            echo $labels[$order['status']] ?? ucfirst($order['status']);
            ?>
          </span>
        </div>
      </div>
    </div>

    <?php if ($order['payment_method'] === 'bank_transfer'): ?>
    <!-- Bank transfer reminder -->
    <div style="background:#FFF3E0;border:1px solid #F5C584;border-radius:var(--border-radius-lg);padding:var(--space-6);margin-bottom:var(--space-6)">
      <h4 style="color:#7A4500;margin-bottom:var(--space-4)">⏰ Please Transfer Payment Within 24 Hours</h4>
      <?php
      $bankName = getSetting('bank_account_name', 'BraidedbyAGB');
      $bankSort = getSetting('bank_sort_code', '');
      $bankAcc  = getSetting('bank_account_number', '');
      $refCode  = strtoupper(explode(' ', $order['c_name'])[0]) . '-ORDER';
      ?>
      <div class="bank-detail-row"><span>Account Name:</span><strong><?= htmlspecialchars($bankName) ?></strong></div>
      <?php if ($bankSort): ?>
      <div class="bank-detail-row"><span>Sort Code:</span><strong><?= htmlspecialchars($bankSort) ?></strong></div>
      <?php endif; ?>
      <?php if ($bankAcc): ?>
      <div class="bank-detail-row"><span>Account Number:</span><strong><?= htmlspecialchars($bankAcc) ?></strong></div>
      <?php endif; ?>
      <div class="bank-detail-row"><span>Reference:</span><strong><?= htmlspecialchars($refCode) ?></strong></div>
      <div class="bank-detail-row" style="border-top:1px solid #F5C584;padding-top:var(--space-3);margin-top:var(--space-3)">
        <span>Amount:</span><strong style="color:var(--color-primary);font-size:var(--text-xl)">£<?= number_format((float)$order['total'], 2) ?></strong>
      </div>
    </div>
    <?php endif; ?>

    <!-- Delivery info -->
    <?php if ($order['delivery_type'] === 'local_pickup'): ?>
    <div style="background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--border-radius-lg);padding:var(--space-5);margin-bottom:var(--space-6);font-size:var(--text-sm)">
      <strong>📍 Local Pickup — Farnborough</strong><br>
      <span style="color:var(--color-text-muted)">We'll contact you via email or WhatsApp to arrange a convenient collection time once your order is confirmed.</span>
    </div>
    <?php elseif ($order['delivery_address']): ?>
    <div style="background:var(--color-white);border:1px solid var(--color-border);border-radius:var(--border-radius-lg);padding:var(--space-5);margin-bottom:var(--space-6);font-size:var(--text-sm)">
      <strong>🚚 Delivering to:</strong><br>
      <span style="color:var(--color-text-muted)"><?= htmlspecialchars($order['delivery_address']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div style="display:flex;gap:var(--space-4);flex-wrap:wrap">
      <a href="/shop" class="btn btn-primary flex-1">Continue Shopping</a>
      <a href="https://wa.me/447769064971" class="btn btn-outline-primary flex-1" target="_blank">
        💬 WhatsApp Support
      </a>
    </div>

    <p style="text-align:center;margin-top:var(--space-6);font-size:var(--text-sm);color:var(--color-text-muted)">
      A confirmation has been sent to <strong><?= htmlspecialchars($order['c_email']) ?></strong>
    </p>
  </div>
</main>
<style>
.bank-detail-row{display:flex;justify-content:space-between;padding:var(--space-2) 0;border-bottom:1px solid rgba(0,0,0,0.06);font-size:var(--text-sm);}
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
</body>
</html>
