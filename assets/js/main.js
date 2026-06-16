/* ============================================================
   BraidedbyAGB — Main JavaScript
   FILE: /assets/js/main.js
   ============================================================ */

document.addEventListener('DOMContentLoaded', function () {

  // ── Header — static, no scroll effect ──────────────────

  // ── Mobile navigation ─────────────────────────────────────
  const toggle = document.getElementById('mobileToggle');
  const mobileNav = document.getElementById('mobileNav');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', () => {
      const isOpen = toggle.classList.toggle('open');
      mobileNav.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen);
      mobileNav.setAttribute('aria-hidden', !isOpen);
    });
    // Close on link click
    mobileNav.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        toggle.classList.remove('open');
        mobileNav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        mobileNav.setAttribute('aria-hidden', 'true');
      });
    });
  }

  // ── Scroll animations removed — content always visible ──

  // ── Toast notification ────────────────────────────────────
  window.showToast = function(message, type = 'default') {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'toast show ' + type;
    setTimeout(() => toast.classList.remove('show'), 3500);
  };

  // ── Booking: price auto-calculate ────────────────────────
  const variantSelect = document.getElementById('service-variant');
  const addonCheckboxes = document.querySelectorAll('.addon-checkbox');
  const totalDisplay = document.getElementById('booking-total');
  const depositDisplay = document.getElementById('booking-deposit');
  const balanceDisplay = document.getElementById('booking-balance');

  function updateBookingTotal() {
    if (!variantSelect || !totalDisplay) return;
    const selectedOption = variantSelect.options[variantSelect.selectedIndex];
    let total = parseFloat(selectedOption?.dataset.price || 0);

    addonCheckboxes.forEach(cb => {
      if (cb.checked) total += parseFloat(cb.dataset.price || 0);
    });

    const deposit = Math.round(total * 0.30 * 100) / 100;
    const balance = Math.round((total - deposit) * 100) / 100;

    if (totalDisplay)   totalDisplay.textContent = '£' + total.toFixed(2);
    if (depositDisplay) depositDisplay.textContent = '£' + deposit.toFixed(2);
    if (balanceDisplay) balanceDisplay.textContent = '£' + balance.toFixed(2);
  }

  if (variantSelect) {
    variantSelect.addEventListener('change', updateBookingTotal);
    updateBookingTotal();
  }
  addonCheckboxes.forEach(cb => cb.addEventListener('change', updateBookingTotal));

  // ── Booking: calendar slot selection ─────────────────────
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('time-slot') && !e.target.classList.contains('slot-blocked')) {
      document.querySelectorAll('.time-slot.selected').forEach(s => s.classList.remove('selected'));
      e.target.classList.add('selected');
      const timeInput = document.getElementById('booking-time');
      if (timeInput) timeInput.value = e.target.dataset.time;
    }
  });

  // ── Star rating picker (review form) ─────────────────────
  const starPicker = document.querySelector('.star-picker');
  if (starPicker) {
    const stars = starPicker.querySelectorAll('.star-pick');
    const ratingInput = document.getElementById('rating-input');

    stars.forEach((star, i) => {
      star.addEventListener('mouseover', () => {
        stars.forEach((s, j) => s.classList.toggle('hovered', j <= i));
      });
      star.addEventListener('mouseleave', () => {
        stars.forEach(s => s.classList.remove('hovered'));
      });
      star.addEventListener('click', () => {
        const val = i + 1;
        stars.forEach((s, j) => s.classList.toggle('selected', j < val));
        if (ratingInput) ratingInput.value = val;
      });
    });
    starPicker.addEventListener('mouseleave', () => {
      stars.forEach(s => s.classList.remove('hovered'));
    });
  }

  // ── Photo preview (review form) ───────────────────────────
  const photoInput = document.getElementById('review-photo');
  const photoPreview = document.getElementById('photo-preview');
  if (photoInput && photoPreview) {
    photoInput.addEventListener('change', function() {
      const file = this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = e => {
          photoPreview.src = e.target.result;
          photoPreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
      }
    });
  }

  // ── Smooth scroll for anchor links ───────────────────────
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function(e) {
      const target = document.querySelector(this.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // ── Form validation helper ────────────────────────────────
  window.validateForm = function(formEl) {
    let valid = true;
    formEl.querySelectorAll('[required]').forEach(field => {
      const group = field.closest('.form-group');
      const error = group?.querySelector('.field-error');
      if (!field.value.trim()) {
        field.classList.add('invalid');
        if (error) error.style.display = 'block';
        valid = false;
      } else {
        field.classList.remove('invalid');
        if (error) error.style.display = 'none';
      }
    });
    // Email validation
    formEl.querySelectorAll('[type="email"]').forEach(field => {
      if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
        field.classList.add('invalid');
        valid = false;
      }
    });
    return valid;
  };

  // ── Policy checkbox gating ────────────────────────────────
  const policyCheckbox = document.getElementById('policy-checkbox');
  const submitBtn = document.getElementById('booking-submit');
  if (policyCheckbox && submitBtn) {
    const toggle = () => {
      submitBtn.disabled = !policyCheckbox.checked;
      submitBtn.style.opacity = policyCheckbox.checked ? '1' : '0.5';
    };
    policyCheckbox.addEventListener('change', toggle);
    toggle();
  }

});
