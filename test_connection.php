<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Database Connection Diagnostic</h2>";

// 1. Check if the certificate exists
$certPath = __DIR__ . '/includes/cockroach-ca.crt';
if (!file_exists($certPath)) {
    echo "<p style='color:red;'>ERROR: The certificate file 'includes/cockroach-ca.crt' is MISSING on the server. Please upload it.</p>";
} else {
    echo "<p style='color:green;'>Certificate file found.</p>";
}

// 2. Check if pdo_pgsql is installed
if (!extension_loaded('pdo_pgsql')) {
    echo "<p style='color:red;'>ERROR: The 'pdo_pgsql' extension is NOT installed or enabled on your server. CockroachDB requires PostgreSQL PDO drivers.</p>";
} else {
    echo "<p style='color:green;'>pdo_pgsql extension is enabled.</p>";
}

// 3. Try connecting to the database
require_once __DIR__ . '/includes/db.php';

try {
    echo "<p>Attempting to connect to CockroachDB...</p>";
    $pdo = db();
    echo "<p style='color:green;'><b>CONNECTION SUCCESSFUL!</b></p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'><b>CONNECTION FAILED:</b></p>";
    echo "<ul>";
    echo "<li><b>Error Message:</b> " . htmlspecialchars($e->getMessage()) . "</li>";
    echo "<li><b>Error Code:</b> " . htmlspecialchars((string)$e->getCode()) . "</li>";
    echo "</ul>";
    
    if (strpos($e->getMessage(), 'Connection timed out') !== false || strpos($e->getMessage(), 'Connection refused') !== false) {
        echo "<p><i>Hint: It looks like your web host (HelioHost) is blocking outgoing connections to port 26257. Many shared hosts block non-standard outgoing ports.</i></p>";
    }
}
