<?php
require_once 'db.php';
session_start();

// --- Validação e Segurança ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Sessão não iniciada.']);
    exit;
}

if (!isset($_GET['command']) || !isset($_GET['slave_id']) || !isset($_GET['ip'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parâmetros em falta.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$command = $_GET['command'];
$slave_id = (int)$_GET['slave_id'];
$ip_address = $_GET['ip'];

// --- Lógica de Registo (Logging) ---
$stmt = $conn->prepare("SELECT id FROM remote_equipment WHERE slave_id = ? AND ip_address = ?");
$stmt->bind_param("is", $slave_id, $ip_address);
$stmt->execute();
$result = $stmt->get_result();
$equipment = $result->fetch_assoc();
$stmt->close();

$equipment_id = null;
if ($equipment) {
    $equipment_id = $equipment['id'];
    $details = "Comando executado pelo utilizador ID: " . $user_id;

    $log_stmt = $conn->prepare("INSERT INTO equipment_log (equipment_id, user_id, action, details) VALUES (?, ?, ?, ?)");
    $log_stmt->bind_param("iiss", $equipment_id, $user_id, $command, $details);
    $log_stmt->execute();
    $log_stmt->close();
}

// --- Reencaminhamento do Comando para o Arduino ---
$arduino_url = "http://" . $ip_address . "/" . $command . "/" . $slave_id;
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $arduino_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$arduino_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// =================================================================================
// <<< NOVA LÓGICA: ATUALIZAR O ESTADO IMEDIATAMENTE APÓS O COMANDO >>>
// =================================================================================
if ($arduino_response !== false && $http_code === 200 && $equipment_id) {
    // Determina o novo estado com base no comando enviado
    $new_state = 'unknown';
    if ($command === 'run') $new_state = 'running';
    if ($command === 'stop') $new_state = 'stopped';
    if ($command === 'clear_fault') $new_state = 'stopped'; // Após limpar falha, assume-se que para.

    if ($new_state !== 'unknown') {
        $stmt_update = $conn->prepare("UPDATE remote_equipment SET last_known_state = ? WHERE id = ?");
        $stmt_update->bind_param("si", $new_state, $equipment_id);
        $stmt_update->execute();
        $stmt_update->close();
    }
}

// --- Devolver a resposta ao dashboard ---
header('Content-Type: application/json');
if ($arduino_response === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Erro de comunicação: ' . $curl_error]);
} else {
    json_decode($arduino_response);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $arduino_response;
    } else {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Resposta inválida do controlador.']);
    }
}
?>
