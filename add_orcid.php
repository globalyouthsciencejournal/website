<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
try {
    $pdo->exec("ALTER TABLE paper_submissions ADD COLUMN orcid_id TEXT DEFAULT NULL");
    echo "Added orcid_id.\n";
} catch (Exception $e) {
    echo "orcid_id error: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE paper_submission_versions ADD COLUMN orcid_id TEXT DEFAULT NULL");
    echo "Added orcid_id to versions.\n";
} catch (Exception $e) {
    echo "versions error: " . $e->getMessage() . "\n";
}
?>
