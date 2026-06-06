<?php
require_once __DIR__ . '/includes/bootstrap.php';
$pdo = db();
$stmt = $pdo->query("DESCRIBE paper_submissions");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
?>
