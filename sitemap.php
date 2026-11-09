<?php
// Canonical sitemap origin, with an environment override for staging.
header('Content-Type: application/xml; charset=utf-8');
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://nexoracopy.net', '/');
$base = $siteUrl . '/en/';
$skip = ['index.html'];
$pages = [''];
foreach (glob(__DIR__ . '/en/*.html') as $f) { $n = basename($f); if (!in_array($n, $skip, true)) $pages[] = $n; }
foreach (glob(__DIR__ . '/en/policies/*.html') as $f) $pages[] = 'policies/' . basename($f);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p) {
    $file = $p === '' ? __DIR__ . '/en/index.html' : __DIR__ . '/en/' . $p;
    echo '  <url><loc>' . htmlspecialchars($base . $p) . '</loc><lastmod>' . date('Y-m-d', filemtime($file)) . "</lastmod></url>\n";
}
echo "</urlset>\n";
