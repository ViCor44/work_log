<?php
require_once '../db.php';
date_default_timezone_set('Europe/Lisbon');
$input = file_get_contents("php://input");
$debugDir = dirname(__DIR__) . '/tmp';
if (!is_dir($debugDir)) { @mkdir($debugDir, 0775, true); }
file_put_contents($debugDir . '/chirpstack_debug.log', date("Y-m-d H:i:s") . " - " . $input . "\n", FILE_APPEND | LOCK_EX);
$json_data = $input;
$data = json_decode($json_data, true);

if (!$data || !isset($data['deviceInfo']['devEui'])) {
    http_response_code(400); // Bad Request
    exit;
}

$dev_eui = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$data['deviceInfo']['devEui']));
if (strlen($dev_eui) !== 16) {
    http_response_code(400);
    echo "DevEUI inválido";
    exit;
}
$rssi = isset($data['rxInfo'][0]['rssi']) ? $data['rxInfo'][0]['rssi'] : null;
$snr = isset($data['rxInfo'][0]['snr']) ? $data['rxInfo'][0]['snr'] : null;
$now = date("Y-m-d H:i:s");

// ======================================================
// == NOVA LÓGICA PARA DESCODIFICAR E GUARDAR O PAYLOAD ==
// ======================================================
$equipment_status = 'Unknown'; // Valor por defeito
$device_type = null;
$fault_status = null;

if (isset($data['data'])) {
    // 1. O ChirpStack envia o payload codificado em base64
    $payload_base64 = $data['data'];
    // 2. Descodificamos de base64 para obter os bytes (ex: '01' ou '00')
    $payload = base64_decode($payload_base64, true);
    $payload_hex = $payload === false ? '' : bin2hex($payload);

    // 3. Interpretamos o payload
    if (strlen($payload_hex) === 4) {
        // Monitor de gerador (porta 2): byte 0 = ON/OFF, byte 1 = avaria.
        $device_type = 'generator';
        $equipment_status = hexdec(substr($payload_hex, 0, 2)) === 1 ? 'On' : 'Off';
        $fault_status = hexdec(substr($payload_hex, 2, 2)) === 1 ? 'Fault' : 'Ok';
    } elseif ($payload_hex === '01') {
        $device_type = 'osmosis';
        $equipment_status = 'On';
    } elseif ($payload_hex === '00') {
        $device_type = 'osmosis';
        $equipment_status = 'Off';
    }

    file_put_contents(
        $debugDir . '/chirpstack_debug.log',
        date("Y-m-d H:i:s") . " - decoded dev_eui={$dev_eui} payload={$payload_hex} equipment={$equipment_status} fault=" . ($fault_status ?? 'null') . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// Prepara a query para atualizar o estado do dispositivo E o estado do equipamento
$stmt = $conn->prepare("
    UPDATE lorawan_devices 
    SET 
        status = 'On', 
        equipment_status = ?,
        device_type = COALESCE(?, device_type),
        fault_status = ?,
        last_seen = ?, 
        last_rssi = ?, 
        last_snr = ?
    WHERE LOWER(REPLACE(REPLACE(REPLACE(TRIM(dev_eui), ':', ''), '-', ''), ' ', '')) = ?
");
$stmt->bind_param("ssssids", $equipment_status, $device_type, $fault_status, $now, $rssi, $snr, $dev_eui);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $check = $conn->prepare("SELECT id FROM lorawan_devices WHERE LOWER(REPLACE(REPLACE(REPLACE(TRIM(dev_eui), ':', ''), '-', ''), ' ', '')) = ? LIMIT 1");
    $check->bind_param('s', $dev_eui);
    $check->execute();
    $device_exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$device_exists) {
        $stmt->close();
        error_log("LORAWAN_UPLINK_DEVICE_NOT_FOUND dev_eui={$dev_eui}");
        http_response_code(404);
        echo "Dispositivo LoRaWAN não encontrado: {$dev_eui}";
        exit;
    }
}
$stmt->close();

// ======================================================

// Avalia imediatamente as transições de alarme/SMS depois de persistir o
// uplink. O worker periódico continua a tratar timeouts LoRa, mas não pode ser
// o único responsável: um OFF/Fault curto poderia regressar a On/Ok entre
// duas execuções e nunca originar SMS.
try {
    // Usa o mesmo lock do worker periódico. Sem esta serialização, vários
    // webhooks simultâneos podem ler o mesmo estado anterior e enviar a mesma
    // recuperação antes de qualquer deles persistir o novo estado.
    $smsLock = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worklog_check_lorawan.lock', 'c');
    if ($smsLock !== false && flock($smsLock, LOCK_EX | LOCK_NB)) {
        try {
            require_once __DIR__ . '/sms_alarm_notifier.php';
            process_lora_alarms($conn);
        } finally {
            flock($smsLock, LOCK_UN);
            fclose($smsLock);
        }
    } elseif ($smsLock !== false) {
        fclose($smsLock);
        error_log('SMS_LORA_UPLINK_SKIPPED alarm processor already running');
    }
} catch (Throwable $smsE) {
    // A receção da telemetria não deve falhar caso o modem/serviço SMS esteja
    // indisponível; a falha fica registada para diagnóstico.
    error_log('SMS_LORA_UPLINK_ALARM_ERR ' . $smsE->getMessage());
}

http_response_code(200);
echo "OK";
?>
