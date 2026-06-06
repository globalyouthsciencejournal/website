<?php
declare(strict_types=1);

// CLI-only migration: adds assigned journals storage for admin applications and admin accounts.
// Safe to run multiple times: duplicate-column errors are ignored.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$driver = '';
try {
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
    $driver = '';
}

$isSqlite = ($driver === 'sqlite');
$type = $isSqlite ? 'TEXT NULL' : 'TEXT NULL';

$columns = [
    ['table' => 'users', 'column' => 'assigned_journals_json'],
    ['table' => 'admin_applications', 'column' => 'assigned_journals_json'],
];

foreach ($columns as $spec) {
    $sql = 'ALTER TABLE ' . $spec['table'] . ' ADD COLUMN ' . $spec['column'] . ' ' . $type;

    try {
        $pdo->exec($sql);
        echo "OK: added column {$spec['column']} to {$spec['table']}\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());
        $code = strtolower((string) $e->getCode());

        $duplicateColumn = (strpos($msg, 'duplicate column') !== false)
            || (strpos($msg, 'duplicate column name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'column') !== false);

        $missingTable = ($code === '42s02')
            || (strpos($msg, "doesn't exist") !== false)
            || (strpos($msg, 'no such table') !== false);

        if ($duplicateColumn) {
            echo "SKIP: column {$spec['column']} already exists on {$spec['table']}\n";
            continue;
        }

        if ($missingTable) {
            fwrite(STDERR, "ERROR: required table {$spec['table']} not found. Run: php scripts/init-db.php\n");
            exit(1);
        }

        fwrite(STDERR, "ERROR: failed to add column {$spec['column']} to {$spec['table']}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "OK: migration complete\n";