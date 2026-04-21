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
$rangeStart = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
$rangeEnd = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
$hasRange = false;

if (
    $rangeStart && $rangeEnd
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd)
    && strtotime($rangeStart) !== false
    && strtotime($rangeEnd) !== false
    && strtotime($rangeStart) <= strtotime($rangeEnd)
) {
    $hasRange = true;
    $start_date = $rangeStart . ' 00:00:00';
    $end_date = $rangeEnd . ' 23:59:59';
    $days = max(1, (int)floor((strtotime($rangeEnd) - strtotime($rangeStart)) / 86400) + 1);
} else {
    $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $end_date = date('Y-m-d H:i:s');
}

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
$historySql = $hasRange
    ? "SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime BETWEEN ? AND ? ORDER BY log_datetime ASC"
    : "SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime >= ? ORDER BY log_datetime ASC";
$stmt = $conn->prepare($historySql);
if (!$stmt) {
    return_json_error('Erro ao preparar consulta de histórico: ' . $conn->error, 500);
}
if ($hasRange) {
    $stmt->bind_param('iss', $tank_id, $start_date, $end_date);
} else {
    $stmt->bind_param('is', $tank_id, $start_date);
}
if (!$stmt->execute()) {
    return_json_error('Erro ao executar consulta de histórico: ' . $stmt->error, 500);
}
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$history) {
    $lastAvailable = null;
    $stmt_last = $conn->prepare("SELECT MAX(log_datetime) AS last_log_datetime FROM controller_history WHERE tank_id = ?");
    if ($stmt_last) {
        $stmt_last->bind_param('i', $tank_id);
        if ($stmt_last->execute()) {
            $row_last = $stmt_last->get_result()->fetch_assoc();
            $lastAvailable = isset($row_last['last_log_datetime']) ? $row_last['last_log_datetime'] : null;
        }
        $stmt_last->close();
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    $message = $hasRange
        ? ('Sem dados recentes de controlador no período solicitado (' . $rangeStart . ' até ' . $rangeEnd . ').')
        : ('Sem dados recentes de controlador no período solicitado (' . (int)$days . ' dias).');
    if ($lastAvailable) {
        $message .= ' Último registo disponível: ' . $lastAvailable . '.';
    }

    echo json_encode([
        'tank_id' => $tank_id,
        'tank_name' => $tank['name'],
        'days' => $days,
        'start_date' => $hasRange ? $rangeStart : date('Y-m-d', strtotime($start_date)),
        'end_date' => $hasRange ? $rangeEnd : date('Y-m-d'),
        'row_count' => 0,
        'no_recent_data' => true,
        'last_available_log_datetime' => $lastAvailable,
        'message' => $message,
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

function clampPidSuggestion($current, $suggested, $min, $max, $maxStepFraction) {
    if ($suggested === null || !is_numeric($suggested)) {
        return null;
    }

    $candidate = max($min, min($max, (float)$suggested));

    if ($current === null || !is_numeric($current) || (float)$current <= 0) {
        return $candidate;
    }

    $base = (float)$current;
    $deltaLimit = abs($base) * $maxStepFraction;
    $lower = max($min, $base - $deltaLimit);
    $upper = min($max, $base + $deltaLimit);

    return max($lower, min($upper, $candidate));
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

function calcStatsFromSeries($series) {
    $errors = [];
    $times = [];
    foreach ($series as $point) {
        if (!isset($point['value']) || !isset($point['setpoint'])) {
            continue;
        }
        if ($point['value'] === null || $point['setpoint'] === null) {
            continue;
        }
        $errors[] = (float)$point['value'] - (float)$point['setpoint'];
        $times[] = $point['time'];
    }
    return calcStats($errors, $times);
}

function clamp01($v) {
    return max(0.0, min(1.0, (float)$v));
}

function classifyWindow($timeString) {
    $hour = (int)date('G', strtotime($timeString));
    if ($hour >= 0 && $hour < 6) return 'madrugada';
    if ($hour >= 6 && $hour < 12) return 'manha';
    if ($hour >= 12 && $hour < 18) return 'tarde';
    return 'noite';
}

function calcTimeWindowStats($series) {
    $buckets = [
        'madrugada' => [],
        'manha' => [],
        'tarde' => [],
        'noite' => [],
    ];

    foreach ($series as $point) {
        if (!isset($point['time'])) continue;
        $bucket = classifyWindow($point['time']);
        $buckets[$bucket][] = $point;
    }

    $result = [];
    foreach ($buckets as $name => $points) {
        $stats = calcStatsFromSeries($points);
        $result[$name] = [
            'samples' => count($points),
            'stats' => $stats,
        ];
    }
    return $result;
}

function calcConfidence($stats, $rowCount, $zeroGlitchCount, $zeroObservedCount) {
    $samples = isset($stats['samples']) ? (int)$stats['samples'] : 0;
    $sampleScore = $samples >= 120 ? 1.0 : ($samples >= 60 ? 0.8 : ($samples >= 30 ? 0.55 : 0.30));
    $coverage = $rowCount > 0 ? ($samples / $rowCount) : 0.0;
    $coverageScore = clamp01($coverage);
    $glitchRatio = $zeroObservedCount > 0 ? ($zeroGlitchCount / $zeroObservedCount) : 0.0;
    $qualityScore = clamp01(1.0 - min(0.5, $glitchRatio));

    $score = (0.50 * $sampleScore) + (0.25 * $coverageScore) + (0.25 * $qualityScore);
    $level = $score >= 0.80 ? 'alta' : ($score >= 0.55 ? 'media' : 'baixa');

    $reasons = [];
    if ($samples < 30) $reasons[] = 'Amostragem reduzida';
    if ($coverage < 0.6) $reasons[] = 'Cobertura parcial dos dados do período';
    if ($glitchRatio > 0.25) $reasons[] = 'Muitas leituras espúrias de zero';

    return [
        'level' => $level,
        'score' => round($score * 100, 1),
        'reasons' => $reasons,
    ];
}

function calcCompositeScore($stats, $recovery, $zeroGlitchCount, $samples) {
    $precision = 100 - min(100, (($stats['mean_abs'] ?? 0) / 0.30) * 100);
    $stability = 100 - min(100, (($stats['stdev'] ?? 0) / 0.40) * 100);
    $recoveryMean = isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null ? (float)$recovery['mean_recovery_sec'] : 0;
    $recoveryScore = 100 - min(100, ($recoveryMean / 3600) * 100);
    $robustnessPenalty = min(40, $zeroGlitchCount * 3);
    $robustness = max(0, 100 - $robustnessPenalty);

    if ($samples < 15) {
        $precision *= 0.8;
        $stability *= 0.8;
        $recoveryScore *= 0.8;
    }

    $total = (0.40 * $precision) + (0.25 * $stability) + (0.20 * $recoveryScore) + (0.15 * $robustness);

    return [
        'total' => round($total, 1),
        'weights' => ['precision' => 0.40, 'stability' => 0.25, 'recovery' => 0.20, 'robustness' => 0.15],
        'components' => [
            'precision' => round($precision, 1),
            'stability' => round($stability, 1),
            'recovery' => round($recoveryScore, 1),
            'robustness' => round($robustness, 1),
        ],
    ];
}

function calcContributionSplit($stats, $recovery) {
    $variability = isset($stats['stdev']) ? (float)$stats['stdev'] : 0.0;
    $disturbanceCount = isset($recovery['disturbance_count']) ? (int)$recovery['disturbance_count'] : 0;
    $unrecovered = isset($recovery['unrecovered_count']) ? (int)$recovery['unrecovered_count'] : 0;

    $externalRaw = min(1.0, ($disturbanceCount * 0.12) + ($unrecovered * 0.18));
    $controllerRaw = min(1.0, ($variability / 0.45) * 0.8 + 0.2);
    $sum = max(0.01, $externalRaw + $controllerRaw);

    $externalPct = round(($externalRaw / $sum) * 100, 1);
    $controllerPct = round(100 - $externalPct, 1);

    return [
        'controller_pct' => $controllerPct,
        'external_pct' => $externalPct,
    ];
}

function buildSparkline($values) {
    if (!$values || count($values) === 0) return '';
    $ticks = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    if ($range <= 0) {
        return str_repeat('▅', min(32, count($values)));
    }

    $spark = '';
    foreach ($values as $v) {
        $norm = ($v - $min) / $range;
        $idx = (int)round($norm * 7);
        $spark .= $ticks[max(0, min(7, $idx))];
    }
    return $spark;
}

function calcBeforeAfterImpact($series, $changeTime) {
    if (!$changeTime) return null;
    $changeTs = strtotime($changeTime);
    if (!$changeTs) return null;

    $beforeStart = $changeTs - 86400;
    $beforeEnd = $changeTs;
    $afterStart = $changeTs;
    $afterEnd = $changeTs + 86400;

    $before = [];
    $after = [];

    foreach ($series as $point) {
        $ts = isset($point['ts']) ? (int)$point['ts'] : 0;
        if ($ts >= $beforeStart && $ts < $beforeEnd) $before[] = $point;
        if ($ts >= $afterStart && $ts <= $afterEnd) $after[] = $point;
    }

    $beforeStats = calcStatsFromSeries($before);
    $afterStats = calcStatsFromSeries($after);
    if (!$beforeStats || !$afterStats) {
        return null;
    }

    $deltaMae = $afterStats['mean_abs'] - $beforeStats['mean_abs'];
    $deltaStdev = $afterStats['stdev'] - $beforeStats['stdev'];

    return [
        'window_hours' => 24,
        'before' => $beforeStats,
        'after' => $afterStats,
        'delta' => [
            'mean_abs' => $deltaMae,
            'stdev' => $deltaStdev,
        ],
    ];
}

function buildActionPlan($stats, $recovery, $confidence, $composite) {
    $actions = [];

    if (($stats['mean_abs'] ?? 0) >= 0.25) {
        $actions[] = [
            'priority' => 1,
            'severity' => 'alta',
            'title' => 'Reduzir erro médio absoluto',
            'action' => 'Ajustar Kp de forma gradual (+10% se estável, -10% se oscilante).',
            'expected_impact' => 'Redução de 10-20% no MAE.',
            'risk' => 'Risco de sobrecorreção se houver perturbações externas frequentes.',
        ];
    }

    if (($stats['stdev'] ?? 0) > 0.30) {
        $actions[] = [
            'priority' => 2,
            'severity' => 'media',
            'title' => 'Conter oscilações',
            'action' => 'Reduzir Kp ligeiramente e aumentar Td em pequeno passo.',
            'expected_impact' => 'Menor variabilidade e menos reversões rápidas.',
            'risk' => 'Resposta pode ficar mais lenta.',
        ];
    }

    if (isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null && $recovery['mean_recovery_sec'] > 1800) {
        $actions[] = [
            'priority' => 3,
            'severity' => 'media',
            'title' => 'Melhorar recuperação pós-perturbação',
            'action' => 'Ajustar Ti para acelerar retorno ao setpoint com segurança.',
            'expected_impact' => 'Recuperação mais rápida após quedas externas.',
            'risk' => 'Integral excessiva pode aumentar overshoot.',
        ];
    }

    if ($confidence['level'] === 'baixa') {
        $actions[] = [
            'priority' => 0,
            'severity' => 'alta',
            'title' => 'Elevar confiança da análise antes de alterar PID',
            'action' => 'Aguardar mais dados e validar qualidade do sensor.',
            'expected_impact' => 'Decisão de tuning mais robusta.',
            'risk' => 'Ajuste prematuro com base em amostra curta.',
        ];
    }

    if (($composite['total'] ?? 100) >= 85 && count($actions) === 0) {
        $actions[] = [
            'priority' => 4,
            'severity' => 'baixa',
            'title' => 'Manter estratégia atual',
            'action' => 'Sem necessidade de ajuste imediato; monitorizar tendência.',
            'expected_impact' => 'Estabilidade operacional contínua.',
            'risk' => 'Baixo.',
        ];
    }

    usort($actions, function($a, $b) {
        return ($a['priority'] <=> $b['priority']);
    });

    return $actions;
}

function markSpontaneousZeroRuns($series) {
    $n = count($series);
    if ($n < 3) {
        return [
            'series' => $series,
            'glitch_count' => 0,
        ];
    }

    $zeroThreshold = 0.02;
    $minBoundaryValue = 0.15;
    $maxBoundaryDrift = 0.25;
    $maxControllerSpread = 0.06;
    $maxRunLen = 2;

    $glitchCount = 0;

    for ($i = 1; $i < $n - 1; $i++) {
        if ($series[$i]['value'] > $zeroThreshold || $series[$i]['zero_glitch']) {
            continue;
        }

        $start = $i;
        $end = $i;
        while (($end + 1) < $n && $series[$end + 1]['value'] <= $zeroThreshold) {
            $end++;
        }

        $runLen = $end - $start + 1;
        if ($runLen > $maxRunLen) {
            $i = $end;
            continue;
        }
        if ($start <= 0 || $end >= ($n - 1)) {
            $i = $end;
            continue;
        }

        $prev = $series[$start - 1];
        $next = $series[$end + 1];
        if ($prev['value'] < $minBoundaryValue || $next['value'] < $minBoundaryValue) {
            $i = $end;
            continue;
        }
        if (abs($prev['value'] - $next['value']) > $maxBoundaryDrift) {
            $i = $end;
            continue;
        }

        $controllers = [];
        for ($k = $start - 1; $k <= $end + 1; $k++) {
            if ($series[$k]['controller'] !== null) {
                $controllers[] = $series[$k]['controller'];
            }
        }
        if (count($controllers) >= 2) {
            $spread = max($controllers) - min($controllers);
            if ($spread > $maxControllerSpread) {
                $i = $end;
                continue;
            }
        }

        for ($k = $start; $k <= $end; $k++) {
            $series[$k]['zero_glitch'] = true;
            $glitchCount++;
        }

        $i = $end;
    }

    return [
        'series' => $series,
        'glitch_count' => $glitchCount,
    ];
}

function calcDisturbanceRecovery($series) {
    $n = count($series);
    if ($n < 4) {
        return [
            'disturbance_count' => 0,
            'unrecovered_count' => 0,
            'mean_recovery_sec' => null,
            'mean_stabilization_sec' => null,
        ];
    }

    $dropThreshold = 0.18;
    $controllerStableThreshold = 0.04;
    $recoveryBand = 0.06;
    $stabilityBand = 0.05;
    $recoverySamples = [];
    $stabilizationSamples = [];
    $unrecovered = 0;
    $events = 0;

    for ($i = 1; $i < $n - 1; $i++) {
        $prev = $series[$i - 1];
        $curr = $series[$i];

        if ($prev['controller'] === null || $curr['controller'] === null) {
            continue;
        }

        $drop = $curr['value'] - $prev['value'];
        $ctrlDelta = abs($curr['controller'] - $prev['controller']);
        if ($drop > -$dropThreshold || $ctrlDelta > $controllerStableThreshold) {
            continue;
        }

        $events++;
        $eventTs = $curr['ts'];
        $target = max($curr['setpoint'] - $recoveryBand, $prev['value'] - ($dropThreshold * 0.35));
        $recoveryIdx = null;

        for ($j = $i + 1; $j < $n; $j++) {
            if ($series[$j]['value'] >= $target) {
                $recoveryIdx = $j;
                break;
            }
        }

        if ($recoveryIdx === null) {
            $unrecovered++;
            continue;
        }

        $recoverySamples[] = max(0, $series[$recoveryIdx]['ts'] - $eventTs);

        $stableIdx = null;
        for ($k = $recoveryIdx; $k < $n - 2; $k++) {
            $ok = true;
            for ($w = 0; $w < 3; $w++) {
                $e = abs($series[$k + $w]['value'] - $series[$k + $w]['setpoint']);
                if ($e > $stabilityBand) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $stableIdx = $k + 2;
                break;
            }
        }

        if ($stableIdx !== null) {
            $stabilizationSamples[] = max(0, $series[$stableIdx]['ts'] - $eventTs);
        } else {
            $unrecovered++;
        }
    }

    return [
        'disturbance_count' => $events,
        'unrecovered_count' => $unrecovered,
        'mean_recovery_sec' => $recoverySamples ? (array_sum($recoverySamples) / count($recoverySamples)) : null,
        'mean_stabilization_sec' => $stabilizationSamples ? (array_sum($stabilizationSamples) / count($stabilizationSamples)) : null,
    ];
}

function pidRecommendations($mode, $stats, $currentPid, $context = []) {
    $suggestions = [];
    $p = isset($currentPid['p']) ? floatOrNull($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? floatOrNull($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? floatOrNull($currentPid['d']) : null;
    $responseDelaySec = isset($context['mean_response_delay_sec']) && is_numeric($context['mean_response_delay_sec'])
        ? (float)$context['mean_response_delay_sec']
        : null;

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
    $recovery = isset($context['recovery']) && is_array($context['recovery']) ? $context['recovery'] : [];
    $slowRecovery = isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null && $recovery['mean_recovery_sec'] > 1800;
    $slowStabilization = isset($recovery['mean_stabilization_sec']) && $recovery['mean_stabilization_sec'] !== null && $recovery['mean_stabilization_sec'] > 3000;
    $hasUnrecovered = isset($recovery['unrecovered_count']) && (int)$recovery['unrecovered_count'] > 0;
    $disturbanceHeavy = isset($recovery['disturbance_count']) && (int)$recovery['disturbance_count'] >= 2;
    $zeroGlitches = isset($context['zero_glitch_count']) ? (int)$context['zero_glitch_count'] : 0;
    $zeroObserved = isset($context['zero_observed_count']) ? (int)$context['zero_observed_count'] : 0;

    // Semente de Ti com base no atraso médio observado do processo.
    $defaultTiSec = ($mode === 'chlorine') ? 1200.0 : 300.0;
    $tiSeedSec = ($responseDelaySec !== null && $responseDelaySec > 0)
        ? max(300.0, min(7200.0, $responseDelaySec))
        : $defaultTiSec;

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
            $suggestions[] = 'Viés persistente (média ' . round($stats['mean'], 3) . ') sugere integral ineficaz (Ti=0 ou não configurado); considere definir Ti > 0 para ativar ação integral.';
            $suggestedI = round($hasOscillations ? ($tiSeedSec * 1.5) : $tiSeedSec, 2);
            $suggestions[] = 'Ti inicial sugerido com base na dinâmica do processo: ' . round($suggestedI, 2) . ' (na unidade do controlador).';
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
            $suggestedI = round($hasOscillations ? ($tiSeedSec * 1.6) : ($tiSeedSec * 1.2), 2);
            $suggestions[] = 'Ti não definido (ou igual a 0) com erro elevado; sugerido Ti inicial de ' . round($suggestedI, 2) . ' (na unidade do controlador) para introduzir ação integral de forma gradual.';
        }
    }

    if ($hasRapidReversals) {
        $suggestions[] = 'Erro com muitas reversões rápidas; aumentar Td para amortecer.';
        if ($d !== null) $suggestedD = $d * 1.25; // Aumenta Td 25%
        // Não reduz Kp aqui se já foi reduzido por oscilações
        if (!$hasOscillations && $p !== null) $suggestedP = $p * 0.95; // Reduz ligeiramente Kp
    }

    if (($slowRecovery || $disturbanceHeavy) && !$hasOscillations) {
        $suggestions[] = 'Quedas externas com recuperação lenta; ajustar Kp/Ti para reduzir tempo de retorno ao setpoint.';
        if ($p !== null) $suggestedP = $p * 1.08;
        if ($suggestedI !== null && $suggestedI > 0) $suggestedI = $suggestedI * 0.90;
    }

    if ($slowStabilization && $d !== null) {
        $suggestions[] = 'Após recuperar, a estabilização ainda está lenta; aumentar Td de forma moderada.';
        $suggestedD = $d * 1.10;
    }

    if ($hasUnrecovered) {
        $suggestions[] = 'Foram detetadas perturbações sem recuperação completa no período; manter ajustes conservadores e monitorizar.';
    }

    if ($zeroObserved > 0) {
        $suggestions[] = 'Foram observadas ' . $zeroObserved . ' leituras em zero/quase zero no período.';
    }
    if ($zeroGlitches > 0) {
        $suggestions[] = 'Dessas, ' . $zeroGlitches . ' foram classificadas como sequências curtas (1-2 leituras) de zero espúrio e ignoradas na estatística principal.';
    }

    // Guard rails: limita variação por ciclo e impõe envelopes seguros.
    $suggestedP = clampPidSuggestion($p, $suggestedP, 0.01, 100.0, 0.10);
    $suggestedI = clampPidSuggestion($i, $suggestedI, 0.0, 7200.0, 0.15);
    $suggestedD = clampPidSuggestion($d, $suggestedD, 0.0, 3600.0, 0.20);

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
            'd' => $suggestedD !== null ? (float)$suggestedD : null
        ]
    ];
}

$phErrors = [];
$clSeries = [];
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
        $clSeries[] = [
            'time' => $row['log_datetime'],
            'ts' => strtotime($row['log_datetime']),
            'value' => $cl,
            'setpoint' => $cl_sp,
            'controller' => isset($row['cl_controller_state']) ? floatOrNull($row['cl_controller_state']) : null,
            'zero_glitch' => false,
        ];
    }
}


$zeroObservedCount = 0;
foreach ($clSeries as $point) {
    if ($point['value'] <= 0.02) {
        $zeroObservedCount++;
    }
}

$markResult = markSpontaneousZeroRuns($clSeries);
$clSeries = $markResult['series'];
$zeroGlitchCount = $markResult['glitch_count'];

$clErrors = [];
$cleanSeries = [];
foreach ($clSeries as $point) {
    if ($point['zero_glitch']) {
        continue;
    }
    $clErrors[] = $point['value'] - $point['setpoint'];
    $times[] = $point['time'];
    $cleanSeries[] = $point;
}


$clStats = calcStats($clErrors, $times);
$clSetpointMean = null;
if (count($cleanSeries) > 0) {
    $setpoints = array_column($cleanSeries, 'setpoint');
    $setpoints = array_filter($setpoints, function($v) { return $v !== null && is_numeric($v); });
    if (count($setpoints) > 0) {
        $clSetpointMean = array_sum($setpoints) / count($setpoints);
    }
}

if ($clStats && $clSetpointMean !== null && $clSetpointMean != 0) {
    $clStats['mean_pct'] = ($clStats['mean'] / $clSetpointMean) * 100;
    $clStats['mean_abs_pct'] = ($clStats['mean_abs'] / $clSetpointMean) * 100;
} else if ($clStats) {
    $clStats['mean_pct'] = null;
    $clStats['mean_abs_pct'] = null;
}

// --- Cálculo do tempo médio de resposta do cloro (delay entre dosagem e efeito) ---
$cl_delay_samples = [];
$last_dose_time = null;
$last_dose_value = null;
$dose_threshold = 0.05; // Mudança mínima para considerar nova dosagem
$effect_threshold = 0.05; // Mudança mínima para considerar efeito
for ($i = 1; $i < count($cleanSeries); $i++) {
    $prev = $cleanSeries[$i - 1];
    $curr = $cleanSeries[$i];
    if ($prev['controller'] !== null && $curr['controller'] !== null && abs($curr['controller'] - $prev['controller']) > $dose_threshold) {
        $last_dose_time = $curr['ts'];
        $last_dose_value = $curr['value'];
        for ($j = $i + 1; $j < count($cleanSeries); $j++) {
            $effect_val = $cleanSeries[$j]['value'];
            if ($effect_val !== null && $last_dose_value !== null && abs($effect_val - $last_dose_value) > $effect_threshold) {
                $effect_time = $cleanSeries[$j]['ts'];
                $delay = $effect_time - $last_dose_time;
                if ($delay > 0 && $delay < 3600 * 6) {
                    $cl_delay_samples[] = $delay;
                }
                break;
            }
        }
    }
}
$cl_mean_delay = null;
if (count($cl_delay_samples) > 0) {
    $cl_mean_delay = array_sum($cl_delay_samples) / count($cl_delay_samples);
}

$clRecovery = calcDisturbanceRecovery($cleanSeries);
$windowStats = calcTimeWindowStats($cleanSeries);
$confidence = calcConfidence($clStats ?: ['samples' => 0], count($history), $zeroGlitchCount, $zeroObservedCount);
$compositeScore = calcCompositeScore($clStats ?: ['mean_abs' => 1, 'stdev' => 1], $clRecovery, $zeroGlitchCount, $clStats ? $clStats['samples'] : 0);
$contribution = calcContributionSplit($clStats ?: ['stdev' => 0], $clRecovery);

$trendValues = [];
if ($clStats && count($clErrors) > 0) {
    $trendValues = array_slice($clErrors, -32);
}
$trendSparkline = buildSparkline($trendValues);

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

$statsForRecommendations = $clStats ?: [
    'samples' => 0,
    'mean' => 0.0,
    'mean_abs' => 0.0,
    'min' => 0.0,
    'max' => 0.0,
    'stdev' => 0.0,
    'sign_changes' => 0,
    'sign_change_rate' => 0.0,
    'derivative_mean' => 0.0,
];

$recommendationsResult = pidRecommendations('chlorine', $statsForRecommendations, $tankPid, [
    'mean_response_delay_sec' => $cl_mean_delay,
    'recovery' => $clRecovery,
    'zero_glitch_count' => $zeroGlitchCount,
    'zero_observed_count' => $zeroObservedCount,
]);
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

$beforeAfterImpact = null;
if (!empty($pid_change_history) && isset($pid_change_history[0]['changed_at'])) {
    $beforeAfterImpact = calcBeforeAfterImpact($cleanSeries, $pid_change_history[0]['changed_at']);
}

$actionPlan = buildActionPlan(
    $clStats ?: ['mean_abs' => 1, 'stdev' => 1],
    $clRecovery,
    $confidence,
    $compositeScore
);

$response = [
    'tank_id' => $tank_id,
    'tank_name' => $tank['name'],
    'days' => $days,
    'start_date' => $hasRange ? $rangeStart : date('Y-m-d', strtotime($start_date)),
    'end_date' => $hasRange ? $rangeEnd : date('Y-m-d'),
    'row_count' => count($history),
    'chlorine' => [
        'stats' => $clStats ?: $statsForRecommendations,
        'suggestions' => $recommendations['chlorine'],
        'suggested_values' => $suggestedValues,
        'can_accept_suggestion' => $canAcceptSuggestion,
        'block_reason' => $blockReason,
        'last_change_time' => $lastChangeTime ? date('Y-m-d H:i:s', $lastChangeTime) : null,
        'mean_response_delay_sec' => $cl_mean_delay,
        'mean_response_delay_min' => $cl_mean_delay !== null ? round($cl_mean_delay/60,1) : null,
        'setpoint_mean' => $clSetpointMean,
        'mean_recovery_sec' => isset($clRecovery['mean_recovery_sec']) ? $clRecovery['mean_recovery_sec'] : null,
        'mean_recovery_min' => isset($clRecovery['mean_recovery_sec']) && $clRecovery['mean_recovery_sec'] !== null ? round($clRecovery['mean_recovery_sec'] / 60, 1) : null,
        'mean_stabilization_sec' => isset($clRecovery['mean_stabilization_sec']) ? $clRecovery['mean_stabilization_sec'] : null,
        'mean_stabilization_min' => isset($clRecovery['mean_stabilization_sec']) && $clRecovery['mean_stabilization_sec'] !== null ? round($clRecovery['mean_stabilization_sec'] / 60, 1) : null,
        'disturbance_count' => isset($clRecovery['disturbance_count']) ? (int)$clRecovery['disturbance_count'] : 0,
        'unrecovered_count' => isset($clRecovery['unrecovered_count']) ? (int)$clRecovery['unrecovered_count'] : 0,
        'zero_observed_count' => $zeroObservedCount,
        'zero_glitch_count' => $zeroGlitchCount,
        'confidence' => $confidence,
        'composite_score' => $compositeScore,
        'contribution' => $contribution,
        'time_windows' => $windowStats,
        'error_trend' => [
            'values' => $trendValues,
            'sparkline' => $trendSparkline
        ],
        'before_after_impact' => $beforeAfterImpact,
        'action_plan' => $actionPlan
    ],
    'current_pid' => $tankPid,
    'pid_change_history' => $pid_change_history,
];

// Limpa qualquer output buffering antes de enviar JSON
if (ob_get_length()) {
    ob_end_clean();
}

echo json_encode($response);
