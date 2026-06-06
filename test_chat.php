<?php
$ch = curl_init("http://localhost/user-dashboard.php");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, ["action" => "get_chat", "submission_id" => "1"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);
echo "RESPONSE:\n" . $response;
?>
