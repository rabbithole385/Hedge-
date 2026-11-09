<?php
header('Content-Type: text/plain; charset=utf-8');
$siteUrl = rtrim(getenv('SITE_URL') ?: 'https://nexoracopy.net', '/');
echo "User-agent: *\nAllow: /en/\nDisallow: /admin/\nDisallow: /user/\nDisallow: /api/\nDisallow: /auth/\nDisallow: /cron/\nDisallow: /setup/\nSitemap: {$siteUrl}/sitemap.xml\n";
