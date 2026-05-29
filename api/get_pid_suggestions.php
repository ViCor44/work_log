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
    // -------------------------------------------------------------------------
    // Motor de sugestão híbrido (modelo FOPDT + adaptativo histórico + segurança).
    // Princípios:
    //   1. Se há modelo de processo válido (FOPDT identificado), o alvo principal
    //      é o tuning Lambda (IMC). Heurísticas servem só de ajuste fino.
    //   2. Se a última alteração piorou (worse), propomos REVERTER 50% (passo
    //      em direcção aos valores anteriores) em vez de empurrar mais.
    //   3. Se o atuador está saturado em "high", aumentos de Kp ficam bloqueados
    //      (mais ganho não ajuda quando a bomba já está no máximo).
    //   4. Confiança insuficiente OU melhoria recente (better) → não sugerir
    //      números novos, manter ("não mexer no que está a melhorar").
    //   5. Passo por ciclo limitado a 10–15 % (modo equilibrado).
    // -------------------------------------------------------------------------

    $suggestions     = [];
    $rationale       = [];
    $diagnostics     = [];

    $p = isset($currentPid['p']) ? floatOrNull($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? floatOrNull($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? floatOrNull($currentPid['d']) : null;

    $fopdt      = isset($context['fopdt']) && is_array($context['fopdt']) ? $context['fopdt'] : null;
    $modelTune  = isset($context['model_tuning']) && is_array($context['model_tuning']) ? $context['model_tuning'] : null;
    $saturation = isset($context['saturation']) && is_array($context['saturation']) ? $context['saturation'] : null;
    $learning   = isset($context['learning']) && is_array($context['learning']) ? $context['learning'] : null;
    $previousPid = isset($context['previous_pid']) && is_array($context['previous_pid']) ? $context['previous_pid'] : null;
    $confidence  = isset($context['confidence']) && is_array($context['confidence']) ? $context['confidence'] : null;
    $recovery    = isset($context['recovery']) && is_array($context['recovery']) ? $context['recovery'] : [];

    $zeroGlitches = isset($context['zero_glitch_count']) ? (int)$context['zero_glitch_count'] : 0;
    $zeroObserved = isset($context['zero_observed_count']) ? (int)$context['zero_observed_count'] : 0;

    $samples = isset($stats['samples']) ? (int)$stats['samples'] : 0;
    $errMean    = isset($stats['mean'])     ? (float)$stats['mean']     : 0.0;
    $errMeanAbs = isset($stats['mean_abs']) ? (float)$stats['mean_abs'] : 0.0;
    $errStdev   = isset($stats['stdev'])    ? (float)$stats['stdev']    : 0.0;
    $signRate   = isset($stats['sign_change_rate']) ? (float)$stats['sign_change_rate'] : 0.0;

    $errThreshold   = ($mode === 'chlorine') ? 0.25 : 0.15;
    $tightThreshold = ($mode === 'chlorine') ? 0.20 : 0.10;
    $hasOscillations = $errStdev > max($errThreshold, 0.2);
    $hasHighError    = $errMeanAbs > $errThreshold;
    $hasBias         = abs($errMean) > ($tightThreshold * 0.75) && $signRate < 0.35;

    // ---------- Camada 0: confiança mínima --------------------------------------
    $confLevel = $confidence['level'] ?? 'baixa';
    if ($samples < 30 || $confLevel === 'baixa') {
        $suggestions[] = 'Amostragem reduzida ou confiança baixa ('
            . $samples . ' amostras, confiança ' . $confLevel
            . '). Sem alteração proposta — aguardar mais dados antes de mexer no PID.';
        $rationale[]  = 'Manter atual (confiança insuficiente).';
        return [
            'suggestions'      => $suggestions,
            'suggestedValues'  => ['p' => $p, 'i' => $i, 'd' => $d],
            'strategy'         => 'manter',
            'rationale'        => $rationale,
            'diagnostics'      => $diagnostics,
        ];
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
            'strategy'        => 'reverter',
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

    // ---------- Camada 2: deteção de problema físico (saturação) -----------------
    $saturatedHigh = !empty($saturation['saturated_high']);
    if ($saturatedHigh) {
        $suggestions[] = 'Atenção: a bomba de cloro esteve no máximo durante '
            . ($saturation['pct_at_max'] ?? 0) . '% do período. Isto indica problema físico (capacidade da bomba, caudal de recirculação ou demanda de cloro acima do disponível), não de sintonia. '
            . 'Bloqueamos qualquer aumento de Kp porque mais ganho não ajuda quando o atuador está saturado.';
        $diagnostics[] = 'actuator_saturated_high';
    }
    if (!empty($saturation['saturated_low'])) {
        $suggestions[] = 'A bomba esteve em 0% durante '
            . ($saturation['pct_at_min'] ?? 0) . '% do período (cloro residual elevado ou pouca demanda).';
        $diagnostics[] = 'actuator_saturated_low';
    }

    // ---------- Camada 3: alvo baseado em modelo (Lambda/IMC) ---------------------
    $targetP = $p; $targetI = $i; $targetD = $d;
    $strategy = 'heuristica';

    if ($modelTune && isset($modelTune['p']) && (($fopdt['confidence'] ?? 'baixa') !== 'baixa')) {
        $targetP = (float)$modelTune['p'];
        $targetI = (float)$modelTune['i'];
        $targetD = (float)$modelTune['d'];
        $strategy = 'modelo_lambda';
        $rationale[] = sprintf(
            'Modelo FOPDT identificado a partir de %d evento(s) de degrau (K=%.4f mg/L por %%, τ=%ds, L=%ds). '
            . 'Aplicado tuning Lambda equilibrado (λ=%ds): Kc=%.4f, Ti=%.0fs, Td=%.0fs.',
            (int)($fopdt['events'] ?? 0), (float)($fopdt['K'] ?? 0),
            (int)($fopdt['tau_sec'] ?? 0), (int)($fopdt['L_sec'] ?? 0),
            (int)($modelTune['lambda_sec'] ?? 0),
            $targetP, $targetI, $targetD
        );
        // Se o alvo do modelo está dentro de ±15% do atual, recomendar manter
        $closeP = ($p !== null && $p > 0) ? (abs($targetP - $p) / $p) <= 0.15 : false;
        if ($closeP && !$hasOscillations && !$hasHighError && !$hasBias) {
            $suggestions[] = 'O modelo do processo confirma que a sintonia atual está dentro da banda recomendada (±15% do alvo Lambda). Sem necessidade de alterar.';
            return [
                'suggestions'     => $suggestions,
                'suggestedValues' => ['p' => $p, 'i' => $i, 'd' => $d],
                'strategy'        => 'manter',
                'rationale'       => $rationale,
                'diagnostics'     => $diagnostics,
            ];
        }
        $suggestions[] = 'Tuning sugerido pelo modelo do processo (Lambda/IMC): '
            . 'Kp ' . round($targetP, 4) . ', Ti ' . round($targetI, 0) . 's, Td ' . round($targetD, 0) . 's.';
    } else {
        // Sem modelo válido → heurísticas conservadoras como fallback.
        $rationale[] = 'Modelo FOPDT não disponível (não houve degraus do atuador limpos suficientes); usado ajuste heurístico conservador.';
        if ($hasOscillations) {
            if ($p !== null) $targetP = $p * 0.92;     // -8 %
            if ($d !== null) $targetD = $d * 1.10;     // +10 %
            $suggestions[] = 'Oscilações detetadas (desvio padrão ' . round($errStdev, 3) . '). Reduzir Kp ~8% e aumentar Td ~10%.';
        } elseif ($hasHighError && !$saturatedHigh) {
            if ($p !== null) $targetP = $p * 1.10;     // +10 %
            $suggestions[] = 'Erro médio absoluto elevado (' . round($errMeanAbs, 3) . '). Aumentar Kp ~10%.';
        }
        if ($hasBias) {
            if ($i === null || $i <= 0) {
                $defaultTi = isset($context['mean_response_delay_sec']) && $context['mean_response_delay_sec'] > 0
                    ? max(300.0, min(7200.0, (float)$context['mean_response_delay_sec'] * 2.0))
                    : (($mode === 'chlorine') ? 1200.0 : 300.0);
                $targetI = $defaultTi;
                $suggestions[] = 'Viés persistente sem ação integral (Ti=0). Semear Ti=' . round($defaultTi, 0) . 's.';
            } else {
                $targetI = $i * 0.90;
                $suggestions[] = 'Viés persistente (média ' . round($errMean, 3) . '). Reduzir Ti ~10% para reforçar ação integral.';
            }
        }
    }

    // ---------- Camada 4: segurança — saturação bloqueia aumento de Kp -----------
    if ($saturatedHigh && $p !== null && $targetP > $p) {
        $diagnostics[] = 'kp_increase_blocked_due_to_saturation';
        $suggestions[] = 'Aumento de Kp anulado: atuador saturado.';
        $targetP = $p;
    }

    // ---------- Camada 5: clamp por ciclo (equilibrado: P 10%, I 15%, D 15%) -----
    $suggestedP = clampPidSuggestion($p, $targetP, 0.01, 100.0,  0.10);
    $suggestedI = clampPidSuggestion($i, $targetI, 0.0,  7200.0, 0.15);
    $suggestedD = clampPidSuggestion($d, $targetD, 0.0,  3600.0, 0.15);

    // ---------- Notas adicionais ------------------------------------------------
    if (!empty($recovery['unrecovered_count'])) {
        $suggestions[] = 'Foram detetadas '
            . (int)$recovery['unrecovered_count'] . ' perturbações sem recuperação completa — manter cautela.';
    }
    if ($zeroObserved > 0) {
        $suggestions[] = 'Observadas ' . $zeroObserved . ' leituras em zero/quase zero ('
            . $zeroGlitches . ' filtradas como espúrias).';
    }

    // Se tudo igual no fim → estabilidade ok
    $changedP = ($p !== null && $suggestedP !== null && abs($suggestedP - $p) > 1e-6);
    $changedI = ($i !== null && $suggestedI !== null && abs($suggestedI - $i) > 1e-6);
    $changedD = ($d !== null && $suggestedD !== null && abs($suggestedD - $d) > 1e-6);
    if (!$changedP && !$changedI && !$changedD) {
        $suggestions[] = 'Após avaliação, nenhuma alteração compensa o risco de toque no PID. Manter sintonia atual.';
        $strategy = 'manter';
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
        'strategy'        => $strategy,
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
$modelTuning  = lambdaTuningFromFopdt($fopdt, 'equilibrado');
$saturation   = detectActuatorSaturation($cleanSeries);

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
    'fopdt' => $fopdt,
    'model_tuning' => $modelTuning,
    'saturation' => $saturation,
    'learning' => $lastOutcome,
    'previous_pid' => $previousPid,
    'confidence' => $confidence,
]);
$recommendations = [
    'chlorine' => $recommendationsResult['suggestions'],
];
$suggestedValues = $recommendationsResult['suggestedValues'];
$strategy = $recommendationsResult['strategy'] ?? 'heuristica';
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
