<?php
// ============================================================
// BraidedbyAGB — Checkout Page
// FILE: /public/checkout.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
$stripePublicKey = STRIPE_PUBLIC_KEY;
$bankName = getSetting('bank_account_name', 'BraidedbyAGB');
$bankSort = getSetting('bank_sort_code', '');
$bankAcc  = getSetting('bank_account_number', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <link rel="stylesheet" href="/assets/css/shop.css">
  <script src="https://js.stripe.com/v3/"></script>
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="page-content" style="background:var(--color-bg-light);min-height:100vh">
  <div class="container" style="padding:var(--space-12) var(--space-6)">
    <h1 style="font-size:var(--text-3xl);color:var(--color-deep-purple);margin-bottom:var(--space-8)">Checkout</h1>

    <div class="checkout-layout">

      <!-- Left: Details & Payment -->
      <div class="checkout-main">

        <!-- Contact Details -->
        <div class="booking-step active">
          <div class="booking-step-header">
            <span class="step-number">1</span>
            <h2 class="step-title">Your Details</h2>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full Name *</label>
              <input class="form-control" type="text" id="co-name" placeholder="Full name" required>
            </div>
            <div class="form-group">
              <label class="form-label">Email Address *</label>
              <input class="form-control" type="email" id="co-email" placeholder="your@email.com" required>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Phone Number *</label>
            <input class="form-control" type="tel" id="co-phone" placeholder="07700 000000" required>
          </div>
        </div>

        <!-- Delivery Method -->
        <div class="booking-step active" style="margin-top:var(--space-5)">
          <div class="booking-step-header">
            <span class="step-number">2</span>
            <h2 class="step-title">Delivery Method</h2>
          </div>

          <div class="delivery-options">
            <label class="delivery-option selected" id="opt-shipping">
              <input type="radio" name="delivery" value="shipping" checked onchange="selectDelivery('shipping')">
              <div class="delivery-option-body">
                <div class="delivery-option-title">🚚 UK Standard Delivery</div>
                <div class="delivery-option-desc">2–5 working days · Shipping cost calculated below</div>
              </div>
            </label>
            <label class="delivery-option" id="opt-pickup">
              <input type="radio" name="delivery" value="local_pickup" onchange="selectDelivery('local_pickup')">
              <div class="delivery-option-body">
                <div class="delivery-option-title">📍 Free Local Pickup — Farnborough</div>
                <div class="delivery-option-desc">Collect from our studio · Address provided after order</div>
              </div>
            </label>
          </div>

          <!-- Shipping address (shown for delivery) -->
          <div id="shipping-address-section" style="margin-top:var(--space-5)">
            <h4 style="color:var(--color-deep-purple);margin-bottom:var(--space-4)">Delivery Address</h4>
            <div class="form-group">
              <label class="form-label">Address Line 1 *</label>
              <input class="form-control" type="text" id="co-addr1" placeholder="House number and street" required>
            </div>
            <div class="form-group">
              <label class="form-label">Address Line 2</label>
              <input class="form-control" type="text" id="co-addr2" placeholder="Flat, unit, etc. (optional)">
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Town / City *</label>
                <input class="form-control" type="text" id="co-city" placeholder="City" required>
              </div>
              <div class="form-group">
                <label class="form-label">Postcode *</label>
                <input class="form-control" type="text" id="co-postcode" placeholder="GU11 1AA" required>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment -->
        <div class="booking-step active" style="margin-top:var(--space-5)">
          <div class="booking-step-header">
            <span class="step-number">3</span>
            <h2 class="step-title">Payment</h2>
          </div>

          <!-- Refund policy notice -->
          <div style="background:#FFF8E0;border:1px solid #F5D584;border-left:4px solid var(--color-gold);padding:var(--space-4);border-radius:0 var(--border-radius-lg) var(--border-radius-lg) 0;font-size:var(--text-sm);color:#5A4A00;margin-bottom:var(--space-5)">
            ❌ <strong>No refunds on opened hair products.</strong> Unopened items may be returned within 14 days. <a href="/policies#refund" style="color:var(--color-primary)">See full policy.</a>
          </div>

          <div class="payment-tabs">
            <button class="payment-tab active" onclick="selectPaymentMethod('stripe', this)">💳 Pay by Card</button>
            <button class="payment-tab" onclick="selectPaymentMethod('bank_transfer', this)">🏦 Bank Transfer</button>
          </div>

          <div id="stripe-section">
            <div class="stripe-card-wrap">
              <label class="form-label">Card Details</label>
              <div id="stripe-card-element" class="stripe-card-element"></div>
              <div id="stripe-card-errors" class="stripe-error" role="alert"></div>
            </div>
            <button class="btn btn-gold btn-lg w-full" id="stripe-pay-btn" disabled onclick="submitStripePayment()">
              Pay — <span id="stripe-total-label">£0.00</span>
            </button>
          </div>

          <div id="bank-transfer-section" style="display:none">
            <div class="bank-transfer-box" id="bank-details-box">
              <h4 style="color:var(--color-deep-purple);margin-bottom:var(--space-4)">🏦 Bank Transfer Details</h4>
              <div class="bank-detail-row"><span>Account Name:</span><strong><?= htmlspecialchars($bankName) ?></strong></div>
              <?php if ($bankSort): ?>
              <div class="bank-detail-row"><span>Sort Code:</span><strong><?= htmlspecialchars($bankSort) ?></strong></div>
              <?php endif; ?>
              <?php if ($bankAcc): ?>
              <div class="bank-detail-row"><span>Account Number:</span><strong><?= htmlspecialchars($bankAcc) ?></strong></div>
              <?php endif; ?>
              <div class="bank-detail-row"><span>Reference:</span><strong id="bank-ref-label">Your name + ORDER</strong></div>
              <div class="bank-detail-row total"><span>Total to Transfer:</span><strong style="color:var(--color-primary)" id="bank-total-label">£0.00</strong></div>
              <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-top:var(--space-4)">
                ⚠️ Your order will be held for 24 hours. If payment is not received, the order will be cancelled automatically.
              </p>
            </div>
            <div class="policy-checkbox" style="margin:var(--space-4) 0">
              <input type="checkbox" id="bank-confirm-checkout">
              <label for="bank-confirm-checkout">
                I will transfer <strong id="bank-confirm-amt">£0.00</strong> within 24 hours using the reference above.
              </label>
            </div>
            <button class="btn btn-primary btn-lg w-full" id="bank-submit-btn" disabled onclick="submitBankTransfer()">
              Confirm Order by Bank Transfer
            </button>
          </div>
        </div>

      </div>

      <!-- Right: Order Summary -->
      <div class="cart-summary-col" style="position:sticky;top:100px;height:fit-content">

        <!-- Discount code -->
        <div class="booking-summary" style="margin-bottom:var(--space-4)">
          <div class="booking-summary-header">Discount Code</div>
          <div class="booking-summary-body">
            <div style="display:flex;gap:var(--space-3)">
              <input type="text" id="discount-code-input" class="form-control"
                     placeholder="Enter code" style="flex:1;text-transform:uppercase">
              <button type="button" class="btn btn-outline-primary" onclick="applyDiscount()">Apply</button>
            </div>
            <p id="discount-message" style="font-size:var(--text-sm);margin-top:var(--space-2);display:none"></p>
          </div>
        </div>

        <div class="booking-summary">
          <div class="booking-summary-header">Order Summary</div>
          <div class="booking-summary-body" id="checkout-order-summary">
            <p style="color:var(--color-text-muted);font-size:var(--text-sm)">Loading cart...</p>
          </div>
        </div>
        <div style="margin-top:var(--space-4)">
          <div class="trust-item"><span>🔒</span><span>256-bit SSL secure checkout</span></div>
          <div class="trust-item"><span>📦</span><span>Orders dispatched within 1–2 days</span></div>
          <div class="trust-item"><span>💬</span><span>Questions? WhatsApp 07769 064 971</span></div>
        </div>
      </div>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script src="/assets/js/cart.js"></script>
<script>
const STRIPE_KEY = '<?= $stripePublicKey ?>';
let stripe, cardElement, paymentMethod = 'stripe', deliveryMethod = 'shipping';
let orderTotal = 0;
let appliedDiscount = null;

// Load discount from cart page
try {
  const d = sessionStorage.getItem('agb_discount');
  if (d) appliedDiscount = JSON.parse(d);
} catch(e){}

function buildOrderSummary() {
  const items = Cart.get();
  let subtotal = items.reduce((s, i) => s + i.price * i.quantity, 0);
  let discountAmt = 0;
  if (appliedDiscount) {
    discountAmt = appliedDiscount.type === 'percent'
      ? subtotal * (appliedDiscount.value / 100)
      : Math.min(appliedDiscount.value, subtotal);
  }
  const shipping = deliveryMethod === 'shipping' ? (subtotal >= 50 ? 0 : 3.99) : 0;
  orderTotal = Math.max(0, subtotal - discountAmt) + shipping;

  let rows = items.map(i =>
    `<div class="summary-row"><span class="label">${i.name} × ${i.quantity}</span><span class="value">£${(i.price * i.quantity).toFixed(2)}</span></div>`
  ).join('');

  if (discountAmt > 0) rows += `<div class="summary-row"><span class="label" style="color:var(--color-success)">Discount</span><span class="value" style="color:var(--color-success)">−£${discountAmt.toFixed(2)}</span></div>`;
  rows += `<div class="summary-row"><span class="label">Delivery</span><span class="value">${shipping === 0 ? 'Free' : '£' + shipping.toFixed(2)}</span></div>`;
  rows += `<div class="summary-row total"><span class="label">Total</span><span class="value">£${orderTotal.toFixed(2)}</span></div>`;

  document.getElementById('checkout-order-summary').innerHTML = rows;
  document.getElementById('stripe-total-label').textContent   = '£' + orderTotal.toFixed(2);
  document.getElementById('bank-total-label').textContent     = '£' + orderTotal.toFixed(2);
  document.getElementById('bank-confirm-amt').textContent     = '£' + orderTotal.toFixed(2);

  const name = document.getElementById('co-name')?.value || 'YOUR NAME';
  document.getElementById('bank-ref-label').textContent = name.split(' ')[0].toUpperCase() + '-ORDER';
}

async function applyDiscount() {
  const input = document.getElementById('discount-code-input');
  const msg   = document.getElementById('discount-message');
  const code  = input.value.trim().toUpperCase();
  msg.style.display = 'block';
  if (!code) {
    appliedDiscount = null;
    sessionStorage.removeItem('agb_discount');
    msg.style.color = 'var(--color-text-muted)';
    msg.textContent = 'Discount removed.';
    buildOrderSummary();
    return;
  }
  msg.style.color = 'var(--color-text-muted)';
  msg.textContent = 'Checking…';
  try {
    const res  = await fetch(`/api/validate-discount?code=${encodeURIComponent(code)}`);
    const data = await res.json();
    if (data.valid) {
      appliedDiscount = data;
      sessionStorage.setItem('agb_discount', JSON.stringify(appliedDiscount));
      msg.style.color = 'var(--color-success)';
      msg.textContent = `✓ "${code}" applied — ${data.type === 'percent' ? data.value + '% off' : '£' + data.value + ' off'}`;
      buildOrderSummary();
      if (typeof showToast === 'function') showToast('Discount code applied! 🎉', 'success');
    } else {
      appliedDiscount = null;
      sessionStorage.removeItem('agb_discount');
      msg.style.color = '#c0392b';
      msg.textContent = data.error || 'Invalid or expired code.';
      buildOrderSummary();
    }
  } catch (e) {
    msg.style.color = '#c0392b';
    msg.textContent = 'Could not validate code. Please try again.';
  }
}

function selectDelivery(method) {
  deliveryMethod = method;
  document.getElementById('opt-shipping').classList.toggle('selected', method === 'shipping');
  document.getElementById('opt-pickup').classList.toggle('selected',   method === 'local_pickup');
  document.getElementById('shipping-address-section').style.display = method === 'shipping' ? 'block' : 'none';
  buildOrderSummary();
}

function selectPaymentMethod(method, btn) {
  paymentMethod = method;
  document.querySelectorAll('.payment-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('stripe-section').style.display        = method === 'stripe'        ? 'block' : 'none';
  document.getElementById('bank-transfer-section').style.display = method === 'bank_transfer' ? 'block' : 'none';
}

// Init Stripe
window.addEventListener('DOMContentLoaded', () => {
  buildOrderSummary();
  // Reflect a code already applied on the cart page
  if (appliedDiscount && appliedDiscount.code) {
    const input = document.getElementById('discount-code-input');
    if (input) input.value = appliedDiscount.code;
    const msg = document.getElementById('discount-message');
    if (msg) {
      msg.style.display = 'block';
      msg.style.color = 'var(--color-success)';
      msg.textContent = `✓ "${appliedDiscount.code}" applied`;
    }
  }
  stripe = Stripe(STRIPE_KEY);
  const elements = stripe.elements({ locale: 'en-GB' });
  cardElement = elements.create('card', {
    style: {
      base: { fontFamily: 'Lato, sans-serif', fontSize: '16px', color: '#1A0014', '::placeholder': { color: '#9B8BA5' } }
    }
  });
  cardElement.mount('#stripe-card-element');
  cardElement.on('change', e => {
    document.getElementById('stripe-card-errors').textContent = e.error ? e.error.message : '';
    document.getElementById('stripe-pay-btn').disabled = !!(e.error) || !e.complete;
  });
  document.getElementById('bank-confirm-checkout')?.addEventListener('change', function() {
    document.getElementById('bank-submit-btn').disabled = !this.checked;
  });
  document.getElementById('co-name')?.addEventListener('input', buildOrderSummary);
});

function validateDetails() {
  const name  = document.getElementById('co-name').value.trim();
  const email = document.getElementById('co-email').value.trim();
  const phone = document.getElementById('co-phone').value.trim();
  if (!name || !email || !phone) { showToast('Please fill in your contact details.', 'error'); return null; }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showToast('Please enter a valid email.', 'error'); return null; }
  if (deliveryMethod === 'shipping') {
    const addr1 = document.getElementById('co-addr1').value.trim();
    const city  = document.getElementById('co-city').value.trim();
    const post  = document.getElementById('co-postcode').value.trim();
    if (!addr1 || !city || !post) { showToast('Please enter your delivery address.', 'error'); return null; }
  }
  return { name, email, phone };
}

async function submitStripePayment() {
  const details = validateDetails(); if (!details) return;
  const btn = document.getElementById('stripe-pay-btn');
  btn.disabled = true; btn.textContent = 'Processing...';
  try {
    const intentRes = await fetch('/api/create-order-payment-intent', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: Math.round(orderTotal * 100) })
    });
    const intentData = await intentRes.json();
    if (intentData.error) throw new Error(intentData.error);
    const result = await stripe.confirmCardPayment(intentData.client_secret, {
      payment_method: { card: cardElement }
    });
    if (result.error) {
      document.getElementById('stripe-card-errors').textContent = result.error.message;
      btn.disabled = false; btn.innerHTML = 'Pay — £' + orderTotal.toFixed(2);
    } else if (result.paymentIntent.status === 'succeeded') {
      await confirmOrder('stripe', result.paymentIntent.id, details);
    }
  } catch(e) {
    showToast(e.message || 'Payment failed.', 'error');
    btn.disabled = false; btn.innerHTML = 'Pay — £' + orderTotal.toFixed(2);
  }
}

async function submitBankTransfer() {
  const details = validateDetails(); if (!details) return;
  document.getElementById('bank-submit-btn').disabled = true;
  document.getElementById('bank-submit-btn').textContent = 'Confirming...';
  await confirmOrder('bank_transfer', null, details);
}

async function confirmOrder(method, stripeId, details) {
  const addr = deliveryMethod === 'shipping'
    ? [document.getElementById('co-addr1').value, document.getElementById('co-addr2').value,
       document.getElementById('co-city').value, document.getElementById('co-postcode').value].filter(Boolean).join(', ')
    : null;

  const res = await fetch('/api/confirm-order', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      items:            Cart.get(),
      name:             details.name,
      email:            details.email,
      phone:            details.phone,
      delivery_type:    deliveryMethod,
      delivery_address: addr,
      payment_method:   method,
      stripe_payment_id: stripeId,
      discount_code:    appliedDiscount?.code || null,
      total:            orderTotal,
    })
  });
  const data = await res.json();
  if (data.error)  { showToast(data.error, 'error'); return; }
  if (data.ref) {
    Cart.clear();
    sessionStorage.removeItem('agb_discount');
    window.location.href = '/order-confirmation?ref=' + data.ref;
  }
}
</script>
</body>
</html>
