<?php
require 'vendor/autoload.php';
use WebSocket\Client;

$devEUI = '0000000000000001'; // Replace with your device's DevEUI
$apiToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJjaGlycHN0YWNrIiwiaXNzIjoiY2hpcnBzdGFjayIsInN1YiI6IjZmM2M1MThmLWVjYjUtNDBjMS05NWI2LWM0NWQ0YjUxYzJmMyIsInR5cCI6ImtleSJ9.1mEGsuab6qMUh7Lco4uwPQN6ryl2Hg4wqiFln2Q-6C0'; // Replace with your API token
$url = "ws://172.29.23.173:8080/api/devices/$devEUI/events";

try {
    $client = new Client($url, [
        'headers' => [
            'Authorization' => "Bearer $apiToken"
        ]
    ]);

    echo "Connected to ChirpStack WebSocket\n";

    while (true) {
        $message = $client->receive();
        $data = json_decode($message, true);
        echo "Received uplink: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";

        // Decode Base64 payload
        if (isset($data['phy_payload']['payload']['frm_payload'])) {
            $base64Payload = $data['phy_payload']['payload']['frm_payload'];
            $decodedPayload = base64_decode($base64Payload);
            $hexPayload = bin2hex($decodedPayload);
            echo "Decoded payload (ASCII): $decodedPayload\n";
            echo "Hex payload: $hexPayload\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>