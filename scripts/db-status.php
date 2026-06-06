<?php
declare(strict_types=1);

// CLI-only DB status checker.
// Prints the connected driver and whether required tables exist.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$requiredTables = ['users', 'paper_submissions'];

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$driver = '';
try {
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $e) {
    $driver = '';
}

$driverLower = strtolower($driver);

echo "Driver: " . ($driver !== '' ? $driver : '(unknown)') . "\n";

$found = [];

try {
    if ($driverLower === 'mysql') {
        $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
        $stmt = $pdo->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
        );
        $stmt->execute($requiredTables);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['table_name'])) {
                $found[] = (string) $row['table_name'];
            }
        }
    } elseif ($driverLower === 'sqlite') {
        $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name IN ($placeholders)");
        $stmt->execute($requiredTables);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'])) {
                $found[] = (string) $row['name'];
            }
        }
    } else {
        echo "Table check not implemented for driver: {$driverLower}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Table check failed: " . $e->getMessage() . "\n");
    exit(1);
}

$missing = array_values(array_diff($requiredTables, $found));
$found = array_values(array_unique($found));

if ($found) {
    echo "Found tables: " . implode(', ', $found) . "\n";
}

if ($missing) {
    echo "Missing tables: " . implode(', ', $missing) . "\n";
    echo "Next step: run php scripts/init-db.php (or import sql/schema.sql).\n";
    exit(2);
}

echo "OK: all required tables exist.\n";
