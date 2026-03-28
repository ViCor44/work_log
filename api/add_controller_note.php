<?php
// API para inserir uma nota associada a um ponto do histórico do controlador
require_once '../core.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}


$data = json_decode(file_get_contents('php://input'), true);
$tank_id = isset($data['tank_id']) ? (int)$data['tank_id'] : null;
$log_datetime = isset($data['log_datetime']) ? $data['log_datetime'] : null;
$note = isset($data['note']) ? trim($data['note']) : '';


if (!$tank_id || !$log_datetime || $note === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Dados obrigatórios em falta']);
    exit;
}




$stmt = $conn->prepare("INSERT INTO controller_history_notes (tank_id, log_datetime, note) VALUES (?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar query: ' . $conn->error]);
    exit;
}
$stmt->bind_param('iss', $tank_id, $log_datetime, $note);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao inserir nota: ' . $stmt->error]);
    exit;
}
$stmt->close();

echo json_encode(['success' => true, 'id' => $conn->insert_id]);
