<?php
error_log('TEST PAGE LOADED');
error_log('REQUEST METHOD: ' . $_SERVER['REQUEST_METHOD']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('POST DATA: ' . json_encode($_POST));
}
echo '<h1>Test Page</h1>';
echo '<p>REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD'] . '</p>';
echo '<form method="post" action="account-test.php">';
echo '<input type="hidden" name="test" value="1">';
echo '<input type="text" name="email" value="test@example.com">';
echo '<button type="submit">Test Submit</button>';
echo '</form>';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<p style="color: green;"><strong>FORM SUBMITTED - DATA RECEIVED!</strong></p>';
    echo '<pre>' . print_r($_POST, true) . '</pre>';
}
?>
