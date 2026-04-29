<?php
// Este script é desenhado para ser executado pelo servidor, não por um utilizador.
// Incluímos os ficheiros essenciais.
require_once dirname(__DIR__) . '/core.php';

/**
 * Remove registos de histórico mais antigos que 730 dias.
 */
function cleanup_old_controller_history(mysqli $conn): void
{
    $stmt_delete = $conn->prepare("DELETE FROM controller_history WHERE log_datetime < DATE_SUB(NOW(), INTERVAL 730 DAY)");

    if ($stmt_delete === false) {
        echo "Erro ao preparar a limpeza de histórico antigo: " . $conn->error . "\n";
        return;
    }

    if ($stmt_delete->execute()) {
        echo "Limpeza concluída: " . $stmt_delete->affected_rows . " registos antigos removidos.\n";
    } else {
        echo "Erro ao executar limpeza de histórico antigo: " . $stmt_delete->error . "\n";
    }

    $stmt_delete->close();
}

function ensure_settings_table(mysqli $conn): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS `settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(191) NOT NULL,
        `setting_value` text DEFAULT NULL,
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    return $conn->query($sql) === true;
}

function get_setting_value(mysqli $conn, string $key, ?string $default = null): ?string
{
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }

    $stmt->bind_param("s", $key);
    if (!$stmt->execute()) {
        $stmt->close();
        return $default;
    }

    $result = $stmt->get_result();
    $value = $default;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $value = isset($row['setting_value']) ? (string)$row['setting_value'] : $default;
    }
    $stmt->close();

    return $value;
}

function set_setting_value(mysqli $conn, string $key, string $value): bool
{
    $stmtUpdate = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
    if (!$stmtUpdate) {
        return false;
    }

    $stmtUpdate->bind_param("ss", $value, $key);
    $okUpdate = $stmtUpdate->execute();
    $affected = $stmtUpdate->affected_rows;
    $stmtUpdate->close();

    if (!$okUpdate) {
        return false;
    }

    if ($affected > 0) {
        return true;
    }

    $stmtInsert = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
    if (!$stmtInsert) {
        return false;
    }

    $stmtInsert->bind_param("ss", $key, $value);
    $okInsert = $stmtInsert->execute();
    $stmtInsert->close();

    return $okInsert;
}

function float_or_null($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function clamp(float $v, float $min, float $max): float
{
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
}

function log_system_action(mysqli $conn, string $action, string $description): void
{
    $stmt = $conn->prepare("INSERT INTO logs (user_id, action, description) VALUES (NULL, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("ss", $action, $description);
    $stmt->execute();
    $stmt->close();
}

function send_remote_setpoint(int $ctrl, float $value): array
{
    $endpoint = 'http://191.188.127.30/';
    $postFields = http_build_query([
        'ctrl' => $ctrl,
        'val' => number_format($value, 2, '.', ''),
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $ok = ($response !== false && $httpCode >= 200 && $httpCode < 300);

    return [
        'ok' => $ok,
        'http_code' => (int)$httpCode,
        'error' => $curlError,
        'response' => is_string($response) ? $response : '',
    ];
}

function run_dynamic_setpoint_for_chlorine(mysqli $conn, array $pool, float $chlorine, float $baseSetpoint): void
{
    $tankId = (int)$pool['id'];
    $tankName = isset($pool['name']) ? $pool['name'] : ('tanque_' . $tankId);

    $keyPrefix = 'dynamic_setpoint_tank_' . $tankId . '_ctrl_1_';
    $enabledKey = $keyPrefix . 'enabled';
    $prevPvKey = $keyPrefix . 'prev_pv';
    $lastDynKey = $keyPrefix . 'last_dyn_sp';
    $lastSentAtKey = $keyPrefix . 'last_sent_at';
    $lastSentSpKey = $keyPrefix . 'last_sent_sp';

    $enabled = get_setting_value($conn, $enabledKey, '0') === '1';
    if (!$enabled) {
        return;
    }

    $deadband = 0.03;
    $kp = 0.40;
    $maxOffset = 0.15;
    $maxStepPerCycle = 0.03;
    $cooldownSec = 120;
    $minSendDelta = 0.02;

    $prevPv = float_or_null(get_setting_value($conn, $prevPvKey, null));
    $lastDynSp = float_or_null(get_setting_value($conn, $lastDynKey, null));
    $lastSentSp = float_or_null(get_setting_value($conn, $lastSentSpKey, null));
    $lastSentAt = (int)(get_setting_value($conn, $lastSentAtKey, '0') ?? '0');

    if ($lastDynSp === null) {
        $lastDynSp = $baseSetpoint;
    }

    $error = $chlorine - $baseSetpoint;
    $deltaPv = ($prevPv !== null) ? ($chlorine - $prevPv) : null;

    $targetSp = $baseSetpoint;
    if (abs($error) > $deadband) {
        // Regra de segurança: se está acima do SP e ainda a subir, não atua já.
        if (!($chlorine > $baseSetpoint && $deltaPv !== null && $deltaPv >= 0)) {
            $targetSp = $baseSetpoint - ($kp * $error);
        }
    }

    $targetSp = clamp($targetSp, $baseSetpoint - $maxOffset, $baseSetpoint + $maxOffset);
    $newDynSp = $lastDynSp + clamp($targetSp - $lastDynSp, -$maxStepPerCycle, $maxStepPerCycle);
    $newDynSp = round($newDynSp, 2);

    set_setting_value($conn, $prevPvKey, (string)$chlorine);
    set_setting_value($conn, $lastDynKey, (string)$newDynSp);

    $now = time();
    if ($now - $lastSentAt < $cooldownSec) {
        return;
    }

    if ($lastSentSp !== null && abs($newDynSp - $lastSentSp) < $minSendDelta) {
        return;
    }

    $send = send_remote_setpoint(1, $newDynSp);
    if (!$send['ok']) {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_APPLY_FAIL',
            "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} falhou HTTP {$send['http_code']} erro={$send['error']}"
        );
        return;
    }

    set_setting_value($conn, $lastSentAtKey, (string)$now);
    set_setting_value($conn, $lastSentSpKey, (string)$newDynSp);
    log_system_action(
        $conn,
        'DYNAMIC_SETPOINT_APPLY_OK',
        "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} aplicado com sucesso"
    );
}

// 1. Buscar todas as piscinas que têm um controlador ativo.
$tanks_stmt = $conn->query("SELECT id, name, controller_ip FROM tanks WHERE has_controller = 1 AND controller_ip IS NOT NULL");
$pools_with_controllers = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

$settingsReady = ensure_settings_table($conn);
if (!$settingsReady) {
    echo "Aviso: tabela settings indisponivel, setpoint dinamico sera ignorado.\n";
}

// Prepara a query de INSERT uma vez para ser reutilizada.
$stmt_insert = $conn->prepare("
    INSERT INTO controller_history 
    (tank_id, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, temperature_value, cl_controller_state, ph_controller_state, cl_disturbance, ph_disturbance) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

echo "A iniciar a busca de dados dos controladores...\n";

// 2. Faz um ciclo por cada piscina.
foreach ($pools_with_controllers as $pool) {
    $tank_id = $pool['id'];
    $ip = $pool['controller_ip'];
    $url = "http://" . $ip . "/ajax_inputs";

    // 3. Usa cURL para ir buscar o conteúdo do XML.
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);

    $response_text = curl_exec($ch);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response_text === false) {
        echo "Erro cURL no tanque '{$pool['name']}': " . curl_error($ch) . "\n";
    }

    curl_close($ch);

    if ($http_code === 200 && $response_text) {
        // 4. Se a resposta for XML, converte-a.
        if (strpos(trim($response_text), '<?xml') === 0) {
            try {
                $xml = new SimpleXMLElement($response_text);
                $data = json_decode(json_encode($xml), true);

                // ATENÇÃO: As chaves aqui ('ph', 'cloro_livre', etc.) devem corresponder
                // exatamente às tags do seu ficheiro XML.
                $ph = isset($data['pH']) ? $data['pH'] : null;
                $ph_sp = isset($data['C2SetPoint']) ? $data['C2SetPoint'] : null;
                $cloro = isset($data['freeChlorine']) ? $data['freeChlorine'] : null;
                $cloro_sp = isset($data['C1SetPoint']) ? $data['C1SetPoint'] : null;
                $temp = isset($data['temperature']) ? $data['temperature'] : null;
                $ph_estado = isset($data['C2Value']) ? $data['C2Value'] : null;
				$cl_estado = isset($data['C1Value']) ? $data['C1Value'] : null;
                $ph_disturbio = isset($data['C2Disturbance']) ? $data['C2Disturbance'] : null;
				$cl_disturbio = isset($data['C1Disturbance']) ? $data['C1Disturbance'] : null;

                // 5. Insere os dados na tabela de histórico.
                $stmt_insert->bind_param("idddddssss", $tank_id, $ph, $ph_sp, $cloro, $cloro_sp, $temp, $cl_estado, $ph_estado, $cl_disturbio, $ph_disturbio);
                $stmt_insert->execute();

                echo "Dados do tanque '" . $pool['name'] . "' inseridos com sucesso.\n";

                $chlorineValue = float_or_null($cloro);
                $chlorineSetpoint = float_or_null($cloro_sp);
                if ($settingsReady && $chlorineValue !== null && $chlorineSetpoint !== null) {
                    run_dynamic_setpoint_for_chlorine($conn, $pool, $chlorineValue, $chlorineSetpoint);
                }

            } catch (Exception $e) {
                echo "Erro ao processar XML para o tanque '" . $pool['name'] . "': " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "Falha ao contactar o controlador para o tanque '" . $pool['name'] . "' no IP: " . $ip . "\n";
    }
}

$stmt_insert->close();
cleanup_old_controller_history($conn);
$conn->close();
echo "Processo concluído.\n";
?>