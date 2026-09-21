<?php
// ============================================================
// BraidedbyAGB — Meta (Facebook) Pixel
// FILE: /includes/meta-pixel.php
//
// Renders the base Pixel (PageView) only when a Pixel ID is set in
// Admin → Settings (meta_pixel_id) — so there's nothing to hardcode and no
// deploy needed to change it. metaPixelTrack() fires a standard event (e.g. a
// booking) and is a no-op when no Pixel is configured. Loaded from gtag.php so
// it's present on every public page that already includes analytics.
// ============================================================

if (!function_exists('metaPixelId')) {
    function metaPixelId(): string {
        return function_exists('getSetting') ? trim((string) getSetting('meta_pixel_id', '')) : '';
    }
    /**
     * Fire a standard Pixel event. No-op if no Pixel is configured.
     * $eventId lets Meta de-duplicate (e.g. the booking ref), so a page refresh
     * or a future server-side (CAPI) copy isn't counted twice.
     */
    function metaPixelTrack(string $event, array $params = [], string $eventId = ''): void {
        if (metaPixelId() === '') return;
        $p    = $params ? json_encode($params, JSON_UNESCAPED_SLASHES) : '{}';
        $opts = $eventId !== '' ? ', ' . json_encode(['eventID' => $eventId]) : '';
        echo "<script>if(window.fbq){fbq('track'," . json_encode($event) . ", {$p}{$opts});}</script>\n";
    }
}

// ── Base Pixel (render once per page) ─────────────────────
$__mpid = metaPixelId();
if ($__mpid !== '' && empty($GLOBALS['__meta_pixel_rendered'])):
    $GLOBALS['__meta_pixel_rendered'] = true;
?>
<!-- Meta Pixel -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', <?= json_encode($__mpid) ?>);
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=<?= urlencode($__mpid) ?>&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel -->
<?php endif; ?>
