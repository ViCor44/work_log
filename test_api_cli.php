<?php
// Test via CLI or direct execution
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['user_id'] = 1;

// Test para tank_id = 5
echo "Testando API com tank_id = 5...\n\n";

$_GET['tank_id'] = 5;
$_GET['days'] = 3;

// Simula request
require_once 'core.php';

// Verifica conexão
if (!$conn) {
    die("❌ Sem conexão estabelecida à BD\n");
}

// Verifica se tabelas existem
echo "Verificando tabelas necessárias:\n";

$tables = ['tanks', 'controller_history', 'tank_pid_changes'];
foreach ($tables as $table) {
    $result = @$conn->query("SELECT 1 FROM $table LIMIT 1");
    if ($result !== false) {
        echo "  ✅ $table\n";
    } else {
        echo "  ⚠️  $table (Table might not exist or no data)\n";
    }
}

echo "\nBuscando dados de tank_id = 5:\n";

// Check tank
$stmt = $conn->prepare("SELECT id, name FROM tanks WHERE id = 5");
if (!$stmt) {
    die("❌ Erro ao preparar: " . $conn->error . "\n");
}
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("❌ Tank 5 não existe\n");
}
$tank = $result->fetch_assoc();
echo "✅ Tank encontrado: " . $tank['name'] . "\n";
$stmt->close();

// Check histórico
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM controller_history WHERE tank_id = 5 AND log_datetime >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
if (!$stmt) {
    echo "⚠️  Erro ao contar histórico (3 dias): " . $conn->error . "\n";
    $hist_count = 0;
} else {
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $hist_count = $row['cnt'];
    echo "Registos nos últimos 3 dias: " . $hist_count . "\n";
    $stmt->close();
}

// Se não há dados recentes, tenta últimos 100
if ($hist_count === 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM controller_history WHERE tank_id = 5");
    if ($stmt) {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo "Total de registos (todos): " . $row['cnt'] . "\n";
        $stmt->close();
    }
}

echo "\n✅ Tudo parece OK!\n";
echo "\nTente acessar: http://localhost/work_log/pools/advanced_settings.php?id=5\n";

?>
