<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$slug = is_string($slug) ? trim($slug) : '';

if ($slug === '') {
  header('Location: /publication.php', true, 302);
  exit;
}

header('Location: /publication.php?slug=' . rawurlencode($slug), true, 302);
exit;
