<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
$stmt = $pdo->query("SELECT id, status, title, published_at FROM paper_submissions ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/test_output.txt', print_r($rows, true));
echo "Done";
