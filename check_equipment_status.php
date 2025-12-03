<?php
// Script para ser executado em background (via Cron Job / Agendador de Tarefas)
require_once 'db.php';

date_default_timezone_set('Europe/Lisbon');

echo "--- Iniciando verificação de estado dos equipamentos em " . date('Y-m-d H:i:s') . " ---\n";

$result = $conn->query("SELECT id, name, ip_address, slave_id, last_known_state FROM remote_equipment");
if (!$result || $result->num_rows === 0) {
    echo "Nenhum equipamento remoto encontrado para verificar.\n";
    exit;
}

$equipments = $result->fetch_all(MYSQLI_ASSOC);

foreach ($equipments as $equip) {
    $equipment_id = $equip['id'];
    $last_state = $equip['last_known_state'];
    $url = "http://{$equip['ip_address']}/api/status/{$equip['slave_id']}";

    echo "A verificar: {$equip['name']}... ";

    // OTIMIZAÇÃO: Não verifica se um comando remoto foi enviado nos últimos 15 segundos
    // para evitar registos duplicados.
    $recent_command_stmt = $conn->prepare("SELECT COUNT(*) FROM equipment_log WHERE equipment_id = ? AND user_id IS NOT NULL AND timestamp > (NOW() - INTERVAL 15 SECOND)");
    $recent_command_stmt->bind_param("i", $equipment_id);
    $recent_command_stmt->execute();
    $recent_command_count = $recent_command_stmt->get_result()->fetch_row()[0];
    $recent_command_stmt->close();

    if ($recent_command_count > 0) {
        echo "Comando remoto recente detetado. A ignorar esta verificação para evitar duplicação.\n";
        continue; // Passa para o próximo equipamento
    }

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response_json = @file_get_contents($url, false, $context);

    if ($response_json === false) {
        echo "FALHA DE COMUNICAÇÃO.\n";
        continue;
    }

    $data = json_decode($response_json, true);
    if ($data === null) {
        echo "RESPOSTA INVÁLIDA (NÃO É JSON).\n";
        continue;
    }

    // Determinar o estado atual
    $current_state = 'unknown';
    if (isset($data['activeFault']) && $data['activeFault']) {
        $current_state = 'fault';
    } elseif (isset($data['isRunning']) && $data['isRunning']) {
        $current_state = 'running';
    } else {
        $current_state = 'stopped';
    }

    echo "Estado atual: $current_state (Último conhecido: $last_state).\n";

    if ($current_state !== $last_state && $current_state !== 'unknown') {
        echo ">> MUDANÇA DE ESTADO DETETADA! A registar no log...\n";

        $action = 'unknown';
        $details = "Estado alterado de '$last_state' para '$current_state'.";

        if ($current_state === 'running') $action = 'manual_run';
        if ($current_state === 'stopped') $action = 'manual_stop';
        if ($current_state === 'fault') {
            $action = 'fault_detected';
            $fault_code = isset($data['faultHex']) ? $data['faultHex'] : 'N/A';
            $details .= " Código de falha detetado: " . $fault_code;
        }

        $stmt_log = $conn->prepare("INSERT INTO equipment_log (equipment_id, user_id, action, details) VALUES (?, NULL, ?, ?)");
        $stmt_log->bind_param("iss", $equipment_id, $action, $details);
        $stmt_log->execute();
        $stmt_log->close();

        $stmt_update = $conn->prepare("UPDATE remote_equipment SET last_known_state = ? WHERE id = ?");
        $stmt_update->bind_param("si", $current_state, $equipment_id);
        $stmt_update->execute();
        $stmt_update->close();

        echo ">> Registo e atualização concluídos.\n";
    }
}

echo "--- Verificação concluída. ---\n\n";
?>

