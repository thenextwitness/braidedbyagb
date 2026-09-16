<?php
// ============================================================
// BraidedbyAGB — Shared PWA / theme <head> tags
// FILE: /includes/head-meta.php
//
// Emitted once per request (guarded) and pulled in by includes/brand-styles.php,
// which every rendered page includes — so the manifest link, theme colour,
// iOS home-screen metas and the service-worker registration reach every page
// without editing each <head> by hand.
// ============================================================
if (!defined('AGB_HEAD_META')) {
    define('AGB_HEAD_META', 1);
?>
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" content="#CC1A8A">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="BraidedbyAGB">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<script src="/assets/js/pwa.js" defer></script>
<?php } ?>
