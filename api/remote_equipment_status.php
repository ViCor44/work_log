<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sessão não iniciada.']);
    exit;
}
session_write_close();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido.']);
    exit;
}
require_once dirname(__DIR__) . '/db.php';
$stmt = $conn->prepare('SELECT ip_address, slave_id FROM remote_equipment WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$equipment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$equipment) {
    http_response_code(404);
    echo json_encode(['error' => 'Equipamento não encontrado.']);
    exit;
}
// O destino vem exclusivamente do equipamento registado, nunca de um URL do cliente.
if (!filter_var($equipment['ip_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(422);
    echo json_encode(['error' => 'IP do equipamento inválido.']);
    exit;
}
$curl = curl_init('http://' . $equipment['ip_address'] . '/api/status/' . (int)$equipment['slave_id']);
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
$body = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$data = is_string($body) ? json_decode($body, true) : null;
if ($status !== 200 || !is_array($data) || !isset($data['isRunning'], $data['activeFault'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Sem comunicação com o equipamento.']);
    exit;
}
echo json_encode($data);
