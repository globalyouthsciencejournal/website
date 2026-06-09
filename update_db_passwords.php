<?php
require_once __DIR__ . '/includes/bootstrap.php';

try {
    $pdo = db();
    
    // Check if reset_token already exists
    $checkStmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='users' AND column_name='reset_token'");
    if ($checkStmt->fetch()) {
        echo "The reset_token column already exists.\n";
    } else {
        // We'll run the ALTER TABLE commands for postgres/cockroach
        // For SQLite or MySQL, the syntax is generally the same.
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token TEXT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires TEXT NULL");
        } else if ($driver === 'mysql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL");
        } else {
            // postgres / cockroach
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires TIMESTAMP NULL");
        }
        echo "Successfully added reset_token and reset_expires columns.\n";
    }
} catch (Exception $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}
