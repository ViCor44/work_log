<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Não autenticado']); exit;
}

$tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : 0;
if ($tank_id <= 0) {
    echo json_encode(['error' => 'tank_id inválido']); exit;
}

$limit = isset($_GET['limit']) ? max(1, min(365, (int)$_GET['limit'])) : 30;

$stmt = $conn->prepare("
    SELECT data_referencia, hora_inicio, hora_fim,
           integral_dosagem, qmax_lh, consumo_estimado_l, n_registos, created_at
    FROM hipoclorito_diario
    WHERE tank_id = ?
    ORDER BY data_referencia DESC
    LIMIT ?
");
$stmt->bind_param("ii", $tank_id, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['consumo' => $rows]);
