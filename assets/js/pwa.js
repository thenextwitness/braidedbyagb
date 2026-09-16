/* ============================================================
   BraidedbyAGB — PWA registration + install prompt
   FILE: /assets/js/pwa.js  (loaded on every page via head-meta.php)
   ============================================================ */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) return;

  // Register the worker at root scope. Failure is silent — the site works
  // exactly the same without it.
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* ignore */ });
  });

  // ── Install prompt ──────────────────────────────────────
  // Chrome/Edge/Android fire beforeinstallprompt; we defer it and offer our own
  // button. iOS Safari has no such event (users add to Home Screen manually), so
  // nothing shows there — by design.
  var deferredPrompt = null;
  var DISMISS_KEY = 'agb_install_dismissed';

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function remember() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignore */ }
  }

  function showButton() {
    if (dismissed() || document.getElementById('agb-install-btn')) return;
    var bar = document.createElement('div');
    bar.id = 'agb-install-btn';
    bar.setAttribute('role', 'dialog');
    bar.setAttribute('aria-label', 'Install the BraidedbyAGB app');
    bar.style.cssText = [
      'position:fixed', 'left:16px', 'right:16px', 'bottom:16px', 'z-index:9999',
      'max-width:420px', 'margin:0 auto', 'display:flex', 'align-items:center', 'gap:12px',
      'background:#fff', 'border:1px solid #EADCF0', 'border-radius:14px',
      'box-shadow:0 12px 40px rgba(122,0,80,0.18)', 'padding:12px 14px',
      "font-family:'Lato',system-ui,sans-serif"
    ].join(';');
    bar.innerHTML =
      '<div style="flex:1;min-width:0">' +
        '<div style="font-weight:800;color:#7A0050;font-size:0.95rem">Install BraidedbyAGB</div>' +
        '<div style="color:#6B5575;font-size:0.8rem">Quick access to booking &amp; your account.</div>' +
      '</div>' +
      '<button id="agb-install-yes" style="cursor:pointer;border:none;border-radius:9px;padding:10px 16px;font-weight:700;color:#fff;background:#CC1A8A;font-size:0.9rem">Install</button>' +
      '<button id="agb-install-no" aria-label="Dismiss" style="cursor:pointer;border:none;background:none;color:#9B8BA5;font-size:1.4rem;line-height:1;padding:0 4px">&times;</button>';
    document.body.appendChild(bar);

    document.getElementById('agb-install-yes').addEventListener('click', function () {
      bar.remove();
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () { deferredPrompt = null; remember(); });
    });
    document.getElementById('agb-install-no').addEventListener('click', function () {
      bar.remove();
      remember();
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    // Don't nag: only surface after a moment, and never on the sign-in screens.
    if (/^\/(login|verify|logout)(\/|$)/.test(location.pathname)) return;
    setTimeout(showButton, 1500);
  });

  window.addEventListener('appinstalled', function () {
    remember();
    var bar = document.getElementById('agb-install-btn');
    if (bar) bar.remove();
  });
})();
