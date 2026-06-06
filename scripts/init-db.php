<?php
declare(strict_types=1);

// CLI-only DB initializer for local/dev usage.
// Applies `sql/schema.sql` using the DB credentials from `includes/config.php` (+ optional `includes/config.local.php`).

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
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $e) {
    $driver = '';
}

$schemaPath = __DIR__ . '/../sql/schema.sql';
if (strtolower($driver) === 'sqlite') {
    $schemaPath = __DIR__ . '/../sql/schema.sqlite.sql';
}

if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema file not found: {$schemaPath}\n");
    exit(1);
}

$sqlRaw = file_get_contents($schemaPath);
if ($sqlRaw === false) {
    fwrite(STDERR, "Failed to read schema file: {$schemaPath}\n");
    exit(1);
}

// Strip simple line comments and build a statement buffer.
$buffer = '';
$lines = preg_split("/\r\n|\n|\r/", $sqlRaw) ?: [];
foreach ($lines as $line) {
    $trimmed = ltrim($line);

    // Skip full-line comments and empty lines.
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
        continue;
    }

    $buffer .= $line . "\n";
}

$statements = [];
foreach (explode(';', $buffer) as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $statements[] = $statement;
}

$applied = 0;
foreach ($statements as $statement) {
    $pdo->exec($statement);
    $applied++;
}

echo "OK: Applied {$applied} statement(s) from " . basename($schemaPath) . "\n";
