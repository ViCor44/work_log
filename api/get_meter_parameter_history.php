<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

// Validação dos parâmetros recebidos
$meter_id = isset($_GET['meter_id']) ? (int)$_GET['meter_id'] : 0;
$parameter = isset($_GET['parameter']) ? $_GET['parameter'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Medida de Segurança: Lista de colunas permitidas para evitar injeção de SQL
$allowed_parameters = [
    'voltageLLAvg', 'currentAvg', 'activePowerTotal', 'voltageAB', 'voltageBC', 'voltageCA',
    'voltageAN', 'voltageBN', 'voltageCN', 'voltageLNAvg', 'currentA', 'currentB',
    'currentC', 'activePowerA', 'activePowerB', 'activePowerC', 'powerFactorTotal', 'frequency'
];

if ($meter_id <= 0 || !in_array($parameter, $allowed_parameters)) {
    echo json_encode(['error' => 'Parâmetros inválidos.']);
    exit;
}

$end_date_full = $end_date . ' 23:59:59';

// Override de datetime exato (usado para janelas rolantes como "últimas 24h")
if (isset($_GET['start_datetime']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $_GET['start_datetime'])) {
    $start_date = $_GET['start_datetime'];
}
if (isset($_GET['end_datetime']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $_GET['end_datetime'])) {
    $end_date_full = $_GET['end_datetime'];
}

// A query agora seleciona a coluna do parâmetro de forma dinâmica e segura
$sql = "SELECT log_datetime, `$parameter` as value 
        FROM power_meter_history 
        WHERE meter_id = ? AND log_datetime BETWEEN ? AND ? 
        ORDER BY log_datetime ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iss", $meter_id, $start_date, $end_date_full);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($history);
?>