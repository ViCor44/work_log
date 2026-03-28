<?php
header('Content-Type: application/json');
require_once '../db.php'; // Garanta que o caminho para db.php está correto

date_default_timezone_set('Europe/Lisbon');

// --- Validação ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do equipamento inválido.']);
    exit;
}
$equipment_id = (int)$_GET['id'];

// --- 1. Ir buscar o IP, Slave ID e último estado conhecido do equipamento ---
$stmt_equip = $conn->prepare("SELECT ip_address, slave_id, last_known_state FROM remote_equipment WHERE id = ?");
$stmt_equip->bind_param("i", $equipment_id);
$stmt_equip->execute();
$equipment = $stmt_equip->get_result()->fetch_assoc();
$stmt_equip->close();

if (!$equipment) {
    http_response_code(404);
    echo json_encode(['error' => 'Equipamento não encontrado.']);
    exit;
}
$ip_address = $equipment['ip_address'];
$slave_id = $equipment['slave_id'];
$last_known_state = $equipment['last_known_state'];

// --- 2. Ir buscar o estado em tempo real ao Arduino ---
$live_data = null;
$url = "http://{$ip_address}/api/status/{$slave_id}";
$context = stream_context_create(['http' => ['timeout' => 5]]);
$response_json = @file_get_contents($url, false, $context);
if ($response_json) {
    $live_data = json_decode($response_json, true);
}

// --- LÓGICA PARA REGISTAR FALHAS EM TEMPO REAL ---
$current_state = 'unknown';
if ($live_data) {
    if (isset($live_data['activeFault']) && $live_data['activeFault'] === true) {
        $current_state = 'fault';
    } elseif (isset($live_data['isRunning']) && $live_data['isRunning'] === true) {
        $current_state = 'running';
    } else {
        $current_state = 'stopped';
    }
}

if ($current_state === 'fault' && $last_known_state !== 'fault') {
    $fault_code = isset($live_data['faultHex']) ? $live_data['faultHex'] : 'N/A';
    $details = "Estado alterado para 'fault'. Código de falha detetado: " . $fault_code;
    
    $stmt_log = $conn->prepare("INSERT INTO equipment_log (equipment_id, user_id, action, details, timestamp) VALUES (?, NULL, 'fault_detected', ?, NOW())");
    $stmt_log->bind_param("is", $equipment_id, $details);
    $stmt_log->execute();
    $stmt_log->close();

    $stmt_update = $conn->prepare("UPDATE remote_equipment SET last_known_state = 'fault' WHERE id = ?");
    $stmt_update->bind_param("i", $equipment_id);
    $stmt_update->execute();
    $stmt_update->close();
}


// --- 3. Ir buscar o último COMANDO registado na BD ---
$last_command = null;
$stmt_cmd = $conn->prepare("
    SELECT log.timestamp, log.action, COALESCE(usr.username, 'Sistema / Manual') AS user_name
    FROM equipment_log AS log
    LEFT JOIN users AS usr ON log.user_id = usr.id
    WHERE log.equipment_id = ? AND (log.action LIKE '%run' OR log.action LIKE '%stop' OR log.action LIKE '%clear_fault')
    ORDER BY log.timestamp DESC LIMIT 1
");
$stmt_cmd->bind_param("i", $equipment_id);
$stmt_cmd->execute();
$last_command = $stmt_cmd->get_result()->fetch_assoc();
$stmt_cmd->close();

// =================================================================================
// <<< LÓGICA CORRIGIDA PARA DETERMINAR A ÚLTIMA FALHA >>>
// =================================================================================
$final_last_fault = null;

// Prioridade 1: A falha que está na memória do Arduino agora.
if ($live_data && isset($live_data['faultHex']) && hexdec($live_data['faultHex']) != 0) {
    $live_fault_code = $live_data['faultHex'];

    // Com o código da falha, vamos à BD buscar a data/hora do último registo dessa falha
    $stmt_fault_time = $conn->prepare("
        SELECT timestamp FROM equipment_log
        WHERE equipment_id = ? AND action = 'fault_detected' AND details LIKE ?
        ORDER BY timestamp DESC LIMIT 1
    ");
    $like_pattern = "%" . $live_fault_code . "%";
    $stmt_fault_time->bind_param("is", $equipment_id, $like_pattern);
    $stmt_fault_time->execute();
    $fault_time_result = $stmt_fault_time->get_result()->fetch_assoc();
    $stmt_fault_time->close();

    // Monta o objeto da falha final
    $final_last_fault = [
        'details' => 'Código de falha detetado: ' . $live_fault_code,
        'timestamp' => $fault_time_result ? $fault_time_result['timestamp'] : null
    ];
} else {
    // Prioridade 2: Se o Arduino está limpo, procura a última falha no histórico da BD
    $stmt_fault = $conn->prepare("
        SELECT timestamp, details
        FROM equipment_log
        WHERE equipment_id = ? AND action = 'fault_detected'
        ORDER BY timestamp DESC LIMIT 1
    ");
    $stmt_fault->bind_param("i", $equipment_id);
    $stmt_fault->execute();
    $final_last_fault = $stmt_fault->get_result()->fetch_assoc();
    $stmt_fault->close();
}


// --- 5. Combinar tudo e devolver como JSON ---
echo json_encode([
    'live_status' => $live_data,
    'last_command' => $last_command,
    'last_fault' => $final_last_fault // Usa a informação da falha determinada pela nova lógica
]);
?>

