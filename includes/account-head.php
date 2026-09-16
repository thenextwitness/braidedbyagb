<?php
// ============================================================
// BraidedbyAGB — Account / auth page head partial
// FILE: /includes/account-head.php
//
// Shared <head> + nav for the customer account and sign-in pages. Sends
// no-store headers so authenticated pages are never cached by the browser,
// a shared device, or (critically) the PWA service worker. Include AFTER any
// requireClient()/redirect logic and after setting $pageTitle. Close the page
// with includes/account-foot.php.
// ============================================================

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
}
$accountTitle = $pageTitle ?? 'My Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">
  <title><?= htmlspecialchars($accountTitle) ?> — BraidedbyAGB</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:ital,wght@0,300;0,400;0,700;1,300&family=Cormorant+Garamond:ital,wght@1,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/brand.css">
<?php include __DIR__ . '/brand-styles.php'; ?>
  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/account.css">
</head>
<body>
<?php include __DIR__ . '/nav.php'; ?>
<main class="page-content">
