<?php
// Script de debug detalhado
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['user_id'] = 1;

echo "=== DEBUG API - PID Suggestions ===\n\n";

// Simula a chamada GET
$_GET['tank_id'] = 5;
$_GET['days'] = 3;

// Captura todo output
ob_start();

// Carregue o core para inicializar $conn
require_once 'core.php';

echo "Database connection: ";
if ($conn && !$conn->connect_error) {
    echo "✅ OK\n";
} else {
    echo "❌ ERRO: " . ($conn->connect_error ?? 'Desconhecido') . "\n";
}

// Verifica se as tabelas existem
echo "\nVerificando tabelas:\n";

$tables = ['tanks', 'controller_history', 'tank_pid_changes'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    echo "  $table: " . ($result->num_rows > 0 ? "✅" : "❌") . "\n";
}

// Testa query de dados
echo "\nTestando query de dados para tank_id = 5:\n";
$stmt = $conn->prepare("SELECT id, name, pid_p, pid_i, pid_d FROM tanks WHERE id = 5 LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "  Tanque encontrado: " . $row['name'] . "\n";
    echo "  PID P: " . ($row['pid_p'] ?? 'NULL') . "\n";
    echo "  PID I: " . ($row['pid_i'] ?? 'NULL') . "\n";
    echo "  PID D: " . ($row['pid_d'] ?? 'NULL') . "\n";
} else {
    echo "  ❌ Tanque não encontrado\n";
}
$stmt->close();

// Testa dados de histórico
echo "\nTestando dados de controller_history:\n";
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM controller_history WHERE tank_id = 5");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
echo "  Registos: " . $row['cnt'] . "\n";
$stmt->close();

$debug_output = ob_get_clean();

echo $debug_output;

// Agora tenta a API
echo "\n\n=== Tentando API ===\n";

ob_start();
include 'api/get_pid_suggestions.php';
$api_response = ob_get_clean();

echo "Resposta da API (primeiros 500 chars):\n";
echo htmlspecialchars(substr($api_response, 0, 500)) . "\n\n";

if (strlen($api_response) === 0) {
    echo "❌ ERRO: Resposta vazia!\n";
} else {
    $json = json_decode($api_response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ JSON válido\n";
        echo "Chaves: " . implode(', ', array_keys($json)) . "\n";
    } else {
        echo "❌ JSON inválido: " . json_last_error_msg() . "\n";
    }
}

?>
