<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'ID do tanque inválido']);
    exit;
}
$tank_id = $_GET['id'];

// Define o intervalo de datas. Por defeito, é o dia de hoje.
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Garante que a data final inclui o dia inteiro
$end_date_full = $end_date . ' 23:59:59';

// Busca os dados históricos para o gráfico principal
$stmt_history = $conn->prepare("
    SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, ph_controller_state, cl_controller_state
    FROM controller_history 
    WHERE tank_id = ? AND log_datetime BETWEEN ? AND ? 
    ORDER BY log_datetime ASC
");
$stmt_history->bind_param("iss", $tank_id, $start_date, $end_date_full);
$stmt_history->execute();
$history = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_history->close();

// Busca o último registo para os manómetros (gauges)
$stmt_latest = $conn->prepare("
    SELECT * FROM controller_history WHERE tank_id = ? ORDER BY log_datetime DESC LIMIT 1
");
$stmt_latest->bind_param("i", $tank_id);
$stmt_latest->execute();
$latest_data = $stmt_latest->get_result()->fetch_assoc();
$stmt_latest->close();

// Prepara a resposta em formato JSON
$response = [
    'history' => $history,
    'latest' => $latest_data
];

echo json_encode($response);
?>