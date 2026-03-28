<?php
// Test script to verify PID analysis API
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['user_id'] = 1; // Fake user ID for testing

require_once 'core.php';

echo "<h2>Testing PID Suggestions API</h2>";
echo "<p><strong>Tank ID:</strong> 5</p>";

// Make a request to the API
$tank_id = 5;
$days = 3;

// Fetch the API response directly
ob_start();
require_once 'api/get_pid_suggestions.php';
$api_output = ob_get_clean();

echo "<h3>API Response:</h3>";
echo "<pre>";
echo htmlspecialchars($api_output);
echo "</pre>";

$response = json_decode($api_output, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "<div style='color: red;'><strong>Error decoding JSON:</strong> " . json_last_error_msg() . "</div>";
} else {
    echo "<h3>Parsed Response:</h3>";
    echo "<ul>";
    echo "<li><strong>Tank Name:</strong> " . htmlspecialchars($response['tank_name'] ?? 'N/A') . "</li>";
    echo "<li><strong>Days Analyzed:</strong> " . ($response['days'] ?? 'N/A') . "</li>";
    echo "<li><strong>Row Count:</strong> " . ($response['row_count'] ?? 'N/A') . "</li>";
    echo "<li><strong>Current PID:</strong>";
    if (isset($response['current_pid'])) {
        echo "<ul>";
        echo "<li>P: " . ($response['current_pid']['p'] ?? 'NULL') . "</li>";
        echo "<li>I: " . ($response['current_pid']['i'] ?? 'NULL') . "</li>";
        echo "<li>D: " . ($response['current_pid']['d'] ?? 'NULL') . "</li>";
        echo "</ul>";
    }
    echo "</li>";
    echo "<li><strong>Suggested Values:</strong>";
    if (isset($response['chlorine']['suggested_values'])) {
        echo "<ul>";
        echo "<li>P: " . ($response['chlorine']['suggested_values']['p'] ?? 'NULL') . "</li>";
        echo "<li>I: " . ($response['chlorine']['suggested_values']['i'] ?? 'NULL') . "</li>";
        echo "<li>D: " . ($response['chlorine']['suggested_values']['d'] ?? 'NULL') . "</li>";
        echo "</ul>";
    } else {
        echo " NOT PRESENT";
    }
    echo "</li>";
    echo "</ul>";
}

?>
