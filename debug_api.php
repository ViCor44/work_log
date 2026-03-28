<?php
// Debug script to see raw API response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
$_SESSION['user_id'] = 1;

// Output as plain text to see the raw response
header('Content-Type: text/plain; charset=utf-8');

echo "=== DEBUG: Testing PID Suggestions API ===\n\n";

// Capture all output from the API
ob_start();

// Simulate a GET request
$_GET['tank_id'] = 5;
$_GET['days'] = 3;

// Load API
require_once 'api/get_pid_suggestions.php';

$output = ob_get_clean();

echo "Raw API Output:\n";
echo $output;
echo "\n\n";

// Try to parse as JSON
$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "✅ Valid JSON\n";
    echo "Keys: " . implode(', ', array_keys($json)) . "\n";
} else {
    echo "❌ Invalid JSON Error: " . json_last_error_msg() . "\n";
    echo "First 100 chars: " . substr($output, 0, 100) . "\n";
}

?>
