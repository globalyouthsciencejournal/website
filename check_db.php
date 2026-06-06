<?php require 'includes/bootstrap.php'; $pdo = db(); $stmt = $pdo->query('DESCRIBE submission_messages'); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); ?>
