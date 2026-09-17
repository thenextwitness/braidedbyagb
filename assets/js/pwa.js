/* ============================================================
   BraidedbyAGB — PWA registration + install
   FILE: /assets/js/pwa.js  (loaded on every page via head-meta.php)

   Two ways to install:
     1. The browser's own prompt (Chrome/Edge/Android), surfaced as a small
        one-time banner.
     2. Any element marked [data-agb-install] (e.g. the "Install app" button on
        the account dashboard and in the footer) — works on every platform:
        it fires the native prompt where available, and shows Add-to-Home-Screen
        steps on iOS, which has no prompt API. These elements auto-hide once the
        app is installed.
   ============================================================ */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) return;

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* ignore */ });
  });

  var deferredPrompt = null;
  var DISMISS_KEY = 'agb_install_dismissed';

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }
  function isIOS() {
    var ua = window.navigator.userAgent;
    return /iphone|ipad|ipod/i.test(ua) ||
           (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }
  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function remember() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignore */ }
  }

  // ── Manual install entry points: [data-agb-install] ──────
  function refreshInstallEls() {
    var els = document.querySelectorAll('[data-agb-install]');
    // Hide the manual triggers once installed (nothing to do then).
    for (var i = 0; i < els.length; i++) els[i].hidden = isStandalone();
  }

  function showIosSheet() {
    if (document.getElementById('agb-ios-sheet')) return;
    var w = document.createElement('div');
    w.id = 'agb-ios-sheet';
    w.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(20,0,15,0.55);display:flex;align-items:flex-end;justify-content:center';
    w.innerHTML =
      '<div style="background:#fff;max-width:440px;width:100%;border-radius:16px 16px 0 0;padding:22px 20px 30px;font-family:\'Lato\',system-ui,sans-serif">' +
        '<div style="font-weight:800;color:#7A0050;font-size:1.05rem;margin-bottom:10px">Install BraidedbyAGB</div>' +
        '<ol style="margin:0 0 18px;padding-left:20px;color:#4A3A54;font-size:0.95rem;line-height:1.7">' +
          '<li>Tap the <strong>Share</strong> button in Safari&rsquo;s toolbar.</li>' +
          '<li>Choose <strong>Add to Home Screen</strong>.</li>' +
          '<li>Tap <strong>Add</strong> — done!</li>' +
        '</ol>' +
        '<button id="agb-ios-close" style="width:100%;cursor:pointer;border:none;border-radius:10px;padding:13px;font-weight:700;color:#fff;background:#CC1A8A;font-size:1rem">Got it</button>' +
      '</div>';
    document.body.appendChild(w);
    var close = function () { w.remove(); };
    w.addEventListener('click', function (e) { if (e.target === w) close(); });
    document.getElementById('agb-ios-close').addEventListener('click', close);
  }

  function triggerInstall() {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () { deferredPrompt = null; refreshInstallEls(); });
    } else if (isIOS()) {
      showIosSheet();
    } else {
      // Chrome without a pending prompt (already engaged/installed elsewhere, or
      // criteria not yet met): point at the browser's own install control.
      alert('To install, open your browser menu and choose “Install app” (or “Add to Home screen”).');
    }
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-agb-install]');
    if (!t) return;
    e.preventDefault();
    triggerInstall();
  });

  document.addEventListener('DOMContentLoaded', refreshInstallEls);
  if (document.readyState !== 'loading') refreshInstallEls();

  // ── Auto one-time banner (Chrome/Edge/Android only) ──────
  function showBanner() {
    if (dismissed() || isStandalone() || document.getElementById('agb-install-btn')) return;
    if (/^\/(login|verify|logout)(\/|$)/.test(location.pathname)) return;
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
      triggerInstall();
      remember();
    });
    document.getElementById('agb-install-no').addEventListener('click', function () {
      bar.remove();
      remember();
    });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    refreshInstallEls();
    setTimeout(showBanner, 1500);
  });

  window.addEventListener('appinstalled', function () {
    remember();
    deferredPrompt = null;
    var bar = document.getElementById('agb-install-btn');
    if (bar) bar.remove();
    refreshInstallEls();
  });
})();
