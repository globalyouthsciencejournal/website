<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
$_SESSION['user_id'] = 1;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = 1;

try {
    include 'edit-submission.php';
} catch (Throwable $e) {
    echo "CAUGHT EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
