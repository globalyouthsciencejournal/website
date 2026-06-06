<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
$stmt = $pdo->query("SELECT * FROM paper_submissions ORDER BY id DESC LIMIT 1");
$paper = $stmt->fetch(PDO::FETCH_ASSOC);
file_put_contents('paper_dump.json', json_encode($paper, JSON_PRETTY_PRINT));
echo "Done";
?>
