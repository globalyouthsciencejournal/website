<?php
declare(strict_types=1);

// CLI-only migration: adds extended profile fields to the `users` table.
// Safe to run multiple times: duplicate-column/index errors are ignored.

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

$columns = [
    'username' => [
        'mysql' => 'VARCHAR(64) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'phone' => [
        'mysql' => 'VARCHAR(32) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'country' => [
        'mysql' => 'VARCHAR(100) NULL',
        'sqlite' => 'TEXT NULL',
    ],

    'title' => [
        'mysql' => 'VARCHAR(32) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'first_name' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'middle_name' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'last_name' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],

    'position' => [
        'mysql' => 'VARCHAR(100) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'institution' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'department' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],

    'grade_level' => [
        'mysql' => 'VARCHAR(100) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'school_name' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'school_email' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'admission_number' => [
        'mysql' => 'VARCHAR(100) NULL',
        'sqlite' => 'TEXT NULL',
    ],

    'city' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'state' => [
        'mysql' => 'VARCHAR(255) NULL',
        'sqlite' => 'TEXT NULL',
    ],
    'postal_code' => [
        'mysql' => 'VARCHAR(32) NULL',
        'sqlite' => 'TEXT NULL',
    ],

    'assigned_journals_json' => [
        'mysql' => 'TEXT NULL',
        'sqlite' => 'TEXT NULL',
    ],
];

$added = 0;
foreach ($columns as $columnName => $types) {
    $type = $isSqlite ? $types['sqlite'] : $types['mysql'];
    $sql = 'ALTER TABLE users ADD COLUMN ' . $columnName . ' ' . $type;

    try {
        $pdo->exec($sql);
        $added++;
        echo "OK: added column {$columnName}\n";
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
            echo "SKIP: column {$columnName} already exists\n";
            continue;
        }

        if ($missingTable) {
            fwrite(STDERR, "ERROR: users table not found. Run: php scripts/init-db.php\n");
            exit(1);
        }

        fwrite(STDERR, "ERROR: failed to add column {$columnName}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Add/ensure unique index for username.
if ($isSqlite) {
    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_username ON users(username)');
        echo "OK: ensured unique index uniq_users_username\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: failed to create uniq_users_username index: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    try {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)');
        echo "OK: ensured unique index uniq_users_username\n";
    } catch (Throwable $e) {
        $msg = strtolower((string) $e->getMessage());

        $duplicateKeyName = (strpos($msg, 'duplicate key name') !== false)
            || (strpos($msg, 'already exists') !== false && strpos($msg, 'key') !== false);

        if ($duplicateKeyName) {
            echo "SKIP: unique index uniq_users_username already exists\n";
        } else {
            fwrite(STDERR, "ERROR: failed to create uniq_users_username index: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

echo "OK: migration complete (added {$added} column(s))\n";
