<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_GET["tank_id"] = 5;
$_GET["days"] = 3;

session_start();
$_SESSION["user_id"] = 1;

// Simula os headers
if (!function_exists("header_sent_hack")) {
    function header_sent_hack($str) {
        // Bloqueia redirects automaticamente
        return true;
    }
}

// Captura output
ob_start();
require_once "api/get_pid_suggestions.php";
$output = ob_get_clean();

echo "Length: " . strlen($output) . "\n";
echo "Type: " . (json_decode($output) ? "JSON" : "NOT JSON") . "\n";
if (strlen($output) < 500) {
    echo "Content:\n" . $output . "\n";
} else {
    echo "First 500 chars:\n" . substr($output, 0, 500) . "\n";
}
?>