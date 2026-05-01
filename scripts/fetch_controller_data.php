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

function send_remote_setpoint(int $ctrl, float $value, string $endpoint): array
{
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
    $controllerIp = isset($pool['controller_ip']) ? trim((string)$pool['controller_ip']) : '';

    if ($controllerIp === '') {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_SKIP_NO_CONTROLLER_IP',
            "Tanque {$tankName} ({$tankId}) sem controller_ip configurado; setpoint dinâmico ignorado"
        );
        return;
    }

    $endpoint = 'http://' . $controllerIp . '/';

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

    // ── Parâmetros de ajuste (lidos da DB; fallback para os valores padrão) ──
    $pPrefix = 'dynamic_setpoint_tank_' . $tankId . '_ctrl_1_param_';
    $anticipationOffset = (float)(get_setting_value($conn, $pPrefix . 'anticipation_offset', null) ?? 0.06);
    $minFollowOffset    = (float)(get_setting_value($conn, $pPrefix . 'min_follow_offset',   null) ?? 0.03);
    $maxFollowOffset    = (float)(get_setting_value($conn, $pPrefix . 'max_follow_offset',   null) ?? 0.18);
    $pumpMinTarget      = (float)(get_setting_value($conn, $pPrefix . 'pump_min_target',     null) ?? 20.0);
    $pumpMaxTarget      = (float)(get_setting_value($conn, $pPrefix . 'pump_max_target',     null) ?? 35.0);
    $pumpAdjustStep     = (float)(get_setting_value($conn, $pPrefix . 'pump_adjust_step',    null) ?? 0.02);
    $trendDeadband      = (float)(get_setting_value($conn, $pPrefix . 'trend_deadband',      null) ?? 0.01);
    $cooldownSec        = (float)(get_setting_value($conn, $pPrefix . 'cooldown_sec',        null) ?? 60.0);
    $minSendDelta       = (float)(get_setting_value($conn, $pPrefix . 'min_send_delta',      null) ?? 0.01);
    $nightStartHour     = (int)(float)(get_setting_value($conn, $pPrefix . 'night_start_hour', null) ?? 22.0);
    $nightEndHour       = (int)(float)(get_setting_value($conn, $pPrefix . 'night_end_hour',   null) ?? 7.0);
    $nightMinExcessOverBase = (float)(get_setting_value($conn, $pPrefix . 'night_min_excess_over_base', null) ?? 0.25);
    $nightMinDropDelta  = (float)(get_setting_value($conn, $pPrefix . 'night_min_drop_delta', null) ?? 0.02);

    $nightStartHour = max(0, min(23, $nightStartHour));
    $nightEndHour   = max(0, min(23, $nightEndHour));
    $nightMinExcessOverBase = max(0.0, $nightMinExcessOverBase);
    $nightMinDropDelta = max(0.0, $nightMinDropDelta);

    // ── Alta Afluência: parâmetros alternativos para dias com maior afluxo ──
    $haSettingKey       = 'dynamic_setpoint_tank_' . $tankId . '_ctrl_1_high_attendance';
    $isHighAttendance   = get_setting_value($conn, $haSettingKey, '0') === '1';
    $haAnticipationOffset = $anticipationOffset;
    $haMinFollowOffset    = $minFollowOffset;
    $haMaxFollowOffset    = $maxFollowOffset;
    $haPumpMinTarget      = $pumpMinTarget;
    $haPumpMaxTarget      = $pumpMaxTarget;
    $haPumpAdjustStep     = $pumpAdjustStep;
    if ($isHighAttendance) {
        $haAnticipationOffset = (float)(get_setting_value($conn, $pPrefix . 'ha_anticipation_offset', null) ?? 0.12);
        $haMinFollowOffset    = (float)(get_setting_value($conn, $pPrefix . 'ha_min_follow_offset',   null) ?? 0.06);
        $haMaxFollowOffset    = (float)(get_setting_value($conn, $pPrefix . 'ha_max_follow_offset',   null) ?? 0.35);
        $haPumpMinTarget      = (float)(get_setting_value($conn, $pPrefix . 'ha_pump_min_target',     null) ?? 12.0);
        $haPumpMaxTarget      = (float)(get_setting_value($conn, $pPrefix . 'ha_pump_max_target',     null) ?? 45.0);
        $haPumpAdjustStep     = (float)(get_setting_value($conn, $pPrefix . 'ha_pump_adjust_step',    null) ?? 0.04);
    }
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
    $hourNow = (int)date('G');
    $isNight = ($nightStartHour <= $nightEndHour)
        ? ($hourNow >= $nightStartHour && $hourNow < $nightEndHour)
        : ($hourNow >= $nightStartHour || $hourNow < $nightEndHour);

    $requiredExcessOverBase = $isNight ? $nightMinExcessOverBase : 0.0;
    $requiredDropDelta = $isNight ? max($trendDeadband, $nightMinDropDelta) : $trendDeadband;

    // ── Decisão de setpoint ──────────────────────────────────────────────────
    // Enquanto PV está ACIMA da base (+ margem noturna), o SP segue sempre o PV
    // de cima, independentemente de a tendência momentânea ser positiva ou negativa.
    // Isto evita que ticks pontuais para cima façam o SP colapsar para a base e
    // parem a bomba, causando a quebra de doseagem visível no histórico.
    // O offset varia com a % da bomba para auto-regular sem sobre-dosear.
    $reason = '';
    $decision = 'restaurar_base';
    $followOffset = 0.00;
    if ($chlorine > ($lockedBaseSp + $requiredExcessOverBase) && $deltaPv <= $trendDeadband) {
        // PV acima da base e estável ou a descer: SP segue PV de cima para não largar a dosagem.
        // Quando o PV está a SUBIR acima da base não empurramos o SP para cima — restauramos a base
        // para que o controlador pare a bomba e evite sobre-cloração.
        if ($deltaPv < -$requiredDropDelta) {
            $trendLabel = 'a_descer';
        } else {
            $trendLabel = 'estavel';
        }
        if ($isNight) {
            $decision = 'acima_base_noite_' . $trendLabel;
        } elseif ($isHighAttendance) {
            $decision = 'acima_base_HA_' . $trendLabel;
        } else {
            $decision = 'acima_base_' . $trendLabel;
        }
        $followOffset = $haAnticipationOffset;
        if ($pumpPercent !== null) {
            if ($pumpPercent < $haPumpMinTarget) {
                $followOffset += $haPumpAdjustStep;
                if ($pumpPercent < 5.0) {
                    $followOffset += $haPumpAdjustStep;
                }
            } elseif ($pumpPercent > $haPumpMaxTarget) {
                $followOffset -= $haPumpAdjustStep;
                if ($pumpPercent > 60.0) {
                    $followOffset -= $haPumpAdjustStep;
                }
            }
        }
        $followOffset = clamp($followOffset, $haMinFollowOffset, $haMaxFollowOffset);
        $newDynSp = $chlorine + $followOffset;
        $reason   = $decision . " PV={$chlorine} base={$lockedBaseSp} delta={$deltaPv} bomba=" . ($pumpPercent === null ? 'N/A' : $pumpPercent) . " offset={$followOffset}";
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
    $calculationSummary = "decision={$decision} mode=" . ($isNight ? 'night' : 'day') . " ha=" . ($isHighAttendance ? '1' : '0') . " hour={$hourNow} reqExcess=" . round($requiredExcessOverBase, 3) . " reqDrop=" . round($requiredDropDelta, 4) . " PV=" . round($chlorine, 2) . " prevPV=" . round($prevPv, 2) . " delta=" . round($deltaPv, 4) . " base=" . round($lockedBaseSp, 2) . " pump=" . ($pumpPercent === null ? 'N/A' : round($pumpPercent, 2)) . " offset=" . round($followOffset, 4) . " newSP={$newDynSp}";
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

    $send = send_remote_setpoint(1, $newDynSp, $endpoint);
    if (!$send['ok']) {
        log_system_action(
            $conn,
            'DYNAMIC_SETPOINT_APPLY_FAIL',
            "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} endpoint={$endpoint} ({$reason}) {$calculationSummary} falhou HTTP {$send['http_code']} erro={$send['error']}"
        );
        return;
    }

    set_setting_value($conn, $lastSentAtKey, (string)$now);
    set_setting_value($conn, $lastSentSpKey, (string)$newDynSp);
    log_system_action(
        $conn,
        'DYNAMIC_SETPOINT_APPLY_OK',
        "Tanque {$tankName} ({$tankId}) ctrl=1 val={$newDynSp} endpoint={$endpoint} ({$reason}) {$calculationSummary} aplicado com sucesso"
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