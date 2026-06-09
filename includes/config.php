<?php
declare(strict_types=1);

// Central configuration for the site.
//
// Recommended: set these as environment variables on your hosting (Hostinger):
// - DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
//
// Fallbacks below are safe defaults; update them if your host provides different values.
$config = [
    'db' => [
        // pgsql or cockroachdb (default) or sqlite (handy for local dev)
        'driver' => getenv('DB_DRIVER') ?: 'cockroachdb',
        'host' => getenv('DB_HOST') ?: '',
        'port' => (int) (getenv('DB_PORT') ?: 26257),
        'name' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',

        // SQLite only: defaults to a local file under /data.
        'sqlite_path' => getenv('DB_SQLITE_PATH') ?: (__DIR__ . '/../data/app.sqlite'),
    ],
];

// Optional local override file (keep secrets out of git):
// - Copy `config.local.php.example` to `config.local.php` and fill in real values.
$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

return $config;
