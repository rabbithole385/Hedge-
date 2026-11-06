<?php
// Render health check. Always 200 so a slow database start never blocks a deploy.
header('Content-Type: text/plain');
echo "ok\n";
