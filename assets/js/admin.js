// ============================================================
// BraidedbyAGB — Admin JavaScript
// FILE: /assets/js/admin.js
// ============================================================

// ── Sidebar toggle (mobile) ───────────────────────────────
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar       = document.getElementById('adminSidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar()  { sidebar?.classList.add('open');    sidebarOverlay?.classList.add('open'); }
function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('open'); }

if (sidebarToggle && sidebar) {
  sidebarToggle.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  // Close via overlay tap
  sidebarOverlay?.addEventListener('click', closeSidebar);
  // Close when a nav link is tapped (navigating away)
  sidebar.querySelectorAll('.admin-nav-link').forEach(link => {
    link.addEventListener('click', closeSidebar);
  });
}

// ── Toast notifications ────────────────────────────────────
function showAdminToast(message, type = 'success') {
  const toast = document.getElementById('adminToast');
  if (!toast) return;
  toast.textContent = message;
  toast.className   = 'admin-toast ' + type + ' show';
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), 3500);
}

// Auto-show flash messages as toasts if present
document.addEventListener('DOMContentLoaded', () => {
  const flash = document.querySelector('[data-flash]');
  if (flash) {
    showAdminToast(flash.dataset.flash, flash.dataset.flashType || 'success');
  }
});

// ── Inline confirm for destructive actions ─────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-confirm]');
  if (btn) {
    if (!confirm(btn.dataset.confirm || 'Are you sure?')) {
      e.preventDefault();
      return false;
    }
  }
});

// ── Status update via AJAX (bookings/orders) ──────────────
async function updateStatus(endpoint, id, status, btn) {
  const original = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Updating...'; }
  try {
    const res  = await fetch('/api/admin-action', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_status', endpoint, id, status })
    });
    const data = await res.json();
    if (data.success) {
      showAdminToast('Status updated to: ' + status);
      setTimeout(() => location.reload(), 800);
    } else {
      showAdminToast(data.error || 'Update failed', 'error');
      if (btn) { btn.disabled = false; btn.textContent = original; }
    }
  } catch (e) {
    showAdminToast('Network error. Please try again.', 'error');
    if (btn) { btn.disabled = false; btn.textContent = original; }
  }
}

// ── Auto-dismiss alerts ────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 5000);
  });
});
