<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nao autenticado']);
    exit;
}

function dynamic_setting_key($tank_id) {
    return 'dynamic_setpoint_tank_' . (int)$tank_id . '_ctrl_1_enabled';
}

function read_dynamic_state($conn, $tank_id) {
    $key = dynamic_setting_key($tank_id);
    $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $state = false;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $state = isset($row['setting_value']) && (string)$row['setting_value'] === '1';
    }
    $stmt->close();
    return $state;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : 0;
    if ($tank_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'tank_id invalido']);
        exit;
    }

    $enabled = read_dynamic_state($conn, $tank_id);
    if ($enabled === null) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao ler configuracao de setpoint dinamico']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'tank_id' => $tank_id,
        'states' => [
            '1' => $enabled,
        ],
    ]);
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
$enabled = !empty($payload['enabled']) ? 1 : 0;

if ($tank_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'tank_id invalido']);
    exit;
}

if ($ctrl !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Setpoint dinamico disponivel apenas para o controlador 1 (cloro)']);
    exit;
}

$key = dynamic_setting_key($tank_id);
$value = (string)$enabled;

$stmt_check = $conn->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1');
if (!$stmt_check) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar verificacao de configuracao']);
    exit;
}
$stmt_check->bind_param('s', $key);
$stmt_check->execute();
$res_check = $stmt_check->get_result();
$exists = $res_check && $res_check->num_rows > 0;
$stmt_check->close();

if ($exists) {
    $stmt_save = $conn->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    if (!$stmt_save) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao preparar update de configuracao']);
        exit;
    }
    $stmt_save->bind_param('ss', $value, $key);
} else {
    $stmt_save = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    if (!$stmt_save) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao preparar insert de configuracao']);
        exit;
    }
    $stmt_save->bind_param('ss', $key, $value);
}

if (!$stmt_save->execute()) {
    $stmt_save->close();
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao gravar configuracao de setpoint dinamico']);
    exit;
}
$stmt_save->close();

$user_id = (int)$_SESSION['user_id'];
$description = sprintf('Setpoint dinamico (ctrl=1) %s | tank_id=%d', $enabled ? 'ATIVADO' : 'DESATIVADO', $tank_id);
log_action($conn, $user_id, 'DYNAMIC_SETPOINT_TOGGLE', $description);

echo json_encode([
    'success' => true,
    'tank_id' => $tank_id,
    'ctrl' => 1,
    'enabled' => (bool)$enabled,
]);
