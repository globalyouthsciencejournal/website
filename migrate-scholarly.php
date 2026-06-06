<?php
require_once __DIR__ . '/includes/bootstrap.php';

try {
    $pdo = db();
    
    $queries = [
        "ALTER TABLE paper_submissions ADD COLUMN doi VARCHAR(100) NULL UNIQUE",
        "ALTER TABLE paper_submissions ADD COLUMN funding_info TEXT NULL",
        "ALTER TABLE paper_submissions ADD COLUMN license_url VARCHAR(255) DEFAULT 'https://creativecommons.org/licenses/by/4.0/'"
    ];
    
    foreach ($queries as $q) {
        try {
            $pdo->exec($q);
            echo "Success: $q\n";
        } catch (Throwable $e) {
            echo "Skipped/Failed: $q (" . $e->getMessage() . ")\n";
        }
    }
    
    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}
