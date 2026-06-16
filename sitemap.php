<?php
// ============================================================
// BraidedbyAGB — Dynamic XML Sitemap
// FILE: /sitemap.php
// Route: add to .htaccess: RewriteRule ^sitemap\.xml$ sitemap.php [L]
// ============================================================
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$db = getDB();
$base = 'https://braidedbyagb.co.uk';

// Static pages
$static = [
    ['loc' => '',           'priority' => '1.0',  'changefreq' => 'weekly'],
    ['loc' => '/services',  'priority' => '0.9',  'changefreq' => 'weekly'],
    ['loc' => '/shop',      'priority' => '0.8',  'changefreq' => 'weekly'],
    ['loc' => '/booking',   'priority' => '0.9',  'changefreq' => 'monthly'],
    ['loc' => '/about',     'priority' => '0.6',  'changefreq' => 'monthly'],
    ['loc' => '/contact',   'priority' => '0.6',  'changefreq' => 'monthly'],
    ['loc' => '/policies',  'priority' => '0.4',  'changefreq' => 'monthly'],
];

// Dynamic: services
try {
    $services = $db->query("SELECT slug, updated_at FROM services WHERE is_active=1 ORDER BY display_order ASC")->fetchAll();
} catch(Exception $e) { $services = []; }

// Dynamic: products
try {
    $products = $db->query("SELECT slug, updated_at FROM products WHERE is_active=1 ORDER BY display_order ASC")->fetchAll();
} catch(Exception $e) { $products = []; }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($static as $page) {
    echo "  <url>\n";
    echo "    <loc>{$base}{$page['loc']}</loc>\n";
    echo "    <changefreq>{$page['changefreq']}</changefreq>\n";
    echo "    <priority>{$page['priority']}</priority>\n";
    echo "  </url>\n";
}

foreach ($services as $svc) {
    $lastmod = $svc['updated_at'] ? date('Y-m-d', strtotime($svc['updated_at'])) : date('Y-m-d');
    echo "  <url>\n";
    echo "    <loc>{$base}/services/" . htmlspecialchars($svc['slug']) . "</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

foreach ($products as $prod) {
    $lastmod = $prod['updated_at'] ? date('Y-m-d', strtotime($prod['updated_at'])) : date('Y-m-d');
    echo "  <url>\n";
    echo "    <loc>{$base}/shop/" . htmlspecialchars($prod['slug']) . "</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>';
