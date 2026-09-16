<?php
// ============================================================
// BraidedbyAGB — Offline fallback (served by the service worker)
// FILE: /public/offline.php  →  /offline
// Self-contained: all CSS is inline so it renders with no network, since the
// worker precaches only this page (not the stylesheets).
// ============================================================
header('Cache-Control: no-cache');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#CC1A8A">
  <title>You're offline — BraidedbyAGB</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      font-family: 'Lato', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: linear-gradient(160deg, #7A0050, #2A0020); color: #fff; padding: 24px;
    }
    .card { max-width: 420px; text-align: center; }
    .mark {
      width: 76px; height: 76px; border-radius: 50%; margin: 0 auto 22px;
      display: flex; align-items: center; justify-content: center; font-size: 2.2rem;
      background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25);
    }
    h1 { font-size: 1.6rem; margin: 0 0 10px; font-weight: 800; }
    p { font-size: 1rem; line-height: 1.6; color: rgba(255,255,255,0.85); margin: 0 0 26px; }
    button {
      cursor: pointer; border: none; border-radius: 10px; padding: 13px 26px;
      font-size: 1rem; font-weight: 700; color: #7A0050; background: #F0C030;
    }
    button:active { transform: translateY(1px); }
    .hint { margin-top: 20px; font-size: 0.85rem; color: rgba(255,255,255,0.6); }
    a { color: #F0C030; }
  </style>
</head>
<body>
  <div class="card">
    <div class="mark">📶</div>
    <h1>You're offline</h1>
    <p>We couldn't reach BraidedbyAGB just now. Check your connection and try again — your place isn't lost.</p>
    <button onclick="location.reload()">Try again</button>
    <p class="hint">Need us urgently? WhatsApp <a href="https://wa.me/447769064971">07769 064971</a>.</p>
  </div>
</body>
</html>
