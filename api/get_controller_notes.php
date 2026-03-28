<?php
// API para buscar notas associadas ao histórico do controlador
require_once '../core.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['tank_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'tank_id obrigatório']);
    exit;
}
$tank_id = (int)$_GET['tank_id'];

$stmt = $conn->prepare("SELECT id, log_datetime, note, created_at FROM controller_history_notes WHERE tank_id = ? ORDER BY log_datetime ASC");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar query: ' . $conn->error]);
    exit;
}
$stmt->bind_param('i', $tank_id);
$stmt->execute();
$result = $stmt->get_result();
$notes = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['notes' => $notes]);
