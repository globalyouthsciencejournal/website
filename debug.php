<?php
require 'includes/bootstrap.php';
$pdo = db();
$stmt = $pdo->query('SELECT id, slug, tracking_id, volume_year, issue_number, published_at FROM paper_submissions ORDER BY id DESC LIMIT 5');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
