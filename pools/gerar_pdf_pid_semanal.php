<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$days = isset($_GET['days']) && is_numeric($_GET['days']) && (int)$_GET['days'] > 0
    ? (int)$_GET['days']
    : 7;

$startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$generatedAt = date('Y-m-d H:i');
$generatedBy = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador';

function float_or_null($value) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function calc_stats($errors, $times) {
    $n = count($errors);
    if ($n === 0) {
        return null;
    }

    $sum = 0.0;
    $sumAbs = 0.0;
    $min = $errors[0];
    $max = $errors[0];

    foreach ($errors as $e) {
        $sum += $e;
        $sumAbs += abs($e);
        if ($e < $min) {
            $min = $e;
        }
        if ($e > $max) {
            $max = $e;
        }
    }

    $mean = $sum / $n;
    $meanAbs = $sumAbs / $n;

    $varSum = 0.0;
    foreach ($errors as $e) {
        $varSum += pow($e - $mean, 2);
    }
    $stdev = $n > 1 ? sqrt($varSum / ($n - 1)) : 0.0;

    $signChanges = 0;
    $prevSign = null;
    foreach ($errors as $e) {
        if ($e == 0) {
            continue;
        }
        $sign = $e > 0 ? 1 : -1;
        if ($prevSign !== null && $sign !== $prevSign) {
            $signChanges++;
        }
        $prevSign = $sign;
    }
    $signChangeRate = $n > 1 ? $signChanges / ($n - 1) : 0.0;

    $deriv = [];
    for ($i = 1; $i < $n; $i++) {
        $dt = max(1, strtotime($times[$i]) - strtotime($times[$i - 1]));
        $deriv[] = abs($errors[$i] - $errors[$i - 1]) / $dt;
    }
    $derivMean = $deriv ? array_sum($deriv) / count($deriv) : 0.0;

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

function calc_stats_from_series($series) {
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
    return calc_stats($errors, $times);
}

function clamp01($v) {
    return max(0.0, min(1.0, (float)$v));
}

function classify_window($timeString) {
    $hour = (int)date('G', strtotime($timeString));
    if ($hour >= 0 && $hour < 6) return 'Madrugada';
    if ($hour >= 6 && $hour < 12) return 'Manha';
    if ($hour >= 12 && $hour < 18) return 'Tarde';
    return 'Noite';
}

function calc_time_window_stats($series) {
    $buckets = [
        'Madrugada' => [],
        'Manha' => [],
        'Tarde' => [],
        'Noite' => [],
    ];

    foreach ($series as $point) {
        if (!isset($point['time'])) continue;
        $bucket = classify_window($point['time']);
        $buckets[$bucket][] = $point;
    }

    $result = [];
    foreach ($buckets as $name => $points) {
        $result[$name] = [
            'samples' => count($points),
            'stats' => calc_stats_from_series($points),
        ];
    }
    return $result;
}

function calc_confidence($stats, $rowCount, $zeroGlitchCount) {
    $samples = isset($stats['samples']) ? (int)$stats['samples'] : 0;
    $sampleScore = $samples >= 120 ? 1.0 : ($samples >= 60 ? 0.8 : ($samples >= 30 ? 0.55 : 0.30));
    $coverage = $rowCount > 0 ? ($samples / $rowCount) : 0.0;
    $coverageScore = clamp01($coverage);
    $qualityScore = clamp01(1.0 - min(0.5, ($zeroGlitchCount / max(1, $samples))));

    $score = (0.50 * $sampleScore) + (0.25 * $coverageScore) + (0.25 * $qualityScore);
    $level = $score >= 0.80 ? 'ALTA' : ($score >= 0.55 ? 'MEDIA' : 'BAIXA');

    return [
        'level' => $level,
        'score' => round($score * 100, 1),
    ];
}

function calc_composite_score($stats, $recovery, $zeroGlitches, $samples) {
    $precision = 100 - min(100, (($stats['mean_abs'] ?? 0) / 0.30) * 100);
    $stability = 100 - min(100, (($stats['stdev'] ?? 0) / 0.40) * 100);
    $recoveryMean = isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null ? (float)$recovery['mean_recovery_sec'] : 0;
    $recoveryScore = 100 - min(100, ($recoveryMean / 3600) * 100);
    $robustness = max(0, 100 - min(40, $zeroGlitches * 3));

    if ($samples < 15) {
        $precision *= 0.8;
        $stability *= 0.8;
        $recoveryScore *= 0.8;
    }

    return round((0.40 * $precision) + (0.25 * $stability) + (0.20 * $recoveryScore) + (0.15 * $robustness), 1);
}

function calc_contribution_split($stats, $recovery) {
    $variability = isset($stats['stdev']) ? (float)$stats['stdev'] : 0.0;
    $disturbanceCount = isset($recovery['disturbance_count']) ? (int)$recovery['disturbance_count'] : 0;
    $unrecovered = isset($recovery['unrecovered_count']) ? (int)$recovery['unrecovered_count'] : 0;

    $externalRaw = min(1.0, ($disturbanceCount * 0.12) + ($unrecovered * 0.18));
    $controllerRaw = min(1.0, ($variability / 0.45) * 0.8 + 0.2);
    $sum = max(0.01, $externalRaw + $controllerRaw);

    $externalPct = round(($externalRaw / $sum) * 100, 1);
    $controllerPct = round(100 - $externalPct, 1);
    return ['controller_pct' => $controllerPct, 'external_pct' => $externalPct];
}

function calc_before_after_impact($conn, $tankId, $series) {
    $stmt = $conn->prepare("SELECT changed_at FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $tankId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || empty($row['changed_at'])) {
        return null;
    }

    $changeTs = strtotime($row['changed_at']);
    if (!$changeTs) {
        return null;
    }

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

    $beforeStats = calc_stats_from_series($before);
    $afterStats = calc_stats_from_series($after);
    if (!$beforeStats || !$afterStats) {
        return null;
    }

    return [
        'delta_mean_abs' => $afterStats['mean_abs'] - $beforeStats['mean_abs'],
    ];
}

function build_action_plan($stats, $recovery, $confidence, $compositeScore) {
    $actions = [];
    if (($stats['mean_abs'] ?? 0) >= 0.25) {
        $actions[] = 'Reduzir erro medio absoluto com ajuste gradual de Kp.';
    }
    if (($stats['stdev'] ?? 0) > 0.30) {
        $actions[] = 'Conter oscilacoes: reduzir Kp ligeiro e aumentar Td moderado.';
    }
    if (isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null && $recovery['mean_recovery_sec'] > 1800) {
        $actions[] = 'Melhorar recuperacao apos perturbacao ajustando Ti com cautela.';
    }
    if ($confidence['level'] === 'BAIXA') {
        $actions[] = 'Aumentar confianca da analise antes de novo ajuste.';
    }
    if ($compositeScore >= 85 && count($actions) === 0) {
        $actions[] = 'Manter estrategia atual e monitorizar tendencia.';
    }
    return $actions;
}

function mark_spontaneous_zero_runs($series) {
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

function calc_disturbance_recovery($series) {
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

function pid_recommendations($stats, $currentPid, $responseDelaySec, $recovery) {
    $p = isset($currentPid['p']) ? float_or_null($currentPid['p']) : null;
    $i = isset($currentPid['i']) ? float_or_null($currentPid['i']) : null;
    $d = isset($currentPid['d']) ? float_or_null($currentPid['d']) : null;

    $suggestedP = $p;
    $suggestedI = $i;
    $suggestedD = $d;

    $errThreshold = 0.25;
    $tightThreshold = 0.20;

    $hasOscillations = $stats['stdev'] > max($errThreshold, 0.2);
    $hasHighError = $stats['mean_abs'] > $errThreshold;
    $hasBias = abs($stats['mean']) > ($tightThreshold * 0.75) && $stats['sign_change_rate'] < 0.35;
    $hasRapidReversals = $stats['sign_change_rate'] > 0.45 && isset($stats['derivative_mean']) && $stats['derivative_mean'] > 0.0002;
    $slowRecovery = isset($recovery['mean_recovery_sec']) && $recovery['mean_recovery_sec'] !== null && $recovery['mean_recovery_sec'] > 1800;
    $slowStabilization = isset($recovery['mean_stabilization_sec']) && $recovery['mean_stabilization_sec'] !== null && $recovery['mean_stabilization_sec'] > 3000;
    $hasUnrecovered = isset($recovery['unrecovered_count']) && $recovery['unrecovered_count'] > 0;
    $disturbanceHeavy = isset($recovery['disturbance_count']) && $recovery['disturbance_count'] >= 2;

    $tiSeedSec = ($responseDelaySec !== null && $responseDelaySec > 0)
        ? max(300.0, min(7200.0, (float)$responseDelaySec))
        : 1200.0;

    if ($hasOscillations) {
        if ($p !== null) {
            $suggestedP = $p * 0.90;
        }
        if ($d !== null) {
            $suggestedD = $d * 1.20;
        }
    } elseif ($hasHighError && $p !== null) {
        $suggestedP = $p * 1.15;
    }

    if ($hasBias) {
        if ($i === null || $i <= 0) {
            $suggestedI = round($hasOscillations ? ($tiSeedSec * 1.5) : $tiSeedSec, 2);
        } elseif ($i >= 500) {
            $suggestedI = $i * 0.75;
        } else {
            $suggestedI = $i * 0.88;
        }
    }

    if (($i === null || $i <= 0) && $suggestedI === $i) {
        $needsIntegralKickstart = $hasHighError && !$hasRapidReversals;
        if ($needsIntegralKickstart) {
            $suggestedI = round($hasOscillations ? ($tiSeedSec * 1.6) : ($tiSeedSec * 1.2), 2);
        }
    }

    if ($hasRapidReversals) {
        if ($d !== null) {
            $suggestedD = $d * 1.25;
        }
        if (!$hasOscillations && $p !== null) {
            $suggestedP = $p * 0.95;
        }
    }

    if (($slowRecovery || $disturbanceHeavy) && !$hasOscillations) {
        if ($p !== null) {
            $suggestedP = $p * 1.08;
        }
        if ($suggestedI !== null && $suggestedI > 0) {
            $suggestedI = $suggestedI * 0.90;
        }
    }

    if ($slowStabilization && $d !== null) {
        $suggestedD = $d * 1.10;
    }

    // Classificação em 3 níveis para evitar relatório binário
    $veryHighError = $stats['mean_abs'] > 0.45;
    $veryHighOsc = $stats['stdev'] > 0.55;
    $moderateIssue = $stats['mean_abs'] > 0.20 || $stats['stdev'] > 0.25 || $hasBias;

    $severity = 'OK';
    if ($veryHighError || $veryHighOsc || ($hasOscillations && $hasHighError)) {
        $severity = 'CRITICO';
    } elseif ($moderateIssue) {
        $severity = 'VIGIAR';
    }

    if ($hasUnrecovered && $severity !== 'CRITICO') {
        $severity = 'VIGIAR';
    }
    if ($hasUnrecovered && isset($recovery['unrecovered_count']) && $recovery['unrecovered_count'] >= 2) {
        $severity = 'CRITICO';
    }

    return [
        'severity' => $severity,
        'values' => [
            'p' => $suggestedP,
            'i' => $suggestedI,
            'd' => $suggestedD,
        ]
    ];
}

function fetch_tank_pid($conn, $tank) {
    $pid = [
        'p' => isset($tank['pid_p']) ? float_or_null($tank['pid_p']) : null,
        'i' => isset($tank['pid_i']) ? float_or_null($tank['pid_i']) : null,
        'd' => isset($tank['pid_d']) ? float_or_null($tank['pid_d']) : null,
    ];

    if (($pid['i'] === null || $pid['i'] <= 0) || ($pid['d'] === null || $pid['d'] < 0) || ($pid['p'] === null || $pid['p'] <= 0)) {
        $stmt = $conn->prepare("SELECT p, i, d FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 1");
        if ($stmt) {
            $tankId = (int)$tank['id'];
            $stmt->bind_param('i', $tankId);
            if ($stmt->execute()) {
                $last = $stmt->get_result()->fetch_assoc();
                if ($last) {
                    if (($pid['p'] === null || $pid['p'] <= 0) && isset($last['p']) && float_or_null($last['p']) > 0) {
                        $pid['p'] = float_or_null($last['p']);
                    }
                    if (($pid['i'] === null || $pid['i'] <= 0) && isset($last['i']) && float_or_null($last['i']) > 0) {
                        $pid['i'] = float_or_null($last['i']);
                    }
                    if (($pid['d'] === null || $pid['d'] < 0) && isset($last['d']) && float_or_null($last['d']) >= 0) {
                        $pid['d'] = float_or_null($last['d']);
                    }
                }
            }
            $stmt->close();
        }
    }

    return $pid;
}

function analyze_tank($conn, $tankId, $startDate) {
    $stmt = $conn->prepare("SELECT log_datetime, chlorine_value, chlorine_setpoint, cl_controller_state FROM controller_history WHERE tank_id = ? AND log_datetime >= ? ORDER BY log_datetime ASC");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $tankId, $startDate);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$history) {
        return null;
    }

    $series = [];
    foreach ($history as $row) {
        $value = float_or_null($row['chlorine_value']);
        $setpoint = float_or_null($row['chlorine_setpoint']);
        if ($value === null || $setpoint === null) {
            continue;
        }

        $series[] = [
            'time' => $row['log_datetime'],
            'ts' => strtotime($row['log_datetime']),
            'value' => $value,
            'setpoint' => $setpoint,
            'controller' => isset($row['cl_controller_state']) ? float_or_null($row['cl_controller_state']) : null,
            'zero_glitch' => false,
        ];
    }

    if (!$series) {
        return null;
    }

    $markResult = mark_spontaneous_zero_runs($series);
    $series = $markResult['series'];
    $zeroGlitches = $markResult['glitch_count'];

    $errors = [];
    $times = [];
    $cleanSeries = [];
    foreach ($series as $point) {
        if ($point['zero_glitch']) {
            continue;
        }
        $errors[] = $point['value'] - $point['setpoint'];
        $times[] = $point['time'];
        $cleanSeries[] = $point;
    }

    $stats = calc_stats($errors, $times);
    if (!$stats) {
        return null;
    }

    $setpointMean = null;
    $setpoints = array_column($cleanSeries, 'setpoint');
    $setpoints = array_filter($setpoints, function($v) { return $v !== null && is_numeric($v); });
    if (count($setpoints) > 0) {
        $setpointMean = array_sum($setpoints) / count($setpoints);
    }
    if ($setpointMean !== null && $setpointMean != 0) {
        $stats['mean_pct'] = ($stats['mean'] / $setpointMean) * 100;
        $stats['mean_abs_pct'] = ($stats['mean_abs'] / $setpointMean) * 100;
    } else {
        $stats['mean_pct'] = null;
        $stats['mean_abs_pct'] = null;
    }

    $delaySamples = [];
    $doseThreshold = 0.05;
    $effectThreshold = 0.05;

    for ($i = 1; $i < count($cleanSeries); $i++) {
        $prevDose = $cleanSeries[$i - 1]['controller'];
        $currDose = $cleanSeries[$i]['controller'];

        if ($prevDose === null || $currDose === null || abs($currDose - $prevDose) <= $doseThreshold) {
            continue;
        }

        $doseTime = $cleanSeries[$i]['ts'];
        $doseValue = $cleanSeries[$i]['value'];

        for ($j = $i + 1; $j < count($cleanSeries); $j++) {
            $effectValue = $cleanSeries[$j]['value'];
            if ($effectValue === null || $doseValue === null) {
                continue;
            }

            if (abs($effectValue - $doseValue) > $effectThreshold) {
                $effectTime = $cleanSeries[$j]['ts'];
                $delay = $effectTime - $doseTime;
                if ($delay > 0 && $delay < 21600) {
                    $delaySamples[] = $delay;
                }
                break;
            }
        }
    }

    $meanDelaySec = count($delaySamples) > 0 ? array_sum($delaySamples) / count($delaySamples) : null;
    $recovery = calc_disturbance_recovery($cleanSeries);
    $timeWindows = calc_time_window_stats($cleanSeries);
    $confidence = calc_confidence($stats, count($history), $zeroGlitches);
    $compositeScore = calc_composite_score($stats, $recovery, $zeroGlitches, $stats['samples']);
    $contribution = calc_contribution_split($stats, $recovery);
    $beforeAfter = calc_before_after_impact($conn, $tankId, $cleanSeries);
    $actionPlan = build_action_plan($stats, $recovery, $confidence, $compositeScore);

    $worstWindow = null;
    $worstMae = -1;
    foreach ($timeWindows as $windowName => $windowData) {
        if (!isset($windowData['stats']) || !$windowData['stats']) {
            continue;
        }
        $windowMae = $windowData['stats']['mean_abs'];
        if ($windowMae > $worstMae) {
            $worstMae = $windowMae;
            $worstWindow = $windowName;
        }
    }

    return [
        'stats' => $stats,
        'setpoint_mean' => $setpointMean,
        'mean_delay_sec' => $meanDelaySec,
        'zero_glitches' => $zeroGlitches,
        'recovery' => $recovery,
        'confidence' => $confidence,
        'composite_score' => $compositeScore,
        'contribution' => $contribution,
        'time_windows' => $timeWindows,
        'worst_window' => $worstWindow,
        'before_after_impact' => $beforeAfter,
        'action_plan' => $actionPlan,
        'row_count' => count($history),
    ];
}

$hasPidCols = false;
$cols = [];
if ($res = $conn->query("SHOW COLUMNS FROM `tanks`")) {
    while ($row = $res->fetch_assoc()) {
        $cols[$row['Field']] = true;
    }
    $res->free();
}
$hasPidCols = isset($cols['pid_p']) && isset($cols['pid_i']) && isset($cols['pid_d']);

$tankSql = $hasPidCols
    ? "SELECT id, name, pid_p, pid_i, pid_d FROM tanks WHERE type = 'piscina' AND has_controller = 1 ORDER BY name ASC"
    : "SELECT id, name FROM tanks WHERE type = 'piscina' AND has_controller = 1 ORDER BY name ASC";

$tanksResult = $conn->query($tankSql);
$tanks = $tanksResult ? $tanksResult->fetch_all(MYSQLI_ASSOC) : [];

$rows = [];

foreach ($tanks as $tank) {
    $analysis = analyze_tank($conn, (int)$tank['id'], $startDate);
    if (!$analysis) {
        $rows[] = [
            'tank_name' => $tank['name'],
            'samples' => 0,
            'mean_abs' => null,
            'stdev' => null,
            'mean_delay_min' => null,
            'current' => fetch_tank_pid($conn, $tank),
            'suggested' => ['p' => null, 'i' => null, 'd' => null],
            'severity' => 'SEM DADOS'
        ];
        continue;
    }

    $currentPid = fetch_tank_pid($conn, $tank);
    $recommendation = pid_recommendations($analysis['stats'], $currentPid, $analysis['mean_delay_sec'], $analysis['recovery']);

    $rows[] = [
        'tank_name' => $tank['name'],
        'samples' => $analysis['stats']['samples'],
        'mean_abs' => $analysis['stats']['mean_abs'],
        'mean_abs_pct' => isset($analysis['stats']['mean_abs_pct']) ? $analysis['stats']['mean_abs_pct'] : null,
        'stdev' => $analysis['stats']['stdev'],
        'confidence_level' => isset($analysis['confidence']['level']) ? $analysis['confidence']['level'] : 'BAIXA',
        'confidence_score' => isset($analysis['confidence']['score']) ? $analysis['confidence']['score'] : 0,
        'composite_score' => isset($analysis['composite_score']) ? $analysis['composite_score'] : null,
        'controller_pct' => isset($analysis['contribution']['controller_pct']) ? $analysis['contribution']['controller_pct'] : null,
        'external_pct' => isset($analysis['contribution']['external_pct']) ? $analysis['contribution']['external_pct'] : null,
        'worst_window' => isset($analysis['worst_window']) ? $analysis['worst_window'] : '-',
        'delta_mae_after_change' => isset($analysis['before_after_impact']['delta_mean_abs']) ? $analysis['before_after_impact']['delta_mean_abs'] : null,
        'top_action' => (isset($analysis['action_plan']) && count($analysis['action_plan']) > 0) ? $analysis['action_plan'][0] : 'Sem acao prioritaria',
        'mean_delay_min' => $analysis['mean_delay_sec'] !== null ? round($analysis['mean_delay_sec'] / 60, 1) : null,
        'mean_recovery_min' => isset($analysis['recovery']['mean_recovery_sec']) && $analysis['recovery']['mean_recovery_sec'] !== null
            ? round($analysis['recovery']['mean_recovery_sec'] / 60, 1)
            : null,
        'mean_stabilization_min' => isset($analysis['recovery']['mean_stabilization_sec']) && $analysis['recovery']['mean_stabilization_sec'] !== null
            ? round($analysis['recovery']['mean_stabilization_sec'] / 60, 1)
            : null,
        'disturbance_count' => isset($analysis['recovery']['disturbance_count']) ? (int)$analysis['recovery']['disturbance_count'] : 0,
        'unrecovered_count' => isset($analysis['recovery']['unrecovered_count']) ? (int)$analysis['recovery']['unrecovered_count'] : 0,
        'zero_glitches' => isset($analysis['zero_glitches']) ? (int)$analysis['zero_glitches'] : 0,
        'current' => $currentPid,
        'suggested' => $recommendation['values'],
        'severity' => $recommendation['severity']
    ];
}

class PIDWeeklyPDF extends FPDF {
    public $generatedBy;
    public $generatedAt;
    public $days;

    function Header() {
        $this->Image('../images/Logo Registado Red.png', 10, 8, 24);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, utf8_decode('Plano de Ajuste PID - Piscinas'), 0, 1, 'R');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, utf8_decode('Período de análise: últimos ' . $this->days . ' dias'), 0, 1, 'R');
        $this->Cell(0, 4, utf8_decode('Gerado em: ' . $this->generatedAt), 0, 1, 'R');
        $this->Ln(1);
        $this->SetFont('Arial', 'B', 8);

        $this->SetFillColor(230, 230, 230);
        $this->Cell(36, 6, utf8_decode('Controlador'), 1, 0, 'C', true);
        $this->Cell(12, 6, utf8_decode('Amost'), 1, 0, 'C', true);
        $this->Cell(18, 6, utf8_decode('Erro abs'), 1, 0, 'C', true);
        $this->Cell(16, 6, utf8_decode('DP'), 1, 0, 'C', true);
        $this->Cell(16, 6, utf8_decode('Delay'), 1, 0, 'C', true);
        $this->Cell(21, 6, utf8_decode('Kp A->S'), 1, 0, 'C', true);
        $this->Cell(24, 6, utf8_decode('Ti(s) A->S'), 1, 0, 'C', true);
        $this->Cell(22, 6, utf8_decode('Td A->S'), 1, 0, 'C', true);
        $this->Cell(25, 6, utf8_decode('Estado'), 1, 1, 'C', true);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(70, 10, 'WorkLog CMMS', 0, 0, 'L');
        $this->Cell(90, 10, utf8_decode('Impresso por: ' . $this->generatedBy), 0, 0, 'C');
        $this->Cell(0, 10, utf8_decode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

function fmt_value($value, $decimals = 2) {
    if ($value === null || !is_numeric($value)) {
        return '-';
    }
    return number_format((float)$value, $decimals, '.', '');
}

function fmt_pid_pair($current, $suggested, $decimals = 2) {
    $c = ($current === null || !is_numeric($current)) ? '-' : number_format((float)$current, $decimals, '.', '');
    $s = ($suggested === null || !is_numeric($suggested)) ? '-' : number_format((float)$suggested, $decimals, '.', '');
    return $c . '->' . $s;
}

$pdf = new PIDWeeklyPDF('L', 'mm', 'A5');
$pdf->AliasNbPages();
$pdf->generatedBy = $generatedBy;
$pdf->generatedAt = $generatedAt;
$pdf->days = $days;
$pdf->AddPage();
$pdf->SetFont('Arial', '', 7.5);

foreach ($rows as $row) {
    $yStart = $pdf->GetY();
    if ($yStart > 120) {
        $pdf->AddPage();
    }

    $severity = $row['severity'];
    if ($severity === 'CRITICO') {
        $pdf->SetTextColor(176, 0, 32);
    } elseif ($severity === 'VIGIAR') {
        $pdf->SetTextColor(163, 92, 0);
    } elseif ($severity === 'SEM DADOS') {
        $pdf->SetTextColor(90, 90, 90);
    } else {
        $pdf->SetTextColor(0, 110, 64);
    }

    $pdf->Cell(36, 5, utf8_decode(substr($row['tank_name'], 0, 23)), 1, 0, 'L');
    $pdf->Cell(12, 5, (string)$row['samples'], 1, 0, 'C');
    $pdf->Cell(18, 5, fmt_value($row['mean_abs'], 3), 1, 0, 'C');
    $pdf->Cell(16, 5, fmt_value($row['stdev'], 3), 1, 0, 'C');
    $pdf->Cell(16, 5, $row['mean_delay_min'] !== null ? number_format($row['mean_delay_min'], 1, '.', '') . 'm' : '-', 1, 0, 'C');
    $pdf->Cell(21, 5, fmt_pid_pair($row['current']['p'], $row['suggested']['p'], 2), 1, 0, 'C');
    $pdf->Cell(24, 5, fmt_pid_pair($row['current']['i'], $row['suggested']['i'], 0), 1, 0, 'C');
    $pdf->Cell(22, 5, fmt_pid_pair($row['current']['d'], $row['suggested']['d'], 2), 1, 0, 'C');
    $pdf->Cell(25, 5, utf8_decode($severity), 1, 1, 'C');

    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Ln(3);
$pdf->SetFont('Arial', '', 7.5);
$pdf->MultiCell(0, 5, utf8_decode('Nota: Ti está na unidade do controlador (segundos). Ajustes devem ser aplicados de forma gradual e validados durante a semana.'), 0, 'L');

$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, utf8_decode('Métricas Avançadas (7 dias)'), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(32, 6, utf8_decode('Controlador'), 1, 0, 'C', true);
$pdf->Cell(14, 6, utf8_decode('MAE%'), 1, 0, 'C', true);
$pdf->Cell(24, 6, utf8_decode('Confianca'), 1, 0, 'C', true);
$pdf->Cell(14, 6, utf8_decode('Score'), 1, 0, 'C', true);
$pdf->Cell(18, 6, utf8_decode('Ctrl/Ext'), 1, 0, 'C', true);
$pdf->Cell(20, 6, utf8_decode('Janela'), 1, 0, 'C', true);
$pdf->Cell(20, 6, utf8_decode('Delta MAE'), 1, 0, 'C', true);
$pdf->Cell(48, 6, utf8_decode('Acao Prioritaria'), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 6.8);

foreach ($rows as $row) {
    if ($pdf->GetY() > 128) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, utf8_decode('Métricas Avançadas (continuação)'), 0, 1, 'L');
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(32, 6, utf8_decode('Controlador'), 1, 0, 'C', true);
        $pdf->Cell(14, 6, utf8_decode('MAE%'), 1, 0, 'C', true);
        $pdf->Cell(24, 6, utf8_decode('Confianca'), 1, 0, 'C', true);
        $pdf->Cell(14, 6, utf8_decode('Score'), 1, 0, 'C', true);
        $pdf->Cell(18, 6, utf8_decode('Ctrl/Ext'), 1, 0, 'C', true);
        $pdf->Cell(20, 6, utf8_decode('Janela'), 1, 0, 'C', true);
        $pdf->Cell(20, 6, utf8_decode('Delta MAE'), 1, 0, 'C', true);
        $pdf->Cell(48, 6, utf8_decode('Acao Prioritaria'), 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 6.8);
    }

    $confidenceText = (isset($row['confidence_level']) ? $row['confidence_level'] : 'BAIXA')
        . ' (' . (isset($row['confidence_score']) ? number_format((float)$row['confidence_score'], 1, '.', '') : '0.0') . '%)';
    $contributionText = '-';
    if (isset($row['controller_pct']) && isset($row['external_pct']) && $row['controller_pct'] !== null && $row['external_pct'] !== null) {
        $contributionText = number_format((float)$row['controller_pct'], 0, '.', '') . '/' . number_format((float)$row['external_pct'], 0, '.', '');
    }
    $actionText = isset($row['top_action']) ? $row['top_action'] : 'Sem acao prioritaria';

    $pdf->Cell(32, 5, utf8_decode(substr($row['tank_name'], 0, 20)), 1, 0, 'L');
    $pdf->Cell(14, 5, ($row['mean_abs_pct'] !== null ? number_format((float)$row['mean_abs_pct'], 1, '.', '') . '%' : '-'), 1, 0, 'C');
    $pdf->Cell(24, 5, utf8_decode(substr($confidenceText, 0, 18)), 1, 0, 'C');
    $pdf->Cell(14, 5, ($row['composite_score'] !== null ? number_format((float)$row['composite_score'], 1, '.', '') : '-'), 1, 0, 'C');
    $pdf->Cell(18, 5, utf8_decode($contributionText), 1, 0, 'C');
    $pdf->Cell(20, 5, utf8_decode(isset($row['worst_window']) ? $row['worst_window'] : '-'), 1, 0, 'C');
    $pdf->Cell(20, 5, ($row['delta_mae_after_change'] !== null ? number_format((float)$row['delta_mae_after_change'], 3, '.', '') : '-'), 1, 0, 'C');
    $pdf->Cell(48, 5, utf8_decode(substr($actionText, 0, 54)), 1, 1, 'L');
}

$totalDisturbances = 0;
$totalUnrecovered = 0;
$rowsWithRecovery = 0;
$sumRecoveryMin = 0.0;
$sumStabilizationMin = 0.0;
$rowsWithStabilization = 0;
$totalZeroGlitches = 0;

foreach ($rows as $row) {
    $totalDisturbances += isset($row['disturbance_count']) ? (int)$row['disturbance_count'] : 0;
    $totalUnrecovered += isset($row['unrecovered_count']) ? (int)$row['unrecovered_count'] : 0;
    $totalZeroGlitches += isset($row['zero_glitches']) ? (int)$row['zero_glitches'] : 0;

    if (isset($row['mean_recovery_min']) && $row['mean_recovery_min'] !== null) {
        $sumRecoveryMin += (float)$row['mean_recovery_min'];
        $rowsWithRecovery++;
    }
    if (isset($row['mean_stabilization_min']) && $row['mean_stabilization_min'] !== null) {
        $sumStabilizationMin += (float)$row['mean_stabilization_min'];
        $rowsWithStabilization++;
    }
}

$meanRecoveryGlobal = $rowsWithRecovery > 0 ? ($sumRecoveryMin / $rowsWithRecovery) : null;
$meanStabilizationGlobal = $rowsWithStabilization > 0 ? ($sumStabilizationMin / $rowsWithStabilization) : null;

$lineRecovery = 'Recuperação após quedas externas: sem eventos identificados.';
if ($totalDisturbances > 0) {
    $lineRecovery = 'Recuperação média após quedas externas: '
        . ($meanRecoveryGlobal !== null ? number_format($meanRecoveryGlobal, 1, '.', '') . ' min' : 'n/d')
        . '; estabilização média: '
        . ($meanStabilizationGlobal !== null ? number_format($meanStabilizationGlobal, 1, '.', '') . ' min' : 'n/d')
        . '; eventos sem recuperação completa: ' . $totalUnrecovered . '.';
}
$pdf->MultiCell(0, 5, utf8_decode($lineRecovery), 0, 'L');

if ($totalZeroGlitches > 0) {
    $pdf->MultiCell(0, 5, utf8_decode('Leitura robusta: ' . $totalZeroGlitches . ' zeros espontâneos isolados foram desconsiderados na estatística de erro.'), 0, 'L');
}

$pdf->Output('I', 'plano_pid_semanal_' . date('Ymd_His') . '.pdf');
?>