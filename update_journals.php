<?php
require 'includes/db.php';
try {
    $db->exec("UPDATE submissions SET journal = CONCAT('Journal of Advance Research in ', journal) WHERE journal NOT LIKE 'Journal of%' AND journal != 'Advance Research (General)'");
    $db->exec("UPDATE submissions SET journal = 'Journal of Advance Research (General)' WHERE journal = 'Advance Research (General)'");
    echo 'Done';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
