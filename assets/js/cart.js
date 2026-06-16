/* ============================================================
   BraidedbyAGB — Cart JavaScript
   FILE: /assets/js/cart.js
   ============================================================ */

const Cart = {

  get() {
    try {
      return JSON.parse(localStorage.getItem('agb_cart') || '[]');
    } catch { return []; }
  },

  save(items) {
    localStorage.setItem('agb_cart', JSON.stringify(items));
    this.updateBadge();
  },

  add(productId, variantId, name, price, quantity = 1) {
    const items = this.get();
    const key   = `${productId}-${variantId || 0}`;
    const existing = items.find(i => i.key === key);
    if (existing) {
      existing.quantity += quantity;
    } else {
      items.push({ key, productId, variantId, name, price, quantity });
    }
    this.save(items);
    if (typeof showToast === 'function') {
      showToast(`"${name}" added to cart 🛍️`, 'success');
    }
    return items;
  },

  remove(key) {
    const items = this.get().filter(i => i.key !== key);
    this.save(items);
    return items;
  },

  updateQuantity(key, qty) {
    const items = this.get();
    const item  = items.find(i => i.key === key);
    if (item) {
      item.quantity = Math.max(1, qty);
      this.save(items);
    }
    return items;
  },

  clear() {
    this.save([]);
  },

  count() {
    return this.get().reduce((sum, i) => sum + i.quantity, 0);
  },

  total() {
    return this.get().reduce((sum, i) => sum + (i.price * i.quantity), 0);
  },

  updateBadge() {
    const badges = document.querySelectorAll('.cart-badge');
    const count  = this.count();
    badges.forEach(b => {
      b.textContent = count;
      b.style.display = count > 0 ? 'flex' : 'none';
    });
  },

  init() {
    this.updateBadge();

    // Add to cart buttons
    document.addEventListener('click', e => {
      const btn = e.target.closest('.add-to-cart-btn');
      if (!btn) return;
      e.preventDefault();
      const productId  = btn.dataset.productId;
      const variantId  = btn.dataset.variantId || null;
      const name       = btn.dataset.productName || 'Product';
      const price      = parseFloat(btn.dataset.productPrice || 0);
      if (!productId || !price) {
        if (typeof showToast === 'function') showToast('Please select options first', 'error');
        return;
      }
      Cart.add(productId, variantId, name, price);
      btn.textContent = 'Added ✓';
      btn.disabled = true;
      setTimeout(() => {
        btn.textContent = 'Add to Cart';
        btn.disabled = false;
      }, 2000);
    });
  }
};

document.addEventListener('DOMContentLoaded', () => Cart.init());
