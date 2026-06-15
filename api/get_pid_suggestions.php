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
    ? "SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, chlorine_base_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime BETWEEN ? AND ? ORDER BY log_datetime ASC"
    : "SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint, chlorine_base_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime >= ? ORDER BY log_datetime ASC";
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

/**
 * Median helper.
 */
function medianOf($values) {
    $values = array_values(array_filter($values, function($v) { return is_numeric($v); }));
    $n = count($values);
    if ($n === 0) return null;
    sort($values);
    if ($n % 2 === 1) return (float)$values[(int)floor($n / 2)];
    return ((float)$values[$n / 2 - 1] + (float)$values[$n / 2]) / 2.0;
}

/**
 * Detect actuator saturation from cl_controller_state (0..100 %).
 */
function detectActuatorSaturation($series) {
    $total = 0; $atMax = 0; $atMin = 0; $values = [];
    foreach ($series as $p) {
        if (!isset($p['controller']) || $p['controller'] === null) continue;
        $v = (float)$p['controller'];
        $total++;
        $values[] = $v;
        if ($v >= 95.0) $atMax++;
        if ($v <= 5.0) $atMin++;
    }
    if ($total === 0) {
        return [
            'samples' => 0,
            'pct_at_max' => 0.0,
            'pct_at_min' => 0.0,
            'mean' => null,
            'saturated_high' => false,
            'saturated_low' => false,
        ];
    }
    $pctMax = ($atMax / $total) * 100.0;
    $pctMin = ($atMin / $total) * 100.0;
    return [
        'samples' => $total,
        'pct_at_max' => round($pctMax, 1),
        'pct_at_min' => round($pctMin, 1),
        'mean' => round(array_sum($values) / $total, 2),
        // Considera saturado se passar >25% do tempo no limite
        'saturated_high' => $pctMax > 25.0,
        'saturated_low'  => $pctMin > 25.0,
    ];
}

/**
 * Confirma se mudanças do atuador (dosagem) produzem resposta coerente no cloro.
 * Esperado para cloro: ganho positivo (mais dosagem => mais cloro após algum atraso).
 */
function calcActuationDirectionEvidence($series, $meanResponseDelaySec = null) {
    $n = count($series);
    if ($n < 12) {
        return [
            'samples' => 0,
            'agreement' => null,
            'gain_sign' => 'unknown',
            'reliable' => false,
            'lag_steps' => null,
            'reason' => 'Amostragem insuficiente para inferir direção do processo.',
        ];
    }

    $dtSamples = [];
    for ($i = 1; $i < $n; $i++) {
        $dt = (int)$series[$i]['ts'] - (int)$series[$i - 1]['ts'];
        if ($dt > 0 && $dt <= 3600) $dtSamples[] = $dt;
    }
    $medianDt = medianOf($dtSamples);
    if ($medianDt === null || $medianDt <= 0) {
        return [
            'samples' => 0,
            'agreement' => null,
            'gain_sign' => 'unknown',
            'reliable' => false,
            'lag_steps' => null,
            'reason' => 'Sem passo temporal válido para análise direcional.',
        ];
    }

    $lagSteps = 2;
    if ($meanResponseDelaySec !== null && is_numeric($meanResponseDelaySec) && (float)$meanResponseDelaySec > 0) {
        $lagSteps = (int)round(((float)$meanResponseDelaySec) / $medianDt);
    }
    $lagSteps = max(1, min(12, $lagSteps));

    $aligned = 0;
    $opposed = 0;
    $usable = 0;
    $minActuatorStep = 1.0; // variação mínima de 1% na saída

    for ($i = 1; $i < ($n - $lagSteps); $i++) {
        if ($series[$i - 1]['controller'] === null || $series[$i]['controller'] === null) continue;
        $du = (float)$series[$i]['controller'] - (float)$series[$i - 1]['controller'];
        if (abs($du) < $minActuatorStep) continue;

        $yNow = isset($series[$i]['value']) ? (float)$series[$i]['value'] : null;
        $yLag = isset($series[$i + $lagSteps]['value']) ? (float)$series[$i + $lagSteps]['value'] : null;
        if ($yNow === null || $yLag === null) continue;

        $dy = $yLag - $yNow;
        $prod = $du * $dy;
        if (abs($prod) < 1e-6) continue;

        $usable++;
        if ($prod > 0) $aligned++;
        else $opposed++;
    }

    if ($usable < 8) {
        return [
            'samples' => $usable,
            'agreement' => null,
            'gain_sign' => 'unknown',
            'reliable' => false,
            'lag_steps' => $lagSteps,
            'reason' => 'Sem eventos de atuação suficientes para confirmar direção.',
        ];
    }

    $agreement = $aligned / max(1, ($aligned + $opposed));
    $gainSign = $agreement >= 0.55 ? 'positive' : ($agreement <= 0.45 ? 'negative' : 'unclear');
    $reliable = ($gainSign === 'positive') && ($agreement >= 0.62) && ($usable >= 12);

    return [
        'samples' => $usable,
        'agreement' => round($agreement, 3),
        'gain_sign' => $gainSign,
        'reliable' => $reliable,
        'lag_steps' => $lagSteps,
        'reason' => $reliable
            ? 'Direção física confirmada (atuador e resposta do cloro coerentes).'
            : 'Direção física inconclusiva/incoerente para ajuste confiável.',
    ];
}

/**
 * First-Order-Plus-Dead-Time process identification from observed step events
 * in the dosing actuator (cl_controller_state).
 *
 * Returns model parameters (median across events) and a confidence label.
 *
 * Model used by Lambda tuning:
 *   y(s)/u(s) = K * exp(-L s) / (tau s + 1)
 */
function identifyFopdtChlorine($series) {
    $n = count($series);
    $empty = [
        'available' => false,
        'events' => 0,
        'K' => null, 'tau_sec' => null, 'L_sec' => null,
        'confidence' => 'baixa',
        'reasons' => ['Sem eventos de degrau utilizáveis.'],
    ];
    if ($n < 20) return $empty;

    // Parâmetros de procura
    $stepThreshold      = 8.0;        // % de mudança mínima no atuador para considerar degrau
    $preWindowSec       = 600;        // 10 min antes do degrau para baseline
    $postWindowSec      = 5400;       // 90 min após degrau para observar resposta
    $minResponseDelta   = 0.05;       // mg/L mínimo para considerar resposta válida
    $uHoldTolPct        = 4.0;        // tolerância (%) para considerar u "mantido" após o degrau

    // Nota: com setpoint dinâmico ativo o SP varia continuamente. Como, em regime
    // permanente, y depende de u (não diretamente do SP), basta exigir que u
    // permaneça aproximadamente constante na janela de resposta para que ΔY/ΔU
    // seja uma estimativa válida do ganho do processo.
    $Ks = []; $taus = []; $Ls = [];
    $spDriftSeen = false;

    for ($i = 1; $i < $n - 1; $i++) {
        $prev = $series[$i - 1];
        $curr = $series[$i];
        if ($prev['controller'] === null || $curr['controller'] === null) continue;
        $du = (float)$curr['controller'] - (float)$prev['controller'];
        if (abs($du) < $stepThreshold) continue;

        // Baseline antes do degrau
        $t0  = (int)$curr['ts'];
        $baseVals = []; $baseSp = [];
        for ($j = $i - 1; $j >= 0; $j--) {
            if (($t0 - $series[$j]['ts']) > $preWindowSec) break;
            $baseVals[] = (float)$series[$j]['value'];
            $baseSp[]   = (float)$series[$j]['setpoint'];
        }
        if (count($baseVals) < 3) continue;
        $y0 = array_sum($baseVals) / count($baseVals);
        $spStart = array_sum($baseSp) / count($baseSp);

        // Resposta pós-degrau: aceita-se variação do setpoint (compatível com SP
        // dinâmico), mas exige-se que u permaneça aproximadamente constante para
        // que a resposta observada possa ser atribuída ao degrau de u.
        $resp = [];
        $aborted = false;
        $spMin = $spStart; $spMax = $spStart;
        for ($k = $i + 1; $k < $n; $k++) {
            $dt = $series[$k]['ts'] - $t0;
            if ($dt > $postWindowSec) break;
            // Aborta se u sair da banda em torno do novo patamar (degrau não foi mantido)
            if (abs((float)$series[$k]['controller'] - (float)$curr['controller']) > $uHoldTolPct) {
                $aborted = true; break;
            }
            $spK = (float)$series[$k]['setpoint'];
            if ($spK < $spMin) $spMin = $spK;
            if ($spK > $spMax) $spMax = $spK;
            $resp[] = ['t' => $dt, 'y' => (float)$series[$k]['value']];
        }
        if (count($resp) < 8) continue;
        if (($spMax - $spMin) > 0.05) $spDriftSeen = true;

        // Estado final estimado: média do último terço da janela
        $third = (int)max(3, floor(count($resp) / 3));
        $tail  = array_slice($resp, -$third);
        $ys    = array_sum(array_column($tail, 'y')) / count($tail);
        $deltaY = $ys - $y0;
        if (abs($deltaY) < $minResponseDelta) continue;

        // Ganho estático K = ΔY / ΔU
        $K = $deltaY / $du;
        // Para cloro esperamos K positivo (mais bomba → mais cloro). Rejeita sinais incoerentes.
        if ($K <= 0) continue;

        // Dead-time L: tempo até a resposta sair de uma banda de 5% de ΔY a partir do baseline
        $noiseBand = max(0.02, abs($deltaY) * 0.05);
        $L = null;
        foreach ($resp as $pt) {
            if (abs($pt['y'] - $y0) >= $noiseBand) { $L = $pt['t']; break; }
        }
        if ($L === null || $L <= 0) continue;

        // Constante de tempo τ: tempo (a partir de L) para atingir 63.2% de ΔY
        $target = $y0 + 0.632 * $deltaY;
        $tau = null;
        foreach ($resp as $pt) {
            if ($pt['t'] < $L) continue;
            $cond = $deltaY > 0 ? ($pt['y'] >= $target) : ($pt['y'] <= $target);
            if ($cond) { $tau = max(1.0, $pt['t'] - $L); break; }
        }
        if ($tau === null || $tau <= 0) continue;

        $Ks[]   = $K;
        $taus[] = $tau;
        $Ls[]   = $L;

        // Salta a janela já consumida para evitar contar o mesmo evento várias vezes
        $i = $k;
    }

    $events = count($Ks);
    if ($events === 0) {
        $empty['reasons'] = ['Não foram encontrados degraus do atuador mantidos no período (necessário ΔU>=8% e u estável na janela de resposta).'];
        return $empty;
    }

    $Kmed   = medianOf($Ks);
    $tauMed = medianOf($taus);
    $Lmed   = medianOf($Ls);

    // Dispersão relativa para confiança
    $spread = function($arr, $med) {
        if (!$arr || !$med) return 1.0;
        $diffs = array_map(function($v) use ($med) { return abs($v - $med); }, $arr);
        return (array_sum($diffs) / count($diffs)) / max(1e-6, abs($med));
    };
    $spK   = $spread($Ks,   $Kmed);
    $spTau = $spread($taus, $tauMed);
    $spL   = $spread($Ls,   $Lmed);
    $disp  = ($spK + $spTau + $spL) / 3.0;

    $confidence = 'baixa';
    $reasons = [];
    if ($events >= 4 && $disp < 0.35) $confidence = 'alta';
    elseif ($events >= 2 && $disp < 0.60) $confidence = 'media';
    if ($events < 2) $reasons[] = 'Apenas um evento de degrau identificado.';
    if ($disp >= 0.60) $reasons[] = 'Eventos com forte dispersão entre si.';
    if ($spDriftSeen) $reasons[] = 'Setpoint variável (SP dinâmico) durante as janelas analisadas: K/τ/L estimados assumindo u constante.';

    return [
        'available' => true,
        'source'   => 'step_events',
        'events' => $events,
        'K'        => round((float)$Kmed,   4),   // mg/L por % de atuador
        'tau_sec'  => (int)round((float)$tauMed),
        'L_sec'    => (int)round((float)$Lmed),
        'dispersion' => round($disp, 3),
        'confidence' => $confidence,
        'reasons' => $reasons,
    ];
}

/**
 * Fallback FOPDT estimator from closed-loop chart data (no step events required).
 *
 * Estimates K/τ/L by:
 *   - Sweeping candidate dead-times L and picking the lag that maximizes
 *     Pearson correlation between u(t) and y(t+L) (with positive sign).
 *   - K = covariance(u, y_lag) / variance(u)  (linear regression slope).
 *   - τ derived from observed recovery / stabilization metrics, falling back
 *     to a conservative multiple of L when those are unavailable.
 *
 * Returns the same shape as identifyFopdtChlorine() but with source='heuristic'.
 * The output is intended to be used with tighter clamps in pidRecommendations.
 */
function identifyFopdtChlorineHeuristic($series, $meanResponseDelaySec = null, $meanRecoverySec = null, $meanStabilizationSec = null) {
    $empty = [
        'available' => false,
        'source'    => 'heuristic',
        'events'    => 0,
        'K'         => null,
        'tau_sec'   => null,
        'L_sec'     => null,
        'dispersion'=> 1.0,
        'confidence'=> 'baixa',
        'reasons'   => ['Dados insuficientes para estimativa heurística baseada no gráfico.'],
    ];

    if (!is_array($series) || count($series) < 30) return $empty;

    // ---- Sub-conjunto "controlador ativo": apenas pontos próximos (±15 min) de
    // momentos em que a bomba esteve a operar ou variou. Evita que longas pausas
    // (com perturbações externas a dominarem) anulem a correlação.
    $activeSubset = extractControllerActiveSeries($series, 900);
    $usedActiveSubset = false;
    $reasonsPrefix = [];
    if (count($activeSubset) >= 30 && count($activeSubset) < count($series)) {
        $reasonsPrefix[] = 'Aplicado filtro de janela ativa do atuador (' . count($activeSubset) . '/' . count($series) . ' amostras).';
        $usedActiveSubset = true;
    } else {
        $activeSubset = $series;
    }

    // Tenta no subconjunto ativo; se falhar, repete no conjunto completo.
    $result = _heuristicFopdtRegression($activeSubset, $meanResponseDelaySec, $meanRecoverySec, $meanStabilizationSec, $reasonsPrefix);
    if (!$result['available'] && $usedActiveSubset) {
        $result = _heuristicFopdtRegression($series, $meanResponseDelaySec, $meanRecoverySec, $meanStabilizationSec, ['Filtro ativo insuficiente — repetida análise no conjunto completo.']);
    }
    return $result;
}

/**
 * Núcleo da regressão lag-otimizada. Separado para permitir reuso entre o
 * sub-conjunto "controlador ativo" e o conjunto completo.
 */
function _heuristicFopdtRegression($series, $meanResponseDelaySec, $meanRecoverySec, $meanStabilizationSec, $extraReasons = []) {
    $empty = [
        'available' => false,
        'source'    => 'heuristic',
        'events'    => 0,
        'K'         => null,
        'tau_sec'   => null,
        'L_sec'     => null,
        'dispersion'=> 1.0,
        'confidence'=> 'baixa',
        'reasons'   => array_merge($extraReasons, ['Dados insuficientes para estimativa heurística baseada no gráfico.']),
    ];

    $n = count($series);
    if ($n < 30) return $empty;

    // Median sampling interval
    $dts = [];
    for ($i = 1; $i < $n; $i++) {
        $dt = (int)$series[$i]['ts'] - (int)$series[$i - 1]['ts'];
        if ($dt > 0 && $dt <= 1800) $dts[] = $dt;
    }
    if (count($dts) < 10) return $empty;
    $medianDt = medianOf($dts);
    if ($medianDt === null || $medianDt <= 0) return $empty;
    $medianDt = (float)$medianDt;

    // Build aligned u/y/ts arrays
    $u = []; $y = []; $ts = [];
    foreach ($series as $p) {
        if (!isset($p['controller']) || $p['controller'] === null) continue;
        if (!isset($p['value']) || $p['value'] === null) continue;
        $u[] = (float)$p['controller'];
        $y[] = (float)$p['value'];
        $ts[] = (int)$p['ts'];
    }
    $m = count($u);
    if ($m < 30) return $empty;

    // Need actual variability to extract a relationship
    $uMean = array_sum($u) / $m;
    $yMean = array_sum($y) / $m;
    $uVar = 0.0; $yVar = 0.0;
    foreach ($u as $v) $uVar += pow($v - $uMean, 2);
    foreach ($y as $v) $yVar += pow($v - $yMean, 2);
    $uStd = sqrt($uVar / max(1, $m - 1));
    $yStd = sqrt($yVar / max(1, $m - 1));
    // Tolera bombas curtas (σu>=0.5%) e cloro com pouca variação (σy>=0.01 mg/L).
    if ($uStd < 0.5 || $yStd < 0.01) {
        $empty['reasons'] = array_merge($extraReasons, ['Sem variabilidade suficiente do atuador (σu=' . round($uStd, 2) . '%) ou do cloro (σy=' . round($yStd, 3) . ' mg/L) no período.']);
        return $empty;
    }

    // Candidate lag range (in seconds). If we have a measured response delay, focus around it.
    $minLagSec = 60;
    $maxLagSec = 1800;
    if ($meanResponseDelaySec !== null && is_numeric($meanResponseDelaySec) && $meanResponseDelaySec > 0) {
        $minLagSec = max(30,   (int)round($meanResponseDelaySec * 0.4));
        $maxLagSec = min(3600, (int)round($meanResponseDelaySec * 2.5));
    }
    if ($maxLagSec <= $minLagSec) $maxLagSec = $minLagSec + 60;
    $lagStep = max(30, (int)$medianDt);

    $bestLag = null; $bestCorr = -1.0; $bestK = null; $bestPts = 0;

    for ($lagSec = $minLagSec; $lagSec <= $maxLagSec; $lagSec += $lagStep) {
        $lagSteps = max(1, (int)round($lagSec / $medianDt));
        if ($lagSteps >= $m - 10) break;

        $sumU = 0.0; $sumY = 0.0; $sumUY = 0.0; $sumU2 = 0.0; $sumY2 = 0.0; $pts = 0;
        for ($i = 0; $i < $m - $lagSteps; $i++) {
            // Ensure actual time delta matches requested lag (skip large gaps)
            $dtActual = $ts[$i + $lagSteps] - $ts[$i];
            if ($dtActual < $lagSec * 0.6 || $dtActual > $lagSec * 1.6) continue;
            $uu = $u[$i];
            $yy = $y[$i + $lagSteps];
            $sumU  += $uu;
            $sumY  += $yy;
            $sumUY += $uu * $yy;
            $sumU2 += $uu * $uu;
            $sumY2 += $yy * $yy;
            $pts++;
        }
        if ($pts < 15) continue;

        $mU = $sumU / $pts; $mY = $sumY / $pts;
        $vU = $sumU2 / $pts - $mU * $mU;
        $vY = $sumY2 / $pts - $mY * $mY;
        $cov = $sumUY / $pts - $mU * $mY;
        if ($vU <= 1e-6 || $vY <= 1e-6) continue;

        $corr = $cov / sqrt($vU * $vY);
        $K    = $cov / $vU; // regression slope: mg/L per % actuator

        // We expect physically positive gain for chlorine
        if ($K > 0 && $corr > $bestCorr) {
            $bestCorr = $corr;
            $bestLag  = $lagSec;
            $bestK    = $K;
            $bestPts  = $pts;
        }
    }

    // Limiar de correlação reduzido (0.10) e marcado como confiança baixa
    // — sugestões posteriores aplicam clamps ainda mais apertados nesse caso.
    if ($bestLag === null || $bestK === null || $bestCorr < 0.10) {
        $empty['reasons'] = array_merge($extraReasons, ['Correlação atuador→cloro insuficiente para estimativa heurística (r_max=' . round(max(0.0, $bestCorr), 2) . ').']);
        return $empty;
    }

    // τ: prefer observed recovery; otherwise stabilization minus dead-time; otherwise 3*L.
    $tau = null;
    $tauSource = '';
    if ($meanRecoverySec !== null && is_numeric($meanRecoverySec) && $meanRecoverySec > 0) {
        $tau = (float)$meanRecoverySec;
        $tauSource = 'tempo médio de recuperação';
    } elseif ($meanStabilizationSec !== null && is_numeric($meanStabilizationSec) && $meanStabilizationSec > $bestLag) {
        $tau = (float)$meanStabilizationSec - (float)$bestLag;
        $tauSource = 'estabilização menos dead-time';
    } else {
        $tau = (float)$bestLag * 3.0;
        $tauSource = 'heurística τ≈3·L';
    }
    $tau = max(60.0, min(7200.0, $tau));

    // Confidence based on correlation strength and sample count
    $r2 = $bestCorr * $bestCorr;
    if ($bestCorr >= 0.40 && $bestPts >= 60) {
        $confidence = 'alta';
    } elseif ($bestCorr >= 0.20 && $bestPts >= 30) {
        $confidence = 'media';
    } else {
        $confidence = 'baixa';
    }
    $dispersion = max(0.0, min(1.0, 1.0 - $r2));

    $reasons = array_merge($extraReasons, [
        'Modelo estimado por regressão lag-otimizada (r=' . round($bestCorr, 3) . ', ' . $bestPts . ' pares).',
        'τ adotado a partir de: ' . $tauSource . '.',
        'Sem degraus mantidos disponíveis — usadas as variações naturais do atuador no período.',
    ]);

    return [
        'available'  => true,
        'source'     => 'heuristic',
        'events'     => $bestPts,                 // points used in regression
        'K'          => round((float)$bestK, 4),
        'tau_sec'    => (int)round((float)$tau),
        'L_sec'      => (int)round((float)$bestLag),
        'dispersion' => round($dispersion, 3),
        'confidence' => $confidence,
        'reasons'    => $reasons,
    ];
}

/**
 * Filtra a série para conter apenas pontos próximos (±$windowSec) de momentos
 * em que o atuador estava ativo: bomba > 1% ou variação ≥ 1% face ao ponto anterior.
 *
 * Útil quando o gráfico tem grandes períodos de bomba a 0% (perturbação externa
 * ou consumo baixo) que diluem a correlação com a resposta do cloro.
 */
function extractControllerActiveSeries($series, $windowSec = 900) {
    $n = count($series);
    if ($n === 0) return [];

    // Marca timestamps em que houve actividade do atuador
    $activeTs = [];
    for ($i = 0; $i < $n; $i++) {
        $u = isset($series[$i]['controller']) ? $series[$i]['controller'] : null;
        if ($u === null) continue;
        $uF = (float)$u;
        $isActive = ($uF > 1.0);
        if (!$isActive && $i > 0) {
            $uPrev = isset($series[$i - 1]['controller']) ? $series[$i - 1]['controller'] : null;
            if ($uPrev !== null && abs($uF - (float)$uPrev) >= 1.0) {
                $isActive = true;
            }
        }
        if ($isActive) $activeTs[] = (int)$series[$i]['ts'];
    }
    if (empty($activeTs)) return [];

    sort($activeTs);
    $count = count($activeTs);
    $filtered = [];
    $idx = 0;
    foreach ($series as $point) {
        $t = (int)$point['ts'];
        // Avança o ponteiro até o primeiro timestamp ativo >= t - window.
        while ($idx < $count && $activeTs[$idx] < ($t - $windowSec)) $idx++;
        if ($idx >= $count) break; // sem mais janelas ativas à frente
        if ($activeTs[$idx] <= ($t + $windowSec)) {
            $filtered[] = $point;
        }
    }
    return $filtered;
}

/**
 * Tier 3: sugestão "micro-heurística" baseada apenas em métricas observadas
 * (viés, oscilação, recuperação) quando nem o FOPDT estrito nem o heurístico
 * estão disponíveis mas há atividade mínima do controlador.
 *
 * Clamps muito apertados (P 3%, I 5%) e mudanças só em uma direção por ciclo.
 * Retorna null se não houver evidência sequer mínima para sugerir.
 */
function microHeuristicSuggestion($stats, $currentPid, $context = []) {
    $p = isset($currentPid['p']) ? floatOrNull($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? floatOrNull($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? floatOrNull($currentPid['d']) : null;
    if ($p === null && $i === null) return null;

    $samples = isset($stats['samples']) ? (int)$stats['samples'] : 0;
    $errMean = isset($stats['mean'])     ? (float)$stats['mean']     : 0.0;
    $errMeanAbs = isset($stats['mean_abs']) ? (float)$stats['mean_abs'] : 0.0;
    $stdev   = isset($stats['stdev'])    ? (float)$stats['stdev']    : 0.0;
    $signChangeRate = isset($stats['sign_change_rate']) ? (float)$stats['sign_change_rate'] : 0.0;

    if ($samples < 30) return null;

    $saturation = isset($context['saturation']) ? $context['saturation'] : [];
    $satHigh = !empty($saturation['saturated_high']);
    if ($satHigh) return null; // problema físico — não é tuning

    $rec = isset($context['recovery']) ? $context['recovery'] : [];
    $meanRecovery = isset($rec['mean_recovery_sec']) ? $rec['mean_recovery_sec'] : null;

    $reasons = [];
    $direction = 'manter';

    // Decisão por prioridade:
    // 1) Oscilação alta (sign change rate > 15%) → reduzir Kp
    // 2) Erro persistente / viés relevante → reduzir Ti (mais ação integral)
    // 3) Recuperação lenta sem oscilação → aumentar Kp ligeiramente
    if ($signChangeRate > 0.15 && $stdev > 0.20) {
        $direction = 'reduce_p';
        $reasons[] = 'Oscilação elevada detetada (mudanças de sinal ' . round($signChangeRate * 100, 1) . '%, σ=' . round($stdev, 3) . ').';
    } elseif (abs($errMean) > 0.15 && $errMeanAbs > 0.20) {
        $direction = 'reduce_i';
        $reasons[] = 'Viés persistente (erro médio=' . round($errMean, 3) . ', MAE=' . round($errMeanAbs, 3) . ').';
    } elseif ($meanRecovery !== null && (float)$meanRecovery > 1800 && $signChangeRate < 0.08) {
        $direction = 'increase_p';
        $reasons[] = 'Recuperação lenta (' . round((float)$meanRecovery / 60, 1) . ' min) sem oscilação.';
    } else {
        return [
            'suggestions' => ['Métricas dentro de margens razoáveis — manter sintonia e continuar a observar.'],
            'suggestedValues' => ['p' => $p, 'i' => $i, 'd' => $d],
            'strategy' => 'manter',
            'rationale' => ['Tier 3 micro-heurístico: sem desvio crítico para justificar alteração.'],
            'diagnostics' => ['tier3_no_action_needed'],
        ];
    }

    // Aplicar micro-ajuste com clamps muito apertados (3% P, 5% I).
    $newP = $p; $newI = $i;
    if ($direction === 'reduce_p' && $p !== null) {
        $newP = max(0.01, $p * 0.97);
    } elseif ($direction === 'increase_p' && $p !== null) {
        $newP = min(100.0, $p * 1.03);
    } elseif ($direction === 'reduce_i' && $i !== null && $i > 0) {
        $newI = max(1.0, $i * 0.95);
    }

    $suggestions = ['Micro-ajuste baseado em métricas observadas (sem modelo FOPDT identificável):'];
    foreach ($reasons as $r) $suggestions[] = $r;
    if ($p !== null && $newP !== $p) {
        $suggestions[] = 'Kp atual: ' . $p . ' → Sugerido: ' . round($newP, 6) . ' (variação ' . round(($newP - $p) / max(1e-6, $p) * 100, 1) . '%).';
    }
    if ($i !== null && $newI !== $i) {
        $suggestions[] = 'Ti atual: ' . $i . ' → Sugerido: ' . round($newI, 2) . 's (variação ' . round(($newI - $i) / max(1e-6, $i) * 100, 1) . '%).';
    }
    $suggestions[] = 'Após gravar, observar 24-48h antes de novo ajuste.';

    return [
        'suggestions' => $suggestions,
        'suggestedValues' => ['p' => $newP, 'i' => $newI, 'd' => $d],
        'strategy' => 'micro_heuristica',
        'rationale' => array_merge(['Tier 3 micro-heurística: clamps por ciclo reduzidos a 3-5% por falta de modelo do processo.'], $reasons),
        'diagnostics' => ['tier3_' . $direction],
    ];
}

/**
 * Lambda (IMC) tuning para PID interactivo (ideal) sobre FOPDT.
 *   Kc = (2τ + L) / (2K(λ + L))
 *   Ti = τ + L/2
 *   Td = (τ L) / (2τ + L)
 * λ é a constante de tempo desejada em malha fechada (maior λ → resposta mais lenta/robusta).
 */
function lambdaTuningFromFopdt($fopdt, $aggressiveness = 'equilibrado') {
    if (!$fopdt || empty($fopdt['available'])) return null;
    $K   = (float)$fopdt['K'];
    $tau = (float)$fopdt['tau_sec'];
    $L   = (float)$fopdt['L_sec'];
    if ($K <= 0 || $tau <= 0 || $L <= 0) return null;

    switch ($aggressiveness) {
        case 'agressivo':   $lambda = max($tau * 0.5, 1.5 * $L); break;
        case 'conservador': $lambda = max($tau * 1.5, 5.0 * $L); break;
        case 'equilibrado':
        default:            $lambda = max($tau,       3.0 * $L); break;
    }

    $Kc = (2.0 * $tau + $L) / (2.0 * $K * ($lambda + $L));
    $Ti = $tau + ($L / 2.0);
    $Td = ($tau * $L) / (2.0 * $tau + $L);

    // Envelopes de segurança absolutos (mesmos do clampPidSuggestion)
    $Kc = max(0.01, min(100.0, $Kc));
    $Ti = max(0.0,  min(7200.0, $Ti));
    $Td = max(0.0,  min(3600.0, $Td));

    return [
        'lambda_sec' => (int)round($lambda),
        'aggressiveness' => $aggressiveness,
        'p' => $Kc,
        'i' => $Ti,
        'd' => $Td,
    ];
}

/**
 * Avalia se a última alteração de PID melhorou ou piorou o controlo,
 * combinando deltas de MAE e desvio padrão.
 *
 * Retorna outcome ∈ {better, neutral, worse, unknown} e score (-100..100, positivo = melhor).
 */
function evaluateLastChangeOutcome($impact) {
    if (!$impact || empty($impact['before']) || empty($impact['after'])) {
        return ['outcome' => 'unknown', 'score' => 0, 'detail' => 'Sem dados antes/depois suficientes.'];
    }
    $beforeMae = (float)($impact['before']['mean_abs'] ?? 0);
    $afterMae  = (float)($impact['after']['mean_abs']  ?? 0);
    $beforeStd = (float)($impact['before']['stdev']    ?? 0);
    $afterStd  = (float)($impact['after']['stdev']     ?? 0);

    if ($beforeMae <= 0 && $beforeStd <= 0) {
        return ['outcome' => 'unknown', 'score' => 0, 'detail' => 'Métricas de referência inválidas.'];
    }

    // Variação relativa (negativa = melhor). Cap a ±100%.
    $relMae = $beforeMae > 0 ? max(-1.0, min(1.0, ($afterMae - $beforeMae) / $beforeMae)) : 0;
    $relStd = $beforeStd > 0 ? max(-1.0, min(1.0, ($afterStd - $beforeStd) / $beforeStd)) : 0;

    // Score positivo = melhoria. Pesos: 60% precisão, 40% estabilidade.
    $score = -((0.60 * $relMae) + (0.40 * $relStd)) * 100.0;
    $score = round(max(-100.0, min(100.0, $score)), 1);

    if ($score >= 10)  $outcome = 'better';
    elseif ($score <= -10) $outcome = 'worse';
    else $outcome = 'neutral';

    $detail = sprintf(
        'MAE %s%.1f%% e desvio padrão %s%.1f%% face às 24h anteriores.',
        $relMae >= 0 ? '+' : '', $relMae * 100,
        $relStd >= 0 ? '+' : '', $relStd * 100
    );

    return ['outcome' => $outcome, 'score' => $score, 'detail' => $detail];
}

function pidRecommendations($mode, $stats, $currentPid, $context = []) {
    $suggestions = [];
    $rationale = [];
    $diagnostics = [];

    $p = isset($currentPid['p']) ? floatOrNull($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? floatOrNull($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? floatOrNull($currentPid['d']) : null;

    $fopdt      = isset($context['fopdt']) && is_array($context['fopdt']) ? $context['fopdt'] : null;
    $modelTune  = isset($context['model_tuning']) && is_array($context['model_tuning']) ? $context['model_tuning'] : null;
    $saturation = isset($context['saturation']) && is_array($context['saturation']) ? $context['saturation'] : null;
    $learning   = isset($context['learning']) && is_array($context['learning']) ? $context['learning'] : null;
    $previousPid = isset($context['previous_pid']) && is_array($context['previous_pid']) ? $context['previous_pid'] : null;
    $confidence  = isset($context['confidence']) && is_array($context['confidence']) ? $context['confidence'] : null;
    $direction  = isset($context['direction_evidence']) && is_array($context['direction_evidence']) ? $context['direction_evidence'] : [];

    $zeroGlitches = isset($context['zero_glitch_count']) ? (int)$context['zero_glitch_count'] : 0;
    $zeroObserved = isset($context['zero_observed_count']) ? (int)$context['zero_observed_count'] : 0;
    $controllerZeroPct = isset($context['controller_zero_pct']) && is_numeric($context['controller_zero_pct'])
        ? (float)$context['controller_zero_pct']
        : null;

    $samples = isset($stats['samples']) ? (int)$stats['samples'] : 0;
    $errMean    = isset($stats['mean'])     ? (float)$stats['mean']     : 0.0;
    $errMeanAbs = isset($stats['mean_abs']) ? (float)$stats['mean_abs'] : 0.0;
    $errThreshold = ($mode === 'chlorine') ? 0.25 : 0.15;
    $confLevel = $confidence['level'] ?? 'baixa';
    $confScore = isset($confidence['score']) ? (float)$confidence['score'] : 0.0;

    $tightThreshold = ($mode === 'chlorine') ? 0.20 : 0.10;
    $chlorineHighAndPumpAtMin = (
        $mode === 'chlorine'
        && $errMean > ($tightThreshold * 0.75)
        && $controllerZeroPct !== null
        && $controllerZeroPct >= 40.0
    );
    $saturatedHigh = !empty($saturation['saturated_high']);
    $directionReliable = !empty($direction['reliable']);
    $directionPositive = (($direction['gain_sign'] ?? 'unknown') === 'positive');
    $directionSamples = isset($direction['samples']) ? (int)$direction['samples'] : 0;

    $modelAvailable = !empty($fopdt['available']) && $modelTune && isset($modelTune['p'], $modelTune['i'], $modelTune['d']);
    $modelConfidence = $fopdt['confidence'] ?? 'baixa';
    $modelEvents = isset($fopdt['events']) ? (int)$fopdt['events'] : 0;
    $modelDispersion = isset($fopdt['dispersion']) ? (float)$fopdt['dispersion'] : 1.0;
    $modelSource = $fopdt['source'] ?? 'step_events';
    $modelReliable = $modelAvailable
        && $modelSource === 'step_events'
        && in_array($modelConfidence, ['alta', 'media'], true)
        && $modelEvents >= 2
        && $modelDispersion <= 0.60;
    // Modelo heurístico (regressão lag-otimizada do gráfico): admite-se
    // confiança média/alta e K>0 já validado dentro de identifyFopdtChlorineHeuristic.
    $modelHeuristic = $modelAvailable
        && $modelSource === 'heuristic'
        && in_array($modelConfidence, ['alta', 'media'], true);
    $modelUsable = $modelReliable || $modelHeuristic;

    $blockedReturn = function($msg, $code) use ($p, $i, $d, &$suggestions, &$rationale, &$diagnostics) {
        $suggestions[] = $msg;
        $rationale[] = 'Sem evidência suficiente para alteração segura neste ciclo.';
        $diagnostics[] = $code;
        return [
            'suggestions' => $suggestions,
            'suggestedValues' => ['p' => $p, 'i' => $i, 'd' => $d],
            'strategy' => 'bloqueado_por_evidencia_insuficiente',
            'rationale' => $rationale,
            'diagnostics' => $diagnostics,
        ];
    };

    // Tier 3 fallback wrapper: tenta produzir sugestão micro-heurística e, se
    // mesmo isso não for possível, devolve o bloqueio com a razão original.
    $tier3OrBlock = function($msg, $code) use ($mode, $stats, $currentPid, $context, $blockedReturn) {
        if ($mode === 'chlorine') {
            $tier3 = microHeuristicSuggestion($stats, $currentPid, $context);
            if ($tier3 !== null) {
                array_unshift($tier3['suggestions'], 'Modelo do processo indisponível (' . $msg . '). Aplicado micro-ajuste conservador baseado nas métricas observadas.');
                $tier3['diagnostics'][] = 'tier3_replaced_block_' . $code;
                return $tier3;
            }
        }
        return $blockedReturn($msg, $code);
    };

    // Gate 1: qualidade mínima de dados e confiança.
    // Com modelo heurístico aceita-se confiança 'media' (sem exigir score>=60),
    // para permitir sugestão credível quando não há degraus mantidos do atuador.
    $minSamples = $modelHeuristic ? 30 : 45;
    $minConfScore = $modelHeuristic ? 40.0 : 60.0;
    $allowedConfLevels = $modelHeuristic ? ['alta', 'media', 'baixa'] : ['alta', 'media'];
    if ($samples < $minSamples
        || !in_array($confLevel, $allowedConfLevels, true)
        || $confScore < $minConfScore) {
        return $blockedReturn(
            'Sugestão bloqueada: evidência insuficiente (' . $samples . ' amostras, confiança ' . $confLevel . ' ' . round($confScore, 1) . '%).',
            'blocked_low_confidence'
        );
    }

    // Gate 2: direção física do processo.
    // O modelo heurístico só é "available" quando o K estimado já é positivo,
    // pelo que serve como confirmação alternativa à evidência direcional clássica.
    $directionConfirmed = ($directionReliable && $directionPositive && $directionSamples >= 12)
        || ($modelHeuristic && isset($fopdt['K']) && (float)$fopdt['K'] > 0);
    if ($mode === 'chlorine' && !$directionConfirmed) {
        // Em vez de bloquear cegamente, tenta Tier 3 (micro-heurística).
        // O viés/oscilação/recuperação observados já dão evidência suficiente
        // para micro-ajustes seguros mesmo sem direção confirmada.
        return $tier3OrBlock(
            'direção física atuador→cloro não comprovada (amostras úteis ' . $directionSamples . ')',
            'direction_unreliable'
        );
    }

    // Gate 3: sem modelo utilizável (nem estrito nem heurístico), sem ajuste numérico.
    if (!$modelUsable) {
        // Mesmo sem FOPDT, o Tier 3 pode oferecer micro-ajuste credível.
        return $tier3OrBlock(
            'modelo do processo sem confiabilidade suficiente (confiança ' . $modelConfidence . ', fonte ' . $modelSource . ', eventos ' . $modelEvents . ', dispersão ' . round($modelDispersion * 100, 1) . '%)',
            'model_unreliable'
        );
    }

    // Gate 4: limitação física evidente.
    if ($saturatedHigh) {
        return $blockedReturn(
            'Sugestão bloqueada: atuador saturado em alta (' . ($saturation['pct_at_max'] ?? 0) . '% no máximo).',
            'blocked_high_saturation'
        );
    }
    if ($chlorineHighAndPumpAtMin) {
        return $blockedReturn(
            'Sugestão bloqueada: cloro acima do setpoint com bomba em 0% por ' . round($controllerZeroPct, 1) . '% do período.',
            'blocked_high_chlorine_low_output'
        );
    }

    // ---------- Camada 1: adaptativo — reagir à última alteração -----------------
    $lastOutcome = $learning['outcome'] ?? 'unknown';
    if ($lastOutcome === 'worse' && $previousPid && isset($previousPid['p'])) {
        $prevP = floatOrNull($previousPid['p'] ?? null);
        $prevI = floatOrNull($previousPid['i'] ?? null);
        $prevD = floatOrNull($previousPid['d'] ?? null);

        // Caminhar 50% de volta para os valores anteriores.
        $revertP = ($p !== null && $prevP !== null) ? ($p + ($prevP - $p) * 0.5) : $p;
        $revertI = ($i !== null && $prevI !== null) ? ($i + ($prevI - $i) * 0.5) : $i;
        $revertD = ($d !== null && $prevD !== null) ? ($d + ($prevD - $d) * 0.5) : $d;

        // Aplica clamps de envelope mas não restringe a 15% (revert é intencional).
        $revertP = $revertP !== null ? max(0.01, min(100.0,  (float)$revertP)) : null;
        $revertI = $revertI !== null ? max(0.0,  min(7200.0, (float)$revertI)) : null;
        $revertD = $revertD !== null ? max(0.0,  min(3600.0, (float)$revertD)) : null;

        $suggestions[] = 'A última alteração de PID parece ter piorado o controlo ('
            . ($learning['detail'] ?? '') . '). Sugerimos reverter 50% em direção aos valores anteriores.';
        if ($p !== null) $suggestions[] = 'Kp: ' . round($p, 4) . ' → ' . round($revertP, 4)
            . ' (anterior: ' . round($prevP, 4) . ')';
        if ($i !== null) $suggestions[] = 'Ti: ' . round($i, 2) . ' → ' . round($revertI, 2)
            . ' (anterior: ' . ($prevI !== null ? round($prevI, 2) : 'N/A') . ')';
        if ($d !== null) $suggestions[] = 'Td: ' . round($d, 2) . ' → ' . round($revertD, 2)
            . ' (anterior: ' . ($prevD !== null ? round($prevD, 2) : 'N/A') . ')';
        $rationale[] = 'Reverter parcialmente a última alteração que se mostrou prejudicial.';

        return [
            'suggestions'     => $suggestions,
            'suggestedValues' => ['p' => $revertP, 'i' => $revertI, 'd' => $revertD],
            'strategy'        => 'ajuste_com_confianca',
            'rationale'       => $rationale,
            'diagnostics'     => $diagnostics,
        ];
    }

    if ($lastOutcome === 'better') {
        $suggestions[] = 'A última alteração melhorou o controlo ('
            . ($learning['detail'] ?? '') . '). Manter sintonia atual e continuar a observar.';
        $rationale[]  = 'Não mexer no que está a melhorar.';
        return [
            'suggestions'     => $suggestions,
            'suggestedValues' => ['p' => $p, 'i' => $i, 'd' => $d],
            'strategy'        => 'manter',
            'rationale'       => $rationale,
            'diagnostics'     => $diagnostics,
        ];
    }

    // Alvo baseado em modelo (Lambda/IMC) com evidência suficiente.
    $targetP = $p; $targetI = $i; $targetD = $d;
    $targetP = (float)$modelTune['p'];
    $targetI = (float)$modelTune['i'];
    $targetD = (float)$modelTune['d'];

    // Regra anti-contradição: com cloro acima do SP, não reduzir Ti.
    if ($mode === 'chlorine' && $errMean > ($tightThreshold * 0.5) && $i !== null && $i > 0 && $targetI < $i) {
        $targetI = $i;
        $diagnostics[] = 'ti_reduction_blocked_high_chlorine';
        $suggestions[] = 'Bloqueio de segurança: redução de Ti anulada porque o cloro está acima do setpoint neste período.';
    }

    // ---------- Camada 5: clamp por ciclo --------------------------------------
    // Modelo estrito (degraus mantidos): P 10%, I 15%, D 15% por ciclo.
    // Modelo heurístico (regressão lag-otimizada): P 5%, I 8%, D 8% — mais conservador,
    // porque o modelo foi inferido de variações naturais e não de excitação controlada.
    if ($modelHeuristic && !$modelReliable) {
        $stepP = 0.05; $stepI = 0.08; $stepD = 0.08;
    } else {
        $stepP = 0.10; $stepI = 0.15; $stepD = 0.15;
    }
    $suggestedP = clampPidSuggestion($p, $targetP, 0.01, 100.0,  $stepP);
    $suggestedI = clampPidSuggestion($i, $targetI, 0.0,  7200.0, $stepI);
    $suggestedD = clampPidSuggestion($d, $targetD, 0.0,  3600.0, $stepD);

    // ---------- Notas adicionais ------------------------------------------------
    if ($zeroObserved > 0) {
        $suggestions[] = 'Observadas ' . $zeroObserved . ' leituras em zero/quase zero ('
            . $zeroGlitches . ' filtradas como espúrias).';
    }

    // Se tudo igual no fim → estabilidade ok
    $changedP = ($p !== null && $suggestedP !== null && abs($suggestedP - $p) > 1e-6);
    $changedI = ($i !== null && $suggestedI !== null && abs($suggestedI - $i) > 1e-6);
    $changedD = ($d !== null && $suggestedD !== null && abs($suggestedD - $d) > 1e-6);
    if (!$changedP && !$changedI && !$changedD) {
        $suggestions[] = 'Modelo confiável confirma sintonia atual dentro dos limites seguros por ciclo. Sem alteração.';
        $rationale[] = 'Sem mudança líquida segura após aplicar modelo e guard rails.';
        return [
            'suggestions'     => $suggestions,
            'suggestedValues' => ['p' => $p, 'i' => $i, 'd' => $d],
            'strategy'        => 'manter',
            'rationale'       => $rationale,
            'diagnostics'     => $diagnostics,
        ];
    }

    $rationale[] = sprintf(
        'Modelo FOPDT %s (K=%.4f, tau=%ds, L=%ds, dispersão=%.1f%%, confiança=%s).',
        $modelSource === 'heuristic' ? 'heurístico (regressão lag-otimizada do gráfico)' : 'validado por degraus',
        (float)($fopdt['K'] ?? 0),
        (int)($fopdt['tau_sec'] ?? 0),
        (int)($fopdt['L_sec'] ?? 0),
        ((float)($fopdt['dispersion'] ?? 0.0)) * 100.0,
        $modelConfidence
    );
    if ($modelSource === 'heuristic') {
        $rationale[] = 'Direção física confirmada pelo K>0 da regressão; clamps por ciclo reduzidos (P 5%, I 8%, D 8%).';
    } else {
        $rationale[] = 'Direção física confirmada (atuador→cloro) e gates de segurança aprovados.';
    }

    if ($p !== null) {
        $suggestions[] = 'Kp atual: ' . $p . ' → Sugerido: ' . round($suggestedP, 6);
    }
    if ($i !== null || $suggestedI !== null) {
        $currentIText  = ($i === null || $i <= 0) ? 'não definido/0' : (string)$i;
        $suggestedIText = $suggestedI !== null ? round($suggestedI, 2) : 'N/A';
        $suggestions[] = 'Ti atual: ' . $currentIText . ' → Sugerido: ' . $suggestedIText . 's.';
    }
    if ($d !== null) {
        $suggestions[] = 'Td atual: ' . $d . ' → Sugerido: ' . round($suggestedD, 2) . 's.';
    }

    return [
        'suggestions'     => $suggestions,
        'suggestedValues' => [
            'p' => $suggestedP,
            'i' => $suggestedI !== null ? (float)$suggestedI : null,
            'd' => $suggestedD !== null ? (float)$suggestedD : null,
        ],
        'strategy'        => ($modelHeuristic && !$modelReliable) ? 'modelo_heuristico_grafico' : 'ajuste_com_confianca',
        'rationale'       => $rationale,
        'diagnostics'     => $diagnostics,
    ];
}

$phErrors = [];
$clSeries = [];
$times = [];

foreach ($history as $row) {
    $ph = floatOrNull($row['ph_value']);
    $ph_sp = floatOrNull($row['ph_setpoint']);
    $cl = floatOrNull($row['chlorine_value']);
    // Usa o SP base fixo quando disponível (SP dinâmico inativo ou não registado);
    // cai para chlorine_setpoint (SP lido do controlador) como fallback.
    $cl_sp = floatOrNull($row['chlorine_base_setpoint'] ?? null) ?? floatOrNull($row['chlorine_setpoint']);

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

$controllerSamples = 0;
$controllerZeroSamples = 0;
foreach ($cleanSeries as $point) {
    if (!isset($point['controller']) || $point['controller'] === null || !is_numeric($point['controller'])) {
        continue;
    }
    $controllerSamples++;
    if ((float)$point['controller'] <= 0.01) {
        $controllerZeroSamples++;
    }
}
$controllerZeroPct = $controllerSamples > 0 ? (($controllerZeroSamples / $controllerSamples) * 100.0) : null;

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

// --- Histórico de alterações de PID (carregado cedo para alimentar o motor adaptativo) ---
$pid_change_history = [];
$stmt_history = $conn->prepare("SELECT changed_at, p, i, d, reason, changed_by FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 5");
if ($stmt_history) {
    $stmt_history->bind_param('i', $tank_id);
    if ($stmt_history->execute()) {
        $pid_change_history = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_history->close();
}

// --- Identificação FOPDT do processo (K, τ, L) e tuning Lambda baseado em modelo ---
$fopdt        = identifyFopdtChlorine($cleanSeries);

// Fallback heurístico: quando não há degraus mantidos suficientes para o modelo
// estrito, estima K/τ/L diretamente da correlação atuador→cloro ao longo do
// período mostrado no gráfico. Mantém os mesmos campos, mas marca source='heuristic'.
if (empty($fopdt['available']) || ($fopdt['confidence'] ?? 'baixa') === 'baixa') {
    $heuristicFopdt = identifyFopdtChlorineHeuristic(
        $cleanSeries,
        $cl_mean_delay,
        isset($clRecovery['mean_recovery_sec']) ? $clRecovery['mean_recovery_sec'] : null,
        isset($clRecovery['mean_stabilization_sec']) ? $clRecovery['mean_stabilization_sec'] : null
    );
    if (!empty($heuristicFopdt['available'])) {
        // Mantém o resultado estrito como referência se existir, mas usa o heurístico.
        if (!empty($fopdt['available'])) {
            $heuristicFopdt['reasons'][] = 'Modelo estrito existia mas com baixa confiança; usado fallback heurístico.';
        }
        $fopdt = $heuristicFopdt;
    }
}

$modelTuning  = lambdaTuningFromFopdt($fopdt, 'equilibrado');
$saturation   = detectActuatorSaturation($cleanSeries);
$directionEvidence = calcActuationDirectionEvidence($cleanSeries, $cl_mean_delay);

// --- Avaliação adaptativa: impacto da última alteração e valor "anterior" para revert ---
$beforeAfterImpact = null;
if (!empty($pid_change_history) && isset($pid_change_history[0]['changed_at'])) {
    $beforeAfterImpact = calcBeforeAfterImpact($cleanSeries, $pid_change_history[0]['changed_at']);
}
$lastOutcome = evaluateLastChangeOutcome($beforeAfterImpact);

$previousPid = null;
if (isset($pid_change_history[1])) {
    $previousPid = [
        'p' => floatOrNull($pid_change_history[1]['p'] ?? null),
        'i' => floatOrNull($pid_change_history[1]['i'] ?? null),
        'd' => floatOrNull($pid_change_history[1]['d'] ?? null),
        'changed_at' => $pid_change_history[1]['changed_at'] ?? null,
    ];
}

$recommendationsResult = pidRecommendations('chlorine', $statsForRecommendations, $tankPid, [
    'mean_response_delay_sec' => $cl_mean_delay,
    'recovery' => $clRecovery,
    'zero_glitch_count' => $zeroGlitchCount,
    'zero_observed_count' => $zeroObservedCount,
    'controller_zero_pct' => $controllerZeroPct,
    'fopdt' => $fopdt,
    'model_tuning' => $modelTuning,
    'saturation' => $saturation,
    'direction_evidence' => $directionEvidence,
    'learning' => $lastOutcome,
    'previous_pid' => $previousPid,
    'confidence' => $confidence,
]);
$recommendations = [
    'chlorine' => $recommendationsResult['suggestions'],
];
$suggestedValues = $recommendationsResult['suggestedValues'];
$strategy = $recommendationsResult['strategy'] ?? 'manter';
$rationale = $recommendationsResult['rationale'] ?? [];
$diagnosticsFlags = $recommendationsResult['diagnostics'] ?? [];

// Últimas alterações de PID registradas (já carregadas acima)

// Verificar se há bloqueio de 48 horas após última aceitação de sugestão
$lastChangeTime = null;
$canAcceptSuggestion = true;
$blockReason = null;

if ($pid_change_history && count($pid_change_history) > 0) {
    $lastChange = $pid_change_history[0]; // Já ordenado por DESC, então primeiro é o mais recente
    $lastChangeTime = strtotime($lastChange['changed_at']);
    $hoursSinceLastChange = (time() - $lastChangeTime) / 3600;

    if ($hoursSinceLastChange < 48) {
        $canAcceptSuggestion = false;
        $remainingHours = ceil(48 - $hoursSinceLastChange);
        $blockReason = "Período de monitorização ativo. Última alteração foi há " . round($hoursSinceLastChange, 1) . " horas. Aguarde mais " . $remainingHours . " horas para aceitar nova sugestão.";
    }
}

// $beforeAfterImpact já calculado mais acima.

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
        'action_plan' => $actionPlan,
        // --- Novos campos do motor híbrido (modelo + adaptativo) ---
        'process_model'      => $fopdt,
        'model_tuning'       => $modelTuning,
        'saturation'         => $saturation,
        'direction_evidence' => $directionEvidence,
        'learning'           => array_merge($lastOutcome, [
            'previous_pid' => $previousPid,
        ]),
        'strategy'           => $strategy,
        'rationale'          => $rationale,
        'diagnostics_flags'  => $diagnosticsFlags,
    ],
    'current_pid' => $tankPid,
    'pid_change_history' => $pid_change_history,
];

// Limpa qualquer output buffering antes de enviar JSON
if (ob_get_length()) {
    ob_end_clean();
}

echo json_encode($response);
