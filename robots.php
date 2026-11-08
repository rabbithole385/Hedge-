<?php
header('Content-Type: text/plain; charset=utf-8');
$host = preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
echo "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /user/\nDisallow: /api/\nDisallow: /auth/\nDisallow: /cron/\nDisallow: /setup/\nSitemap: https://{$host}/sitemap.xml\n";
