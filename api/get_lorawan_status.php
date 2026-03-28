<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

// ALTERAÇÃO: A query agora busca também a coluna 'equipment_status'
$result = $conn->query("
    SELECT name, status, equipment_status, last_seen, last_rssi, last_snr 
    FROM lorawan_devices 
    ORDER BY name ASC
");
$devices = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>