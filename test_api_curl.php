<?php
// Simple test - load API directly
chdir(__DIR__);

// Set up environment
$_GET['tank_id'] = 5;
$_GET['days'] = 3;

session_start();
$_SESSION['user_id'] = 1;

// Simulate HTTP request via URL
$ch = curl_init('http://localhost/work_log/api/get_pid_suggestions.php?tank_id=5&days=3');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "=== API Response Test ===\n\n";
echo "HTTP Code: $http_code\n";
echo "Content-Type: $content_type\n";
echo "Response Length: " . strlen($response) . " bytes\n\n";

if (strlen($response) === 0) {
    echo "❌ ERRO: Resposta vazia\n";
} else {
    // Try JSON decode
    $json = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ Valid JSON\n";
        echo "Keys: " . implode(', ', array_keys($json)) . "\n\n";
        
        if (isset($json['chlorine']['stats'])) {
            echo "Stats present ✅\n";
            echo "Samples: " . $json['chlorine']['stats']['samples'] . "\n";
            echo "Mean abs error: " . $json['chlorine']['stats']['mean_abs'] . "\n";
        }
        
        if (isset($json['chlorine']['suggested_values'])) {
            echo "\nSuggested values ✅\n";
            echo "P: " . $json['chlorine']['suggested_values']['p'] . "\n";
            echo "I: " . $json['chlorine']['suggested_values']['i'] . "\n";
            echo "D: " . $json['chlorine']['suggested_values']['d'] . "\n";
        }
    } else {
        echo "❌ Invalid JSON: " . json_last_error_msg() . "\n";
        echo "First 300 chars: " . htmlspecialchars(substr($response,  0, 300)) . "\n";
    }
}

?>
