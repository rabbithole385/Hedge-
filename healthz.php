<?php
// Health check. Always 200 so a slow database start never blocks a deploy.
// Also shows which code version is running, to confirm a deploy went live.
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo "ok\n";
echo 'version: ' . substr((string)(getenv('RAILWAY_GIT_COMMIT_SHA') ?: getenv('RENDER_GIT_COMMIT') ?: 'unknown'), 0, 7) . "\n";
echo "redirect-fix: 2\n";
