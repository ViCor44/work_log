<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';
require_once '../api/dosing_block_lib.php';

function relay_command_error($status, $message) {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    relay_command_error(401, 'Acesso não autorizado.');
}

if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'viewer') {
    relay_command_error(403, 'O perfil de visualização não pode comandar relés.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    relay_command_error(405, 'Método não permitido.');
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    relay_command_error(400, 'Pedido inválido.');
}

$csrfToken = isset($payload['csrf_token']) ? (string)$payload['csrf_token'] : '';
if (
    empty($_SESSION['relay_csrf_token'])
    || $csrfToken === ''
    || !hash_equals($_SESSION['relay_csrf_token'], $csrfToken)
) {
    relay_command_error(403, 'Token de segurança inválido. Atualize a página e tente novamente.');
}

$tankId = isset($payload['tank_id']) ? filter_var($payload['tank_id'], FILTER_VALIDATE_INT) : false;
$command = isset($payload['command']) ? (string)$payload['command'] : '';
if (!$tankId || $tankId < 1 || !in_array($command, ['on', 'off'], true)) {
    relay_command_error(400, 'Tanque ou comando inválido.');
}

$stmt = $conn->prepare(
    "SELECT name, controller_ip
     FROM tanks
     WHERE id = ? AND type = 'piscina' AND has_controller = 1
     LIMIT 1"
);
if (!$stmt) {
    relay_command_error(500, 'Não foi possível preparar a consulta do controlador.');
}

$stmt->bind_param('i', $tankId);
if (!$stmt->execute()) {
    $stmt->close();
    relay_command_error(500, 'Não foi possível consultar o controlador.');
}

$result = $stmt->get_result();
$tank = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$tank) {
    relay_command_error(404, 'Piscina com controlador não encontrada.');
}

$controllerIp = trim((string)$tank['controller_ip']);
if (filter_var($controllerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    relay_command_error(422, 'O IP do controlador não é um endereço IPv4 válido.');
}

$endpoint = 'http://' . $controllerIp . '/relay/' . $command;
try {
    ensure_dosing_block_table($conn);
    dosing_lock($conn, $tankId);
} catch (mysqli_sql_exception | RuntimeException $error) {
    error_log('Dosing command lock error: ' . $error->getMessage());
    relay_command_error(503, 'Não foi possível preparar o comando. Tente novamente.');
}
try {
    if ($command === 'on') {
        latch_manual_dosing_block($conn, $tankId, (int)$_SESSION['user_id']);
    }
    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Não foi possível iniciar o pedido ao controlador.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $remoteResponse = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    $acknowledgement = $command === 'on' ? 'Rele ligado' : 'Rele desligado';
    $success = $remoteResponse !== false && $httpCode === 200 && trim($remoteResponse) === $acknowledgement;
    if ($success && $command === 'off') {
        reset_dosing_block($conn, $tankId);
    }
} catch (mysqli_sql_exception | RuntimeException $error) {
    error_log('Dosing command state error: ' . $error->getMessage());
    dosing_unlock($conn, $tankId);
    relay_command_error(500, $error->getMessage());
}
dosing_unlock($conn, $tankId);
$userId = (int)$_SESSION['user_id'];
$operation = $command === 'on' ? 'Bloqueio de dosagem' : 'Reset do bloqueio de dosagem';
$description = sprintf(
    '%s %s | utilizador_id=%d | piscina_id=%d | piscina=%s | controlador=%s | endpoint=%s | http=%d',
    $operation,
    $success ? 'SUCESSO' : 'FALHA',
    $userId,
    $tankId,
    $tank['name'],
    $controllerIp,
    $endpoint,
    $httpCode
);
if ($curlError !== '') {
    $description .= ' | curl_error=' . $curlError;
}
try {
    $logged = log_action($conn, $userId, $command === 'on' ? 'DOSING_BLOCK' : 'DOSING_BLOCK_RESET', $description);
} catch (mysqli_sql_exception $error) {
    error_log('Failed to save dosing command log: ' . $error->getMessage());
    $logged = false;
}

if (!$logged) {
    relay_command_error(
        500,
        $success
            ? 'O controlador aceitou o comando, mas não foi possível gravar o log. Não repita o comando apenas para registar o log. Contacte o administrador.'
            : 'O comando ao controlador falhou e não foi possível gravar o log. Contacte o administrador.'
    );
}

if (!$success) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => $remoteResponse === false
            ? 'Não foi possível comunicar com o controlador.'
            : 'O controlador não reconheceu o comando de dosagem.',
        'http_status' => $httpCode,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'command' => $command,
    'message' => $command === 'on' ? 'Comando de bloqueio de dosagem aceite.' : 'Comando de reset da dosagem aceite.',
]);
