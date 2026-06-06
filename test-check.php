<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
$stmt = $pdo->query('SELECT id, title, metadata, submission_details FROM paper_submissions ORDER BY id DESC LIMIT 5');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Last 5 Submissions:\n\n";
foreach ($rows as $row) {
    echo "ID: " . $row['id'] . "\n";
    echo "Title: " . $row['title'] . "\n";
    echo "Metadata: " . $row['metadata'] . "\n";
    echo "Submission Details: " . $row['submission_details'] . "\n";
    echo "-------------------------\n";
}
