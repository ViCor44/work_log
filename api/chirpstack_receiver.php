<?php
require_once '../db.php';
date_default_timezone_set('Europe/Lisbon');
$input = file_get_contents("php://input");
file_put_contents("../tmp/chirpstack_debug.log", date("Y-m-d H:i:s") . " - " . $input . "\n", FILE_APPEND);
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

if (!$data || !isset($data['deviceInfo']['devEui'])) {
    http_response_code(400); // Bad Request
    exit;
}

$dev_eui = $data['deviceInfo']['devEui'];
$rssi = isset($data['rxInfo'][0]['rssi']) ? $data['rxInfo'][0]['rssi'] : null;
$snr = isset($data['rxInfo'][0]['snr']) ? $data['rxInfo'][0]['snr'] : null;
$now = date("Y-m-d H:i:s");

// ======================================================
// == NOVA LÓGICA PARA DESCODIFICAR E GUARDAR O PAYLOAD ==
// ======================================================
$equipment_status = 'Unknown'; // Valor por defeito

if (isset($data['data'])) {
    // 1. O ChirpStack envia o payload codificado em base64
    $payload_base64 = $data['data'];
    // 2. Descodificamos de base64 para obter os bytes (ex: '01' ou '00')
    $payload_hex = bin2hex(base64_decode($payload_base64));

    // 3. Interpretamos o payload
    if ($payload_hex === '01') {
        $equipment_status = 'On';
    } elseif ($payload_hex === '00') {
        $equipment_status = 'Off';
    }
}

// Prepara a query para atualizar o estado do dispositivo E o estado do equipamento
$stmt = $conn->prepare("
    UPDATE lorawan_devices 
    SET 
        status = 'On', 
        equipment_status = ?, 
        last_seen = ?, 
        last_rssi = ?, 
        last_snr = ?
    WHERE dev_eui = ?
");
$stmt->bind_param("ssids", $equipment_status, $now, $rssi, $snr, $dev_eui);
$stmt->execute();
$stmt->close();

// ======================================================

http_response_code(200);
echo "OK";
?>