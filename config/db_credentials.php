<?php
/**
 * DATABASE LOGIN
 * - On Render (or any Docker host): set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   as environment variables. render.yaml does this automatically.
 * - On cPanel: put your login in config/db_credentials.cpanel.php (git-ignored).
 */
$__env = function (string $k): ?string {
    $v = getenv($k);
    return ($v === false || $v === '') ? null : $v;
};

if ($__env('DB_HOST') !== null) {
    define('DB_HOST', $__env('DB_HOST'));
    define('DB_PORT', $__env('DB_PORT') ?? '3306');
    define('DB_NAME', $__env('DB_NAME') ?? 'hedgeinv');
    define('DB_USER', $__env('DB_USER') ?? 'hedgeinv');
    define('DB_PASS', $__env('DB_PASS') ?? '');
    // Set DB_SSL=1 for cloud MySQL that requires an encrypted connection (TiDB Cloud, Aiven, ...)
    define('DB_SSL', in_array(strtolower((string)$__env('DB_SSL')), ['1', 'true', 'yes', 'on'], true));
} elseif (is_file(__DIR__ . '/db_credentials.cpanel.php')) {
    require __DIR__ . '/db_credentials.cpanel.php';
}

defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', '');
defined('DB_USER') || define('DB_USER', '');
defined('DB_PASS') || define('DB_PASS', '');
defined('DB_SSL') || define('DB_SSL', false);
unset($__env);
