<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nao autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo nao permitido']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$tank_id = isset($payload['tank_id']) ? (int)$payload['tank_id'] : 0;
$ctrl = isset($payload['ctrl']) ? (int)$payload['ctrl'] : 0;
$val_raw = isset($payload['val']) ? str_replace(',', '.', trim((string)$payload['val'])) : '';

if ($ctrl < 1 || $ctrl > 3) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro ctrl invalido. Use 1, 2 ou 3.']);
    exit;
}

if ($val_raw === '' || !is_numeric($val_raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro val invalido.']);
    exit;
}

$val = (float)$val_raw;
if (!is_finite($val)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro val fora de intervalo.']);
    exit;
}

$tank_name = 'N/A';
if ($tank_id > 0) {
    $stmt_tank = $conn->prepare('SELECT name FROM tanks WHERE id = ? LIMIT 1');
    if ($stmt_tank) {
        $stmt_tank->bind_param('i', $tank_id);
        $stmt_tank->execute();
        $res_tank = $stmt_tank->get_result();
        if ($res_tank && $res_tank->num_rows > 0) {
            $tank_row = $res_tank->fetch_assoc();
            $tank_name = $tank_row['name'];
        }
        $stmt_tank->close();
    }
}

$endpoint_url = 'http://191.188.127.30/';
$post_fields = http_build_query([
    'ctrl' => $ctrl,
    'val' => $val_raw
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint_url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post_fields,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 10,
]);

$remote_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

$user_id = (int)$_SESSION['user_id'];
$status_label = ($remote_response !== false && $http_code >= 200 && $http_code < 300) ? 'SUCESSO' : 'FALHA';
$description = sprintf(
    'Setpoint remoto %s | tanque_id=%d | tanque=%s | ctrl=%d | val=%s | endpoint=%s | http=%d',
    $status_label,
    $tank_id,
    $tank_name,
    $ctrl,
    $val_raw,
    $endpoint_url,
    (int)$http_code
);
if ($curl_error !== '') {
    $description .= ' | curl_error=' . $curl_error;
}
log_action($conn, $user_id, 'SETPOINT_REMOTE_UPDATE', $description);

if ($remote_response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Erro de comunicacao com o controlador: ' . $curl_error]);
    exit;
}

if ($http_code < 200 || $http_code >= 300) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Controlador devolveu HTTP ' . $http_code,
        'response' => substr((string)$remote_response, 0, 500)
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Setpoint remoto aplicado e registado com sucesso.',
    'ctrl' => $ctrl,
    'val' => $val,
    'tank_id' => $tank_id,
    'timestamp' => date('Y-m-d H:i:s')
]);
