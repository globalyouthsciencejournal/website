<?php
declare(strict_types=1);

// CLI-only migration: adds reviewer profile fields to admin applications and admin accounts.
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

$columns = [
    'users' => [
        'reviewer_experience_text' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_reason_text' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_weekly_availability' => [
            'mysql' => 'VARCHAR(32) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_profile_links' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_path' => [
            'mysql' => 'VARCHAR(1024) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_original_name' => [
            'mysql' => 'VARCHAR(255) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_mime' => [
            'mysql' => 'VARCHAR(100) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_size' => [
            'mysql' => 'INT UNSIGNED NULL',
            'sqlite' => 'INTEGER NULL',
        ],
        'reviewer_supporting_documents_json' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_declaration_confirmed' => [
            'mysql' => 'TINYINT(1) NULL DEFAULT 0',
            'sqlite' => 'INTEGER NULL DEFAULT 0',
        ],
    ],
    'admin_applications' => [
        'country' => [
            'mysql' => 'VARCHAR(100) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'institution' => [
            'mysql' => 'VARCHAR(255) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'grade_level' => [
            'mysql' => 'VARCHAR(100) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_experience_text' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_reason_text' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_weekly_availability' => [
            'mysql' => 'VARCHAR(32) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_profile_links' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_path' => [
            'mysql' => 'VARCHAR(1024) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_original_name' => [
            'mysql' => 'VARCHAR(255) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_mime' => [
            'mysql' => 'VARCHAR(100) NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_cv_size' => [
            'mysql' => 'INT UNSIGNED NULL',
            'sqlite' => 'INTEGER NULL',
        ],
        'reviewer_supporting_documents_json' => [
            'mysql' => 'TEXT NULL',
            'sqlite' => 'TEXT NULL',
        ],
        'reviewer_declaration_confirmed' => [
            'mysql' => 'TINYINT(1) NULL DEFAULT 0',
            'sqlite' => 'INTEGER NULL DEFAULT 0',
        ],
    ],
];

foreach ($columns as $table => $tableColumns) {
    foreach ($tableColumns as $columnName => $types) {
        $type = $isSqlite ? $types['sqlite'] : $types['mysql'];
        $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $columnName . ' ' . $type;

        try {
            $pdo->exec($sql);
            echo "OK: added column {$columnName} to {$table}\n";
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
                echo "SKIP: column {$columnName} already exists on {$table}\n";
                continue;
            }

            if ($missingTable) {
                fwrite(STDERR, "ERROR: required table {$table} not found. Run: php scripts/init-db.php\n");
                exit(1);
            }

            fwrite(STDERR, "ERROR: failed to add column {$columnName} to {$table}: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}

echo "OK: migration complete\n";