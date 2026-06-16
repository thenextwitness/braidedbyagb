<?php
// ============================================================
// BraidedbyAGB — Cart Page
// FILE: /public/cart.php
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Cart — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/../includes/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/pages.css">
  <link rel="stylesheet" href="/assets/css/shop.css">
<?php include __DIR__ . '/../includes/gtag.php'; ?>
</head>
<body>
<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="page-content" style="background:var(--color-bg-light);min-height:100vh">
  <div class="container" style="padding:var(--space-12) var(--space-6)">

    <h1 style="font-size:var(--text-3xl);color:var(--color-deep-purple);margin-bottom:var(--space-8)">Your Cart</h1>

    <!-- Empty state (shown when JS cart is empty) -->
    <div id="cart-empty" style="display:none;text-align:center;padding:var(--space-16) 0">
      <p style="font-size:3rem;margin-bottom:var(--space-4)">🛍️</p>
      <h3 style="color:var(--color-deep-purple);margin-bottom:var(--space-3)">Your cart is empty</h3>
      <p style="color:var(--color-text-muted);margin-bottom:var(--space-6)">Browse our hair extensions and products.</p>
      <a href="/shop" class="btn btn-primary">Shop Extensions</a>
    </div>

    <!-- Cart content -->
    <div id="cart-content" style="display:none">
      <div class="cart-layout">

        <!-- Cart items -->
        <div class="cart-items-col">
          <div class="cart-items-list" id="cart-items-list"></div>

          <!-- Continue shopping -->
          <div style="margin-top:var(--space-6)">
            <a href="/shop" class="btn btn-outline-primary">← Continue Shopping</a>
          </div>
        </div>

        <!-- Order summary -->
        <div class="cart-summary-col">
          <div class="booking-summary">
            <div class="booking-summary-header">Order Summary</div>
            <div class="booking-summary-body">

              <!-- Discount code -->
              <div style="margin-bottom:var(--space-5)">
                <label class="form-label">Discount Code</label>
                <div style="display:flex;gap:var(--space-3);margin-top:var(--space-2)">
                  <input type="text" id="discount-code-input" class="form-control"
                         placeholder="Enter code" style="flex:1;text-transform:uppercase">
                  <button class="btn btn-outline-primary" onclick="applyDiscount()">Apply</button>
                </div>
                <p id="discount-message" style="font-size:var(--text-sm);margin-top:var(--space-2);display:none"></p>
              </div>

              <div class="summary-row">
                <span class="label">Subtotal</span>
                <span class="value" id="cart-subtotal">£0.00</span>
              </div>
              <div class="summary-row" id="discount-row" style="display:none">
                <span class="label" style="color:var(--color-success)">Discount</span>
                <span class="value" style="color:var(--color-success)" id="cart-discount">−£0.00</span>
              </div>
              <div class="summary-row">
                <span class="label">Delivery</span>
                <span class="value" id="cart-delivery">Calculated at checkout</span>
              </div>
              <div class="summary-row total">
                <span class="label">Total</span>
                <span class="value" id="cart-total">£0.00</span>
              </div>

              <a href="/checkout" class="btn btn-gold btn-lg w-full" style="margin-top:var(--space-5);justify-content:center"
                 id="checkout-btn">
                Proceed to Checkout →
              </a>

              <div style="margin-top:var(--space-4)">
                <div class="trust-item"><span>🔒</span><span>Secure checkout via Stripe</span></div>
                <div class="trust-item"><span>📍</span><span>Free local pickup available</span></div>
                <div class="trust-item"><span>❌</span><span>No returns on opened products</span></div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/main.js"></script>
<script src="/assets/js/cart.js"></script>
<script>
let appliedDiscount = null;

function renderCart() {
  const items   = Cart.get();
  const isEmpty = items.length === 0;
  document.getElementById('cart-empty').style.display   = isEmpty ? 'block' : 'none';
  document.getElementById('cart-content').style.display = isEmpty ? 'none'  : 'block';
  if (isEmpty) return;

  const list = document.getElementById('cart-items-list');
  list.innerHTML = '';
  let subtotal = 0;

  items.forEach(item => {
    const lineTotal = item.price * item.quantity;
    subtotal += lineTotal;
    const div = document.createElement('div');
    div.className = 'cart-item';
    div.innerHTML = `
      <div class="cart-item-info">
        <p class="cart-item-name">${item.name}</p>
        <p class="cart-item-unit">£${parseFloat(item.price).toFixed(2)} each</p>
      </div>
      <div class="cart-item-controls">
        <div class="qty-control">
          <button class="qty-btn" onclick="updateCartQty('${item.key}', ${item.quantity - 1})">−</button>
          <span class="qty-display">${item.quantity}</span>
          <button class="qty-btn" onclick="updateCartQty('${item.key}', ${item.quantity + 1})">+</button>
        </div>
        <span class="cart-item-total">£${lineTotal.toFixed(2)}</span>
        <button class="cart-remove-btn" onclick="removeFromCart('${item.key}')" aria-label="Remove">✕</button>
      </div>`;
    list.appendChild(div);
  });

  document.getElementById('cart-subtotal').textContent = '£' + subtotal.toFixed(2);

  let total = subtotal;
  if (appliedDiscount) {
    const discountAmt = appliedDiscount.type === 'percent'
      ? subtotal * (appliedDiscount.value / 100)
      : Math.min(appliedDiscount.value, subtotal);
    total -= discountAmt;
    document.getElementById('cart-discount').textContent = '−£' + discountAmt.toFixed(2);
    document.getElementById('discount-row').style.display = '';
  } else {
    document.getElementById('discount-row').style.display = 'none';
  }

  document.getElementById('cart-total').textContent = '£' + Math.max(0, total).toFixed(2);

  // Store for checkout
  sessionStorage.setItem('agb_discount', appliedDiscount ? JSON.stringify(appliedDiscount) : '');
}

function updateCartQty(key, qty) {
  if (qty < 1) { removeFromCart(key); return; }
  Cart.updateQuantity(key, qty);
  renderCart();
}
function removeFromCart(key) {
  Cart.remove(key);
  renderCart();
  showToast('Item removed from cart', 'default');
}

async function applyDiscount() {
  const code = document.getElementById('discount-code-input').value.trim().toUpperCase();
  const msg  = document.getElementById('discount-message');
  if (!code) return;
  msg.style.display = 'none';

  try {
    const res  = await fetch(`/api/validate-discount?code=${encodeURIComponent(code)}`);
    const data = await res.json();
    if (data.valid) {
      appliedDiscount = data;
      msg.textContent = `✓ "${code}" applied — ${data.type === 'percent' ? data.value + '% off' : '£' + data.value + ' off'}`;
      msg.style.color = 'var(--color-success)';
      msg.style.display = 'block';
      renderCart();
      showToast('Discount code applied! 🎉', 'success');
    } else {
      msg.textContent = data.error || 'Invalid or expired code.';
      msg.style.color = 'var(--color-error)';
      msg.style.display = 'block';
    }
  } catch(e) {
    msg.textContent = 'Could not validate code. Please try again.';
    msg.style.color = 'var(--color-error)';
    msg.style.display = 'block';
  }
}

document.addEventListener('DOMContentLoaded', renderCart);
</script>
</body>
</html>
