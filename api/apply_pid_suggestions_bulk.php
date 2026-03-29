<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

function json_response($payload, $code = 200) {
    http_response_code($code);
    if (ob_get_length()) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    json_response(['error' => 'Acesso não autorizado'], 401);
}

if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'viewer') {
    json_response(['error' => 'Sem permissões para aplicar sugestões'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$days = isset($input['days']) && is_numeric($input['days']) && (int)$input['days'] > 0
    ? (int)$input['days']
    : 7;

$startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
$userId = (int)$_SESSION['user_id'];
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;

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
        'stdev' => $stdev,
        'sign_change_rate' => $signChangeRate,
        'derivative_mean' => $derivMean,
    ];
}

function pid_recommendations($stats, $currentPid, $responseDelaySec) {
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

    return [
        'p' => $suggestedP,
        'i' => $suggestedI,
        'd' => $suggestedD,
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

    $errors = [];
    $times = [];

    foreach ($history as $row) {
        $value = float_or_null($row['chlorine_value']);
        $setpoint = float_or_null($row['chlorine_setpoint']);
        if ($value !== null && $setpoint !== null) {
            $errors[] = $value - $setpoint;
        }
        $times[] = $row['log_datetime'];
    }

    $stats = calc_stats($errors, $times);
    if (!$stats) {
        return null;
    }

    $delaySamples = [];
    $doseThreshold = 0.05;
    $effectThreshold = 0.05;

    for ($i = 1; $i < count($history); $i++) {
        $prevDose = isset($history[$i - 1]['cl_controller_state']) ? float_or_null($history[$i - 1]['cl_controller_state']) : null;
        $currDose = isset($history[$i]['cl_controller_state']) ? float_or_null($history[$i]['cl_controller_state']) : null;

        if ($prevDose === null || $currDose === null || abs($currDose - $prevDose) <= $doseThreshold) {
            continue;
        }

        $doseTime = strtotime($history[$i]['log_datetime']);
        $doseValue = float_or_null($history[$i]['chlorine_value']);

        for ($j = $i + 1; $j < count($history); $j++) {
            $effectValue = float_or_null($history[$j]['chlorine_value']);
            if ($effectValue === null || $doseValue === null) {
                continue;
            }

            if (abs($effectValue - $doseValue) > $effectThreshold) {
                $effectTime = strtotime($history[$j]['log_datetime']);
                $delay = $effectTime - $doseTime;
                if ($delay > 0 && $delay < 21600) {
                    $delaySamples[] = $delay;
                }
                break;
            }
        }
    }

    return [
        'stats' => $stats,
        'mean_delay_sec' => count($delaySamples) > 0 ? array_sum($delaySamples) / count($delaySamples) : null,
    ];
}

function values_equal($a, $b, $eps = 0.000001) {
    if ($a === null || $b === null) {
        return false;
    }
    return abs((float)$a - (float)$b) < $eps;
}

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

$summary = [
    'success' => true,
    'days' => $days,
    'total_controllers' => count($tanks),
    'applied' => 0,
    'skipped_no_data' => 0,
    'skipped_blocked' => 0,
    'skipped_unchanged' => 0,
    'errors' => 0,
    'details' => []
];

foreach ($tanks as $tank) {
    $tankId = (int)$tank['id'];
    $tankName = $tank['name'];

    $analysis = analyze_tank($conn, $tankId, $startDate);
    if (!$analysis) {
        $summary['skipped_no_data']++;
        $summary['details'][] = [
            'tank_id' => $tankId,
            'tank_name' => $tankName,
            'status' => 'SEM_DADOS'
        ];
        continue;
    }

    $current = fetch_tank_pid($conn, $tank);
    $suggested = pid_recommendations($analysis['stats'], $current, $analysis['mean_delay_sec']);

    if ($suggested['p'] === null || $suggested['i'] === null || $suggested['d'] === null) {
        $summary['errors']++;
        $summary['details'][] = [
            'tank_id' => $tankId,
            'tank_name' => $tankName,
            'status' => 'ERRO_PID_INCOMPLETO'
        ];
        continue;
    }

    $unchanged = values_equal($current['p'], $suggested['p'])
        && values_equal($current['i'], $suggested['i'])
        && values_equal($current['d'], $suggested['d']);

    if ($unchanged) {
        $summary['skipped_unchanged']++;
        $summary['details'][] = [
            'tank_id' => $tankId,
            'tank_name' => $tankName,
            'status' => 'SEM_ALTERACAO'
        ];
        continue;
    }

    $stmtBlock = $conn->prepare("SELECT changed_at FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 1");
    if ($stmtBlock) {
        $stmtBlock->bind_param('i', $tankId);
        if ($stmtBlock->execute()) {
            $rowBlock = $stmtBlock->get_result()->fetch_assoc();
            if ($rowBlock) {
                $hoursSince = (time() - strtotime($rowBlock['changed_at'])) / 3600;
                if ($hoursSince < 72) {
                    $summary['skipped_blocked']++;
                    $summary['details'][] = [
                        'tank_id' => $tankId,
                        'tank_name' => $tankName,
                        'status' => 'BLOQUEADO_72H',
                        'hours_since_last_change' => round($hoursSince, 1)
                    ];
                    $stmtBlock->close();
                    continue;
                }
            }
        }
        $stmtBlock->close();
    }

    $conn->begin_transaction();
    try {
        $reason = 'Aceitação em lote de sugestões PID (' . $days . ' dias)';

        $stmtInsert = $conn->prepare("INSERT INTO tank_pid_changes (tank_id, p, i, d, reason, changed_by, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmtInsert) {
            throw new Exception('Falha ao preparar insert: ' . $conn->error);
        }

        $pVal = (float)$suggested['p'];
        $iVal = (float)$suggested['i'];
        $dVal = (float)$suggested['d'];
        $stmtInsert->bind_param('idddsis', $tankId, $pVal, $iVal, $dVal, $reason, $userId, $ip);
        if (!$stmtInsert->execute()) {
            throw new Exception('Falha ao inserir histórico: ' . $stmtInsert->error);
        }
        $stmtInsert->close();

        if ($hasPidCols) {
            $stmtUpdate = $conn->prepare("UPDATE tanks SET pid_p = ?, pid_i = ?, pid_d = ? WHERE id = ?");
            if (!$stmtUpdate) {
                throw new Exception('Falha ao preparar update: ' . $conn->error);
            }
            $stmtUpdate->bind_param('dddi', $pVal, $iVal, $dVal, $tankId);
            if (!$stmtUpdate->execute()) {
                throw new Exception('Falha ao atualizar tanque: ' . $stmtUpdate->error);
            }
            $stmtUpdate->close();
        }

        $conn->commit();

        $summary['applied']++;
        $summary['details'][] = [
            'tank_id' => $tankId,
            'tank_name' => $tankName,
            'status' => 'APLICADO',
            'new_pid' => [
                'p' => $pVal,
                'i' => $iVal,
                'd' => $dVal
            ]
        ];
    } catch (Exception $e) {
        $conn->rollback();
        $summary['errors']++;
        $summary['details'][] = [
            'tank_id' => $tankId,
            'tank_name' => $tankName,
            'status' => 'ERRO',
            'message' => $e->getMessage()
        ];
    }
}

json_response($summary);
