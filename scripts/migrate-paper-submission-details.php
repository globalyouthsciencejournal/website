<?php
declare(strict_types=1);

// CLI-only migration: adds a dedicated submission_details field to paper_submissions.
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

try {
    $pdo->exec('ALTER TABLE paper_submissions ADD COLUMN submission_details ' . $type);
    echo "OK: added column submission_details\n";
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
        echo "SKIP: column submission_details already exists\n";
        exit(0);
    }

    if ($missingTable) {
        fwrite(STDERR, "ERROR: paper_submissions table not found. Run: php scripts/init-db.php\n");
        exit(1);
    }

    fwrite(STDERR, "ERROR: failed to add column submission_details: " . $e->getMessage() . "\n");
    exit(1);
}

echo "OK: migration complete\n";