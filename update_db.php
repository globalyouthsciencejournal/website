<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
try {
    $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN view_count INTEGER NOT NULL DEFAULT 0");
    echo "Added view_count.\n";
} catch (Exception $e) {
    echo "view_count error: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN download_count INTEGER NOT NULL DEFAULT 0");
    echo "Added download_count.\n";
} catch (Exception $e) {
    echo "download_count error: " . $e->getMessage() . "\n";
}
