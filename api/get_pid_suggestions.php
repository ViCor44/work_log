<?php
// Desabilita warnings/notices que podem quebrar JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Inicia output buffering para evitar headers acidentais
ob_start();

// Coloca header JSON ANTES de qualquer require
header('Content-Type: application/json; charset=utf-8');

// Agora requer o core
require_once '../core.php';

// Função helper para retornar JSON de erro com buffer limpo
function return_json_error($error, $code = 400) {
    http_response_code($code);
    if (ob_get_length()) {
        ob_end_clean();
    }
    echo json_encode(['error' => $error]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    return_json_error('Acesso não autorizado', 401);
}

if (!isset($_GET['tank_id']) || !is_numeric($_GET['tank_id'])) {
    return_json_error('ID do tanque inválido', 400);
}

$tank_id = (int)$_GET['tank_id'];
$days = isset($_GET['days']) && is_numeric($_GET['days']) && $_GET['days'] > 0 ? (int)$_GET['days'] : 3;
$start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

// Busca os dados do tanque e valores atuais de PID (se existirem colunas PID)
// Primeiro, verifica se as colunas PID existem
$cols = array();
if ($res = $conn->query("SHOW COLUMNS FROM `tanks`")) {
    while ($row = $res->fetch_assoc()) {
        $cols[$row['Field']] = true;
    }
    $res->free();
}

$has_pid_cols = isset($cols['pid_p']) && isset($cols['pid_i']) && isset($cols['pid_d']);

if ($has_pid_cols) {
    $select_clause = "SELECT id, name, pid_p, pid_i, pid_d FROM tanks WHERE id = ? LIMIT 1";
} else {
    $select_clause = "SELECT id, name FROM tanks WHERE id = ? LIMIT 1";
}

$stmt_tank = $conn->prepare($select_clause);
if (!$stmt_tank) {
    return_json_error('Erro ao preparar consulta de tanque: ' . $conn->error, 500);
}
$stmt_tank->bind_param('i', $tank_id);
if (!$stmt_tank->execute()) {
    return_json_error('Erro ao executar consulta de tanque: ' . $stmt_tank->error, 500);
}
$result_tank = $stmt_tank->get_result();
if ($result_tank->num_rows === 0) {
    return_json_error('Tanque não encontrado', 404);
}
$tank = $result_tank->fetch_assoc();
$stmt_tank->close();


// Agora busca também o estado do controlador de cloro
$stmt = $conn->prepare("SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime >= ? ORDER BY log_datetime ASC");
if (!$stmt) {
    return_json_error('Erro ao preparar consulta de histórico: ' . $conn->error, 500);
}
$stmt->bind_param('is', $tank_id, $start_date);
if (!$stmt->execute()) {
    return_json_error('Erro ao executar consulta de histórico: ' . $stmt->error, 500);
}
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Se não há dados recentes, busca os últimos 100 registros disponíveis
if (!$history) {
    $stmt = $conn->prepare("SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint FROM controller_history WHERE tank_id = ? ORDER BY log_datetime DESC LIMIT 100");
    if (!$stmt) {
        return_json_error('Erro ao preparar consulta de fallback: ' . $conn->error, 500);
    }
    $stmt->bind_param('i', $tank_id);
    if (!$stmt->execute()) {
        return_json_error('Erro ao executar consulta de fallback: ' . $stmt->error, 500);
    }
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    // Reordena por data ascendente
    if ($history) {
        usort($history, function($a, $b) {
            return strtotime($a['log_datetime']) - strtotime($b['log_datetime']);
        });
    }
    $days = 'últimos disponíveis';
}

if (!$history) {
    if (ob_get_length()) {
        ob_end_clean();
    }
    echo json_encode([
        'tank_id' => $tank_id,
        'tank_name' => $tank['name'],
        'days' => $days,
        'message' => 'Sem dados de controlador disponíveis.',
        'suggestions' => []
    ]);
    exit;
}

function floatOrNull($value) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function calcStats($errors, $times) {
    $n = count($errors);
    if ($n === 0) return null;

    $sum = 0;
    $sumAbs = 0;
    $min = $errors[0];
    $max = $errors[0];
    foreach ($errors as $e) {
        $sum += $e;
        $sumAbs += abs($e);
        if ($e < $min) $min = $e;
        if ($e > $max) $max = $e;
    }
    $mean = $sum / $n;
    $meanAbs = $sumAbs / $n;

    $varSum = 0;
    foreach ($errors as $e) {
        $varSum += pow($e - $mean, 2);
    }
    $stdev = $n > 1 ? sqrt($varSum / ($n - 1)) : 0;

    $signChanges = 0;
    $prevSign = null;
    foreach ($errors as $e) {
        if ($e == 0) continue;
        $sign = $e > 0 ? 1 : -1;
        if ($prevSign !== null && $sign !== $prevSign) {
            $signChanges++;
        }
        $prevSign = $sign;
    }
    $signChangeRate = $n > 1 ? $signChanges / ($n - 1) : 0;

    $deriv = [];
    for ($i = 1; $i < $n; $i++) {
        $dt = max(1, strtotime($times[$i]) - strtotime($times[$i - 1]));
        $deriv[] = abs($errors[$i] - $errors[$i - 1]) / $dt;
    }
    $derivMean = $deriv ? array_sum($deriv) / count($deriv) : 0;

    return [
        'samples' => $n,
        'mean' => $mean,
        'mean_abs' => $meanAbs,
        'min' => $min,
        'max' => $max,
        'stdev' => $stdev,
        'sign_changes' => $signChanges,
        'sign_change_rate' => $signChangeRate,
        'derivative_mean' => $derivMean,
    ];
}

function pidRecommendations($mode, $stats, $currentPid) {
    $suggestions = [];
    $p = isset($currentPid['p']) ? floatOrNull($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? floatOrNull($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? floatOrNull($currentPid['d']) : null;

    $targetMap = ['ph' => 0.15, 'chlorine' => 0.25];
    $tightMap = ['ph' => 0.1, 'chlorine' => 0.20];

    $errThreshold = isset($targetMap[$mode]) ? $targetMap[$mode] : 0.2;
    $tightThreshold = isset($tightMap[$mode]) ? $tightMap[$mode] : 0.15;

    // Sugestões de valores
    $suggestedP = $p;
    $suggestedI = $i;
    $suggestedD = $d;

    $hasOscillations = $stats['stdev'] > max($errThreshold, 0.2);
    $hasHighError = $stats['mean_abs'] > $errThreshold;
    $hasBias = abs($stats['mean']) > ($tightThreshold * 0.75) && $stats['sign_change_rate'] < 0.35;
    $hasRapidReversals = $stats['sign_change_rate'] > 0.45 && isset($stats['derivative_mean']) && $stats['derivative_mean'] > 0.0002;

    // Prioridade: oscilações indicam instabilidade, então reduzir P e aumentar D
    if ($hasOscillations) {
        $suggestions[] = 'Oscilações detectadas (desvio padrão ' . round($stats['stdev'], 3) . '); reduzir Kp e aumentar Kd para estabilizar.';
        if ($p !== null) $suggestedP = $p * 0.90; // Reduz 10%
        if ($d !== null) $suggestedD = $d * 1.20; // Aumenta 20%
    } elseif ($hasHighError) {
        // Só aumenta P se não houver oscilações
        $suggestions[] = 'Erro médio alto (' . round($stats['mean_abs'], 3) . ') indica que o sistema está fora do setpoint; considere aumentar Kp gradualmente (+10-20%).';
        if ($p !== null) $suggestedP = $p * 1.15; // Aumenta 15%
    }

    if ($hasBias) {
        if ($i === null || $i <= 0) {
            $suggestions[] = 'Viés persistente (média ' . round($stats['mean'], 3) . ') sugere integral ineficaz (Ti=0 ou não configurado); considere definir Ti > 0 (por exemplo 1-5) para ativar ação integral.';
            $suggestedI = 2.0; // Valor inicial sugerido para ativar integral
        } elseif ($i >= 500) {
            $suggestions[] = 'Viés persistente (média ' . round($stats['mean'], 3) . ') sugere integral muito fraca (Ti muito alto: ' . round($i, 2) . '); reduzir Ti em 20-30% para fortalecer ação integral.';
            $suggestedI = $i * 0.75; // Reduz Ti 25%
        } else {
            $suggestions[] = 'Viés persistente (média ' . round($stats['mean'], 3) . ') sugere integral fraca; considere reduzir Ti em 10-15% (Ki aumenta).';
            $suggestedI = $i * 0.88; // Reduz Ti 12%
        }
    }

    // Reforço: se Ti estiver ausente/zero e houver erro significativo,
    // oferece um Ti inicial mesmo quando o viés não foi classificado como persistente.
    if (($i === null || $i <= 0) && $suggestedI === $i) {
        $needsIntegralKickstart = $hasHighError && !$hasRapidReversals;
        if ($needsIntegralKickstart) {
            $suggestedI = $hasOscillations ? 4.0 : 2.0;
            $suggestions[] = 'Ti não definido (ou igual a 0) com erro elevado; sugerido Ti inicial de ' . round($suggestedI, 2) . ' para introduzir ação integral de forma gradual.';
        }
    }

    if ($hasRapidReversals) {
        $suggestions[] = 'Erro com muitas reversões rápidas; aumentar Td para amortecer.';
        if ($d !== null) $suggestedD = $d * 1.25; // Aumenta Td 25%
        // Não reduz Kp aqui se já foi reduzido por oscilações
        if (!$hasOscillations && $p !== null) $suggestedP = $p * 0.95; // Reduz ligeiramente Kp
    }

    if (count($suggestions) === 0) {
        $suggestions[] = 'Controlador parece estável para o período analisado. Ajustes menores podem focar em otimização fina.';
    }

    if ($p !== null) {
        $suggestions[] = 'Kp atual: ' . $p . ' → Sugerido: ' . round($suggestedP, 6);
    }
    if ($i !== null || $suggestedI !== null) {
        $currentIText = ($i === null || $i <= 0) ? 'não definido/0' : (string)$i;
        $suggestedIText = $suggestedI !== null ? round($suggestedI, 2) : 'N/A';
        $suggestions[] = 'Ti atual: ' . $currentIText . ' → Sugerido: ' . $suggestedIText . ' (∼ Ki ajustado).';
    }
    if ($d !== null) {
        $suggestions[] = 'Td atual: ' . $d . ' → Sugerido: ' . round($suggestedD, 2) . ' (∼ Kd ajustado).';
    }

    return [
        'suggestions' => $suggestions,
        'suggestedValues' => [
            'p' => $suggestedP,
            'i' => $suggestedI !== null ? (float)$suggestedI : null,
            'd' => (int)$suggestedD
        ]
    ];
}

$phErrors = [];
$clErrors = [];
$times = [];

foreach ($history as $row) {
    $ph = floatOrNull($row['ph_value']);
    $ph_sp = floatOrNull($row['ph_setpoint']);
    $cl = floatOrNull($row['chlorine_value']);
    $cl_sp = floatOrNull($row['chlorine_setpoint']);

    if ($ph !== null && $ph_sp !== null) {
        $phErrors[] = $ph - $ph_sp;
    }
    if ($cl !== null && $cl_sp !== null) {
        $clErrors[] = $cl - $cl_sp;
    }
    $times[] = $row['log_datetime'];
}


$clStats = calcStats($clErrors, $times);

// --- Cálculo do tempo médio de resposta do cloro (delay entre dosagem e efeito) ---
$cl_delay_samples = [];
$last_dose_time = null;
$last_dose_value = null;
$dose_threshold = 0.05; // Mudança mínima para considerar nova dosagem
$effect_threshold = 0.05; // Mudança mínima para considerar efeito
for ($i = 1; $i < count($history); $i++) {
    $prev = $history[$i-1];
    $curr = $history[$i];
    // Detecta alteração relevante na dosagem de cloro
    if (isset($prev['cl_controller_state'], $curr['cl_controller_state'])) {
        $prev_dose = floatOrNull($prev['cl_controller_state']);
        $curr_dose = floatOrNull($curr['cl_controller_state']);
        if ($prev_dose !== null && $curr_dose !== null && abs($curr_dose - $prev_dose) > $dose_threshold) {
            $last_dose_time = strtotime($curr['log_datetime']);
            $last_dose_value = floatOrNull($curr['chlorine_value']);
            // Agora procura pelo efeito nos próximos pontos
            for ($j = $i+1; $j < count($history); $j++) {
                $effect_val = floatOrNull($history[$j]['chlorine_value']);
                if ($effect_val !== null && $last_dose_value !== null && abs($effect_val - $last_dose_value) > $effect_threshold) {
                    $effect_time = strtotime($history[$j]['log_datetime']);
                    $delay = $effect_time - $last_dose_time;
                    if ($delay > 0 && $delay < 3600*6) { // ignora delays absurdos (>6h)
                        $cl_delay_samples[] = $delay;
                    }
                    break;
                }
            }
        }
    }
}
$cl_mean_delay = null;
if (count($cl_delay_samples) > 0) {
    $cl_mean_delay = array_sum($cl_delay_samples) / count($cl_delay_samples);
}

// Usa os valores atuais de PID do banco de dados (padrão) e tenta fallback em histórico se Ti inválido
$tankPid = [
    'p' => isset($tank['pid_p']) ? floatOrNull($tank['pid_p']) : null,
    'i' => isset($tank['pid_i']) ? floatOrNull($tank['pid_i']) : null,
    'd' => isset($tank['pid_d']) ? floatOrNull($tank['pid_d']) : null
];

// Se Ti (ou Td) estiver ausente ou zero no registro direto, tentar obter último valor do histórico de alterações.
if (($tankPid['i'] === null || $tankPid['i'] <= 0) || ($tankPid['d'] === null || $tankPid['d'] < 0)) {
    $stmt_history_pid = $conn->prepare("SELECT p, i, d FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 1");
    if ($stmt_history_pid) {
        $stmt_history_pid->bind_param('i', $tank_id);
        if ($stmt_history_pid->execute()) {
            $pid_history = $stmt_history_pid->get_result()->fetch_assoc();
            if ($pid_history) {
                if (($tankPid['i'] === null || $tankPid['i'] <= 0) && isset($pid_history['i']) && floatOrNull($pid_history['i']) > 0) {
                    $tankPid['i'] = floatOrNull($pid_history['i']);
                }
                if (($tankPid['d'] === null || $tankPid['d'] < 0) && isset($pid_history['d']) && floatOrNull($pid_history['d']) >= 0) {
                    $tankPid['d'] = floatOrNull($pid_history['d']);
                }
                if (($tankPid['p'] === null || $tankPid['p'] <= 0) && isset($pid_history['p']) && floatOrNull($pid_history['p']) > 0) {
                    $tankPid['p'] = floatOrNull($pid_history['p']);
                }
            }
        }
        $stmt_history_pid->close();
    }
}

$recommendationsResult = pidRecommendations('chlorine', $clStats, $tankPid);
$recommendations = [
    'chlorine' => $recommendationsResult['suggestions'],
];
$suggestedValues = $recommendationsResult['suggestedValues'];

// Últimas alterações de PID registradas
$stmt_history = $conn->prepare("SELECT changed_at, p, i, d, reason, changed_by FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 5");
if ($stmt_history) {
    $stmt_history->bind_param('i', $tank_id);
    if ($stmt_history->execute()) {
        $pid_change_history = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $pid_change_history = [];
    }
    $stmt_history->close();
} else {
    // Tabela pode não existir ainda, é normal
    $pid_change_history = [];
}

// Verificar se há bloqueio de 72 horas após última aceitação de sugestão
$lastChangeTime = null;
$canAcceptSuggestion = true;
$blockReason = null;

if ($pid_change_history && count($pid_change_history) > 0) {
    $lastChange = $pid_change_history[0]; // Já ordenado por DESC, então primeiro é o mais recente
    $lastChangeTime = strtotime($lastChange['changed_at']);
    $hoursSinceLastChange = (time() - $lastChangeTime) / 3600;

    if ($hoursSinceLastChange < 72) {
        $canAcceptSuggestion = false;
        $remainingHours = ceil(72 - $hoursSinceLastChange);
        $blockReason = "Período de monitorização ativo. Última alteração foi há " . round($hoursSinceLastChange, 1) . " horas. Aguarde mais " . $remainingHours . " horas para aceitar nova sugestão.";
    }
}

$response = [
    'tank_id' => $tank_id,
    'tank_name' => $tank['name'],
    'days' => $days,
    'row_count' => count($history),
    'chlorine' => [
        'stats' => $clStats,
        'suggestions' => $recommendations['chlorine'],
        'suggested_values' => $suggestedValues,
        'can_accept_suggestion' => $canAcceptSuggestion,
        'block_reason' => $blockReason,
        'last_change_time' => $lastChangeTime ? date('Y-m-d H:i:s', $lastChangeTime) : null,
        'mean_response_delay_sec' => $cl_mean_delay,
        'mean_response_delay_min' => $cl_mean_delay !== null ? round($cl_mean_delay/60,1) : null
    ],
    'current_pid' => $tankPid,
    'pid_change_history' => $pid_change_history,
];

// Limpa qualquer output buffering antes de enviar JSON
if (ob_get_length()) {
    ob_end_clean();
}

echo json_encode($response);
