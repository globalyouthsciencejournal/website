<?php
declare(strict_types=1);

/**
 * Returns a shared PDO connection.
 *
 * Usage:
 *   $pdo = db();
 *   $rows = $pdo->query('SELECT 1')->fetchAll();
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /** @var array{db: array{host: string, port: int, name: string, user: string, pass: string, charset: string}} $config */
    $config = require __DIR__ . '/config.php';
    $db = $config['db'] ?? null;

    if (!is_array($db)) {
        throw new RuntimeException('Database configuration is missing.');
    }

    $driver = strtolower((string) ($db['driver'] ?? 'mysql'));

    if ($driver === 'sqlite') {
        $path = (string) ($db['path'] ?? $db['sqlite_path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('SQLite path not configured.');
        }

        // Normalize for sqlite DSN (helps on Windows).
        $path = str_replace('\\', '/', $path);

        if ($path !== ':memory:') {
            $dir = dirname($path);
            if ($dir !== '' && !is_dir($dir)) {
                if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Failed to create SQLite directory: ' . $dir);
                }
            }
        }

        $pdo = new PDO(
            'sqlite:' . $path,
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        // Enforce FK constraints in SQLite.
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    if ($driver === 'pgsql' || $driver === 'cockroachdb') {
        $host = (string) ($db['host'] ?? 'localhost');
        $port = (int) ($db['port'] ?? 26257);
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $pass = (string) ($db['pass'] ?? '');

        if ($name === '' || $user === '') {
            throw new RuntimeException('Database name/user not configured.');
        }

        // For CockroachDB, we may need sslmode
        $sslmode = (string) ($db['sslmode'] ?? 'verify-full');
        $certPath = str_replace('\\', '/', __DIR__ . '/cockroach-ca.crt');
        
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname='%s';sslmode=%s;sslrootcert='%s'",
            $host,
            $port,
            $name,
            $sslmode,
            $certPath
        );

        $pdo = new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return $pdo;
    }

    // Default: MySQL
    $host = (string) ($db['host'] ?? 'localhost');
    $port = (int) ($db['port'] ?? 3306);
    $name = (string) ($db['name'] ?? '');
    $user = (string) ($db['user'] ?? '');
    $pass = (string) ($db['pass'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database name/user not configured.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $name,
        $charset
    );

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}
