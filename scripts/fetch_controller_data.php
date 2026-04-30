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

/**
 * Algoritmo de setpoint dinâmico assimétrico por antecipação de tendência:
 *
 *  • PV > SP_base E A DESCER  → envia SP_enviado = PV + anticipation_offset
 *    (controlador vê PV < SP_enviado → começa a dosear na descida, antes de cair abaixo da base)
 *
 *  • PV < SP_base E A SUBIR   → envia SP_enviado = PV - anticipation_offset
 *    (controlador vê PV > SP_enviado → reduz/para a doseagem na subida, evitando sobre-dosagem)
 *
 *  • Qualquer outro caso       → restaura SP_enviado = SP_base (funcionamento normal)
 */
function run_dynamic_setpoint_for_chlorine(mysqli $conn, array $pool, float $chlorine, float $baseSetpoint, ?float $pumpPercent = null): void
{
    $tankId   = (int)$pool['id'];
    $tankName = isset($pool['name']) ? $pool['name'] : ('tanque_' . $tankId);

    $keyPrefix     = 'dynamic_setpoint_tank_' . $tankId . '_ctrl_1_';
    $enabledKey    = $keyPrefix . 'enabled';
    $prevPvKey     = $keyPrefix . 'prev_pv';
    $lastSentAtKey = $keyPrefix . 'last_sent_at';
    $lastSentSpKey = $keyPrefix . 'last_sent_sp';
    $baseSpKey     = $keyPrefix . 'base_sp';   // SP base fixo definido pelo utilizador

    $enabled = get_setting_value($conn, $enabledKey, '0') === '1';
    if (!$enabled) {
        return;
    }

    // ── Parâmetros de ajuste ─────────────────────────────────────────────────
    $anticipationOffset = 0.06;  // offset base mais leve para ficar ligeiramente acima/abaixo do PV
    $minFollowOffset    = 0.03;  // mínimo acima/abaixo do PV para atuar
    $maxFollowOffset    = 0.18;  // máximo de follow offset para não exagerar
    $pumpMinTarget      = 20.0;  // % mínima desejada da bomba durante descida
    $pumpMaxTarget      = 35.0;  // % máxima desejada da bomba durante descida
    $pumpAdjustStep     = 0.02;  // ajuste de offset com base na % da bomba
    $trendDeadband      = 0.01;  // delta mínimo para considerar tendência real (filtra ruído)
    $cooldownSec        = 60;    // segundos mínimos entre envios consecutivos
    $minSendDelta       = 0.01;  // só envia se o novo SP diferir do último enviado por este valor
    // ────────────────────────────────────────────────────────────────────────

    // SP base fixo: usa o valor guardado pelo utilizador; se ainda não existir,
    // regista o C1SetPoint atual do controlador uma única vez como ponto de referência.
    $lockedBaseSp = float_or_null(get_setting_value($conn, $baseSpKey, null));
    if ($lockedBaseSp === null) {
        $lockedBaseSp = $baseSetpoint;
        set_setting_value($conn, $baseSpKey, (string)$lockedBaseSp);
    }

    $prevPv     = float_or_null(get_setting_value($conn, $prevPvKey, null));
    $lastSentSp = float_or_null(get_setting_value($conn, $lastSentSpKey, null));
    $lastSentAt = (int)(get_setting_value($conn, $lastSentAtKey, '0') ?? '0');

    // Guarda PV atual para o próximo ciclo antes de qualquer retorno antecipado
    set_setting_value($conn, $prevPvKey, (string)$chlorine);

    // Precisa de pelo menos uma leitura anterior para calcular tendência
    if ($prevPv === null) {
        return;
    }

    $deltaPv = $chlorine - $prevPv;

    // ── Decisão de setpoint ──────────────────────────────────────────────────
    // Usa sempre $lockedBaseSp como referência fixa para saber o alvo manual,
    // mas a proteção do SP dinâmico passa a depender da % da bomba e do offset
    // máximo em torno do PV atual, não da distância ao SP base.
    $reason = '';
    $decision = 'restaurar_base';
    $followOffset = 0.00;
    if ($chlorine > $lockedBaseSp && $deltaPv < -$trendDeadband) {
        // Acima da base e a descer: manter SP ligeiramente acima do PV e acompanhar a descida.
        // Se a bomba estiver baixa, sobe offset; se estiver alta, reduz offset.
        $decision = 'acima_base_a_descer';
        $followOffset = $anticipationOffset;
        if ($pumpPercent !== null) {
            if ($pumpPercent < $pumpMinTarget) {
                $followOffset += $pumpAdjustStep;
                if ($pumpPercent < 5.0) {
                    $followOffset += $pumpAdjustStep;
                }
            } elseif ($pumpPercent > $pumpMaxTarget) {
                $followOffset -= $pumpAdjustStep;
                if ($pumpPercent > 60.0) {
                    $followOffset -= $pumpAdjustStep;
                }
            }
        }
        $followOffset = clamp($followOffset, $minFollowOffset, $maxFollowOffset);
        $newDynSp = $chlorine + $followOffset;
        $reason   = "acima_base_a_descer PV={$chlorine} base={$lockedBaseSp} delta={$deltaPv} bomba=" . ($pumpPercent === null ? 'N/A' : $pumpPercent) . " offset={$followOffset}";
    } elseif ($chlorine < $lockedBaseSp && $deltaPv > $trendDeadband) {
        // Abaixo da base e a subir → reduzir doseagem
        $decision = 'abaixo_base_a_subir';
        $followOffset = $anticipationOffset;
        if ($pumpPercent !== null && $pumpPercent > $pumpMaxTarget) {
            $followOffset += ($pumpAdjustStep / 2);
        }
        $followOffset = clamp($followOffset, $minFollowOffset, $maxFollowOffset);
        $newDynSp = $chlorine - $followOffset;
        $reason   = "abaixo_base_a_subir PV={$chlorine} base={$lockedBaseSp} delta={$deltaPv} bomba=" . ($pumpPercent === null ? 'N/A' : $pumpPercent) . " offset={$followOffset}";
    } else {
        // Sem tendência relevante ou já cruzou a base → restaurar SP base
        $newDynSp = $lockedBaseSp;
        $reason   = "restaurar_base PV={$chlorine} base={$lockedBaseSp} delta={$deltaPv} bomba=" . ($pumpPercent === null ? 'N/A' : $pumpPercent);
    }

    // Segurança baseada na operação: quando segue o PV, o SP dinâmico fica sempre
    // limitado pelo followOffset e pela resposta da bomba, não por um teto relativo
    // ao SP manual. Apenas garantimos um domínio físico plausível.
    $newDynSp = clamp($newDynSp, 0.00, 10.00);
    $newDynSp = round($newDynSp, 2);
    $calculationSummary = "decision={$decision} PV=" . round($chlorine, 2) . " prevPV=" . round($prevPv, 2) . " delta=" . round($deltaPv, 4) . " base=" . round($lockedBaseSp, 2) . " pump=" . ($pumpPercent === null ? 'N/A' : round($pumpPercent, 2)) . " offset=" . round($followOffset, 4) . " newSP={$newDynSp}";
    // ────────────────────────────────────────────────────────────────────────

    // Cooldown entre envios
    $now = time();
    if ($now - $lastSentAt < $cooldownSec) {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_SKIP_COOLDOWN',
            "Tanque {$tankName} ({$tankId}) ctrl=1 {$calculationSummary} skipped=cooldown remaining=" . max(0, $cooldownSec - ($now - $lastSentAt))
        );
        return;
    }

    // Só envia se o valor mudou de forma significativa
    if ($lastSentSp !== null && abs($newDynSp - $lastSentSp) < $minSendDelta) {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_SKIP_DELTA',
            "Tanque {$tankName} ({$tankId}) ctrl=1 {$calculationSummary} skipped=min_delta lastSent=" . round($lastSentSp, 2)
        );
        return;
    }

    $send = send_remote_setpoint(1, $newDynSp);
    if (!$send['ok']) {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_APPLY_FAIL',
            "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} ({$reason}) {$calculationSummary} falhou HTTP {$send['http_code']} erro={$send['error']}"
        );
        return;
    }

    set_setting_value($conn, $lastSentAtKey, (string)$now);
    set_setting_value($conn, $lastSentSpKey, (string)$newDynSp);
    log_system_action(
        $conn,
        'DYNAMIC_SETPOINT_APPLY_OK',
        "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} ({$reason}) {$calculationSummary} aplicado com sucesso"
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
                $chlorinePumpPercent = float_or_null($cl_estado);
                if ($settingsReady && $chlorineValue !== null && $chlorineSetpoint !== null) {
                    run_dynamic_setpoint_for_chlorine($conn, $pool, $chlorineValue, $chlorineSetpoint, $chlorinePumpPercent);
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