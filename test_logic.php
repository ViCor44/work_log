<?php
// Final diagnostic - copiar a lógica exata da API
chdir(__DIR__);

error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
$_SESSION['user_id'] = 1;

// Load core
require_once 'core.php';

// Simulate the exact API call
$tank_id = 5;
$days = 3;
$start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "=== Direct Logic Test ===\n\n";

// 1. Fetch tank  
$stmt_tank = $conn->prepare("SELECT id, name, pid_p, pid_i, pid_d FROM tanks WHERE id = ? LIMIT 1");
if (!$stmt_tank) {
    echo "❌ Tank query failed: " . $conn->error . "\n";
    exit;
}
$stmt_tank->bind_param('i', $tank_id);
$stmt_tank->execute();
$tank = $stmt_tank->get_result()->fetch_assoc();
$stmt_tank->close();

if (!$tank) {
    echo "❌ Tank not found\n";
    exit;
}
echo "✅ Tank: " . $tank['name'] . "\n";

// 2. Fetch history (recent)
$stmt = $conn->prepare("SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint FROM controller_history WHERE tank_id = ? AND log_datetime >= ? ORDER BY log_datetime ASC");
if (!$stmt) {
    echo "❌ History query failed: " . $conn->error . "\n";
    exit;
}
$stmt->bind_param('is', $tank_id, $start_date);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo "✅ Recent history: " . count($history) . " records\n";

// 3. If no recent data, fetch last 100
if (!$history) {
    echo "⚠️  No recent data, fetching last 100...\n";
    $stmt = $conn->prepare("SELECT log_datetime, ph_value, ph_setpoint, chlorine_value, chlorine_setpoint FROM controller_history WHERE tank_id = ? ORDER BY log_datetime DESC LIMIT 100");
    if (!$stmt) {
        echo "❌ Fallback query failed: " . $conn->error . "\n";
        exit;
    }
    $stmt->bind_param('i', $tank_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo "✅ Fallback history: " . count($history) . " records\n";
}

if (!$history) {
    echo "❌ No history found at all\n";
    exit;
}

// 4. Calculate stats
function floatOrNull($value) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

$clErrors = [];
$times = [];

foreach ($history as $row) {
    $cl = floatOrNull($row['chlorine_value']);
    $cl_sp = floatOrNull($row['chlorine_setpoint']);
    if ($cl !== null && $cl_sp !== null) {
        $clErrors[] = $cl - $cl_sp;
        $times[] = $row['log_datetime'];
    }
}

echo "✅ Chlorine errors: " . count($clErrors) . "\n";

if (count($clErrors) === 0) {
    echo "⚠️  No chlorine data to analyze\n";
} else {
    $mean = array_sum($clErrors) / count($clErrors);
    echo "✅ Mean error: " . number_format($mean, 4) . "\n";
}

// 5. Simulate response
$response = [
    'tank_id' => $tank_id,
    'tank_name' => $tank['name'],
    'days' => count($history) ? $days : 'últimos disponíveis',
    'row_count' => count($history),
   'chlorine' => [
        'stats' => ['samples' => count($clErrors)],
        'suggestions' => ['Test suggestion'],
        'suggested_values' => ['p' => 25.5, 'i' => 100, 'd' => 50]
    ],
    'current_pid' => ['p' => $tank['pid_p'], 'i' => $tank['pid_i'], 'd' => $tank['pid_d']],
    'pid_change_history' => []
];

$json_response = json_encode($response);
echo "\n✅ JSON Response: " . strlen($json_response) . " bytes\n";
echo "Valid JSON: " . (json_last_error() === JSON_ERROR_NONE ? "YES" : "NO") . "\n";

?>
