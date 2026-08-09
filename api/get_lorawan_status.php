<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

// ALTERAÇÃO: A query agora busca também a coluna 'equipment_status'
$result = $conn->query("
    SELECT id, name, device_type, status, equipment_status, fault_status,
           last_seen, last_rssi, last_snr
    FROM lorawan_devices 
    ORDER BY name ASC
");
$devices = $result->fetch_all(MYSQLI_ASSOC);

// Normaliza os valores para o contrato consumido pelo dashboard. Evita que
// diferenças de capitalização vindas de dados antigos deixem o card preso no
// estado anterior ou sejam apresentadas como estado desconhecido.
foreach ($devices as &$device) {
    $device['status'] = strcasecmp((string)($device['status'] ?? ''), 'on') === 0 ? 'On' : 'Off';
    $equipment = strtolower(trim((string)($device['equipment_status'] ?? '')));
    $device['equipment_status'] = $equipment === 'on' ? 'On' : ($equipment === 'off' ? 'Off' : 'Unknown');
    $fault = strtolower(trim((string)($device['fault_status'] ?? '')));
    $device['fault_status'] = $fault === 'fault' ? 'Fault' : ($fault === 'ok' ? 'Ok' : 'Unknown');
}
unset($device);

echo json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
