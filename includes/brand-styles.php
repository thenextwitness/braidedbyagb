<?php
// ============================================================
// BraidedbyAGB — Dynamic Brand CSS Variables
// FILE: /includes/brand-styles.php
// Include in <head> AFTER brand.css to override CSS vars.
// ============================================================
if (!function_exists('getDB')) {
    // Graceful no-op if included before DB is available
    return;
}
try {
    $db   = getDB();
    $rows = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'brand_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {
    $rows = [];
}

$p   = htmlspecialchars($rows['brand_color_primary']      ?? '');
$pd  = htmlspecialchars($rows['brand_color_primary_dark'] ?? '');
$dp  = htmlspecialchars($rows['brand_color_deep_purple']  ?? '');
$g   = htmlspecialchars($rows['brand_color_gold']         ?? '');
$bg  = htmlspecialchars($rows['brand_color_bg']           ?? '');
$t   = htmlspecialchars($rows['brand_color_text']         ?? '');
$tm  = htmlspecialchars($rows['brand_color_text_muted']   ?? '');
$fp  = htmlspecialchars($rows['brand_font_primary']       ?? '');
$fb  = htmlspecialchars($rows['brand_font_body']          ?? '');
$lo  = htmlspecialchars($rows['brand_logo_url']           ?? '');

// Only output if at least one override exists
$hasOverrides = array_filter([$p,$pd,$dp,$g,$bg,$t,$tm,$fp,$fb]);
if (empty($hasOverrides)) return;
?>
<?php if ($fp || $fb):
    $fontQuery = implode('&', array_filter([
        $fp ? 'family=' . urlencode($fp) . ':wght@400;600;700;800;900' : '',
        $fb ? 'family=' . urlencode($fb) . ':wght@300;400;700' : '',
    ]));
?>
<link href="https://fonts.googleapis.com/css2?<?= $fontQuery ?>&display=swap" rel="stylesheet">
<?php endif; ?>
<style>
:root {
<?= $p  ? "  --color-primary:      $p;\n"      : '' ?>
<?= $pd ? "  --color-primary-dark: $pd;\n"     : '' ?>
<?= $dp ? "  --color-deep-purple:  $dp;\n"     : '' ?>
<?= $g  ? "  --color-gold:         $g;\n"      : '' ?>
<?= $bg ? "  --color-bg:           $bg;\n"     : '' ?>
<?= $t  ? "  --color-text:         $t;\n"      : '' ?>
<?= $tm ? "  --color-text-muted:   $tm;\n"     : '' ?>
<?= $fp ? "  --font-primary: '$fp', sans-serif;\n" : '' ?>
<?= $fb ? "  --font-body:    '$fb', sans-serif;\n" : '' ?>
}
<?php if ($lo): ?>
/* Logo override */
.site-logo img, .logo img, .nav-logo img { content: url('<?= $lo ?>'); }
<?php endif; ?>
</style>
