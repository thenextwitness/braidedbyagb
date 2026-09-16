<?php
// ============================================================
// BraidedbyAGB — Service worker (served by PHP, routed to /sw.js)
// FILE: /public/sw.php  →  /sw.js  (via .htaccess)
//
// Served from PHP (not a static root sw.js) so it deploys with the app and can
// carry a server-side kill switch. Routed to the ROOT url /sw.js and sent with
// Service-Worker-Allowed: / so it can control the whole origin.
//
// DELIBERATELY CONSERVATIVE v1: it never caches HTML or any authenticated or
// API response — the only thing it stores is the offline fallback page. So it
// cannot serve one visitor another's account content, and a bad deploy cannot
// pin stale pages. Navigations are network-first, falling back to /offline only
// when the network is unreachable.
//
// Kill switch: set the `pwa_sw_enabled` setting to 0 and every installed client
// unregisters itself and clears its caches on next load — no redeploy needed.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Default to enabled if the setting can't be read — the worker needs no DB
// itself, so a transient DB issue must never leave /sw.js empty.
try { $enabled = getSetting('pwa_sw_enabled', '1') === '1'; }
catch (Throwable $e) { $enabled = true; }
// Bump to invalidate the precache after changing what is cached below.
$version = 'agb-v1';

if (!$enabled):
    // Kill switch — self-destruct: drop caches, unregister, reload open pages.
    ?>
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    try {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
      await self.registration.unregister();
      const clients = await self.clients.matchAll({ type: 'window' });
      clients.forEach((c) => c.navigate(c.url));
    } catch (e) { /* best effort */ }
  })());
});
<?php else: ?>
const VERSION = '<?= $version ?>';
const PRECACHE = 'agb-precache-' + VERSION;
const OFFLINE_URL = '/offline';

// Areas the worker must NEVER touch — always straight to the network. Private
// (per-user) and dynamic surfaces: account, stylist portal, the dispatcher,
// the APIs, and the whole admin panel.
const BYPASS = /^\/(account|portal|app|api|admin)(\/|$)/;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PRECACHE)
      .then((cache) => cache.add(new Request(OFFLINE_URL, { cache: 'reload' })))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== PRECACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  // The page asks the worker to clear everything (e.g. on sign-out, shared device).
  if (event.data === 'agb-purge') {
    caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k))));
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) return;   // never touch cross-origin
  if (BYPASS.test(url.pathname)) return;             // private/dynamic → network only

  // Page navigations: network-first, fall back to the offline page when offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(OFFLINE_URL))
    );
    return;
  }
  // Everything else (CSS/JS/images): plain network. No HTML/asset caching in v1.
});
<?php endif; ?>
