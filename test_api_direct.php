<?php
// Teste direto da API
error_reporting(E_ALL);
ini_set('display_errors', 0);

$work_log_dir = __DIR__;
chdir($work_log_dir);

session_start();
$_SESSION['user_id'] = 1;

$_GET['tank_id'] = 5;
$_GET['days'] = 3;

// Carrega core manualmente primeiro
require_once $work_log_dir . '/core.php';

// Agora simula o que a API faria
ob_start();

// Coloca header JSON ANTES de qualquer require
header('Content-Type: application/json; charset=utf-8');

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

// Este é o código que a API iria executar
require_once $work_log_dir . '/api/get_pid_suggestions.php';

$output = ob_get_clean();

// Debug output
echo "=== API Response Test ===\n\n";
echo "Response length: " . strlen($output) . " bytes\n";
echo "First 200 chars: " . htmlspecialchars(substr($output, 0, 200)) . "\n\n";

// Try to parse JSON
if (strlen($output) === 0) {
    echo "❌ ERRO: Resposta vazia!\n";
} else {
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ JSON válido\n";
        echo "Keys: " . implode(', ', array_keys($json)) . "\n";
        
        // Check structure
        if (isset($json['chlorine'])) {
            echo "✅ chlorine key presente\n";
            echo "  - stats: " . (isset($json['chlorine']['stats']) ? "✅" : "❌") . "\n";
            echo "  - suggestions: " . (isset($json['chlorine']['suggestions']) ? "✅" : "❌") . "\n";
            echo "  - suggested_values: " . (isset($json['chlorine']['suggested_values']) ? "✅" : "❌") . "\n";
            
            if (isset($json['chlorine']['suggested_values'])) {
                echo "  Suggested P: " . $json['chlorine']['suggested_values']['p'] . "\n";
                echo "  Suggested I: " . $json['chlorine']['suggested_values']['i'] . "\n";
                echo "  Suggested D: " . $json['chlorine']['suggested_values']['d'] . "\n";
            }
        }
    } else {
        echo "❌ JSON inválido: " . json_last_error_msg() . "\n";
    }
}

?>
