<?php
error_log('ADMIN TEST PAGE - POST DATA: ' . json_encode($_POST));
echo '<h1>Admin Test</h1>';
echo '<pre>';
print_r($_POST);
echo '</pre>';
echo '<p><a href="account.php">Back to account</a></p>';
?>
