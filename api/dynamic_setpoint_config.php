<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

function return_json_response($payload, $status = 200) {
    http_response_code($status);
    if (ob_get_length()) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

set_exception_handler(function ($e) {
    $message = 'Erro interno no endpoint de setpoint dinamico';
    if (is_object($e) && method_exists($e, 'getMessage')) {
        $msg = trim((string)$e->getMessage());
        if ($msg !== '') {
            $message .= ': ' . $msg;
        }
    }
    return_json_response(['error' => $message], 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    if (ob_get_length()) {
        ob_end_clean();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Erro fatal no endpoint de setpoint dinamico']);
});

function ensure_settings_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS `settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(191) NOT NULL,
        `setting_value` text DEFAULT NULL,
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    try {
        return $conn->query($sql) === true;
    } catch (Throwable $e) {
        return false;
    }
}

if (!isset($_SESSION['user_id'])) {
    return_json_response(['error' => 'Nao autenticado'], 401);
}

if (!ensure_settings_table($conn)) {
    return_json_response(['error' => 'Nao foi possivel preparar a configuracao de setpoint dinamico'], 500);
}

function dynamic_setting_key($tank_id) {
    return 'dynamic_setpoint_tank_' . (int)$tank_id . '_ctrl_1_enabled';
}

function read_dynamic_state($conn, $tank_id) {
    $key = dynamic_setting_key($tank_id);
    try {
        $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    } catch (Throwable $e) {
        return null;
    }
    if (!$stmt) {
        return null;
    }
    try {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $res = $stmt->get_result();
    } catch (Throwable $e) {
        $stmt->close();
        return null;
    }
    $state = false;
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $state = isset($row['setting_value']) && (string)$row['setting_value'] === '1';
    }
    $stmt->close();
    return $state;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : 0;
    if ($tank_id <= 0) {
        return_json_response(['error' => 'tank_id invalido'], 400);
    }

    $enabled = read_dynamic_state($conn, $tank_id);
    if ($enabled === null) {
        return_json_response(['error' => 'Erro ao ler configuracao de setpoint dinamico'], 500);
    }

    return_json_response([
        'success' => true,
        'tank_id' => $tank_id,
        'states' => [
            '1' => $enabled,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return_json_response(['error' => 'Metodo nao permitido'], 405);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$tank_id = isset($payload['tank_id']) ? (int)$payload['tank_id'] : 0;
$ctrl = isset($payload['ctrl']) ? (int)$payload['ctrl'] : 0;
$enabled = !empty($payload['enabled']) ? 1 : 0;

if ($tank_id <= 0) {
    return_json_response(['error' => 'tank_id invalido'], 400);
}

if ($ctrl !== 1) {
    return_json_response(['error' => 'Setpoint dinamico disponivel apenas para o controlador 1 (cloro)'], 400);
}

$key = dynamic_setting_key($tank_id);
$value = (string)$enabled;

$stmt_check = null;
try {
    $stmt_check = $conn->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1');
} catch (Throwable $e) {
    $stmt_check = null;
}
if (!$stmt_check) {
    return_json_response(['error' => 'Erro ao preparar verificacao de configuracao'], 500);
}
$res_check = null;
try {
    $stmt_check->bind_param('s', $key);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
} catch (Throwable $e) {
    $stmt_check->close();
    return_json_response(['error' => 'Erro ao consultar configuracao atual'], 500);
}
$exists = $res_check && $res_check->num_rows > 0;
$stmt_check->close();

if ($exists) {
    try {
        $stmt_save = $conn->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
    } catch (Throwable $e) {
        $stmt_save = null;
    }
    if (!$stmt_save) {
        return_json_response(['error' => 'Erro ao preparar update de configuracao'], 500);
    }
    $stmt_save->bind_param('ss', $value, $key);
} else {
    try {
        $stmt_save = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    } catch (Throwable $e) {
        $stmt_save = null;
    }
    if (!$stmt_save) {
        return_json_response(['error' => 'Erro ao preparar insert de configuracao'], 500);
    }
    $stmt_save->bind_param('ss', $key, $value);
}

try {
    $okSave = $stmt_save->execute();
} catch (Throwable $e) {
    $okSave = false;
}

if (!$okSave) {
    $stmt_save->close();
    return_json_response(['error' => 'Falha ao gravar configuracao de setpoint dinamico'], 500);
}
$stmt_save->close();

$user_id = (int)$_SESSION['user_id'];
$description = sprintf('Setpoint dinamico (ctrl=1) %s | tank_id=%d', $enabled ? 'ATIVADO' : 'DESATIVADO', $tank_id);
log_action($conn, $user_id, 'DYNAMIC_SETPOINT_TOGGLE', $description);

return_json_response([
    'success' => true,
    'tank_id' => $tank_id,
    'ctrl' => 1,
    'enabled' => (bool)$enabled,
]);
