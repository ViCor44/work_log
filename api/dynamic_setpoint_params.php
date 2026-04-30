<?php
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

function return_json($payload, $status = 200) {
    http_response_code($status);
    if (ob_get_length()) ob_end_clean();
    echo json_encode($payload);
    exit;
}

set_exception_handler(function ($e) {
    return_json(['error' => 'Erro interno: ' . $e->getMessage()], 500);
});

// ── Defaults canónicos (espelho dos valores hardcoded no worker) ──────────────
function param_defaults(): array {
    return [
        'anticipation_offset' => 0.06,
        'min_follow_offset'   => 0.03,
        'max_follow_offset'   => 0.18,
        'pump_min_target'     => 20.0,
        'pump_max_target'     => 35.0,
        'pump_adjust_step'    => 0.02,
        'trend_deadband'      => 0.01,
        'cooldown_sec'        => 60.0,
        'min_send_delta'      => 0.01,
    ];
}

function param_key(int $tank_id, string $name): string {
    return 'dynamic_setpoint_tank_' . $tank_id . '_ctrl_1_param_' . $name;
}

function read_params(mysqli $conn, int $tank_id): array {
    $defaults = param_defaults();
    $out = [];
    foreach ($defaults as $name => $default) {
        $val = get_setting_value($conn, param_key($tank_id, $name), null);
        $out[$name] = ($val !== null) ? (float)$val : $default;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────

if (!isset($_REQUEST['tank_id']) || !is_numeric($_REQUEST['tank_id'])) {
    return_json(['error' => 'tank_id inválido'], 400);
}
$tank_id = (int)$_REQUEST['tank_id'];

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    return_json([
        'params'   => read_params($conn, $tank_id),
        'defaults' => param_defaults(),
    ]);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'reset') {
        // Apaga todas as entradas de parâmetros → worker volta a usar defaults hardcoded
        foreach (array_keys(param_defaults()) as $name) {
            $key = param_key($tank_id, $name);
            $stmt = $conn->prepare("DELETE FROM settings WHERE setting_key = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $stmt->close();
        }
        if (function_exists('log_action')) {
            log_action($conn, $_SESSION['user_id'] ?? 0, 'DYNAMIC_PARAMS_RESET',
                "Tanque {$tank_id}: parâmetros setpoint dinâmico restaurados para defaults");
        }
        return_json(['success' => true, 'message' => 'Parâmetros restaurados para os valores padrão.', 'params' => param_defaults()]);
    }

    // save — valida e persiste cada campo
    $defaults = param_defaults();
    $errors   = [];
    $saved    = [];

    $rules = [
        'anticipation_offset' => ['min' => 0.00, 'max' => 2.0],
        'min_follow_offset'   => ['min' => 0.00, 'max' => 2.0],
        'max_follow_offset'   => ['min' => 0.00, 'max' => 5.0],
        'pump_min_target'     => ['min' => 0.0,  'max' => 100.0],
        'pump_max_target'     => ['min' => 0.0,  'max' => 100.0],
        'pump_adjust_step'    => ['min' => 0.00, 'max' => 1.0],
        'trend_deadband'      => ['min' => 0.00, 'max' => 1.0],
        'cooldown_sec'        => ['min' => 10.0, 'max' => 3600.0],
        'min_send_delta'      => ['min' => 0.00, 'max' => 1.0],
    ];

    foreach ($defaults as $name => $default) {
        if (!isset($_POST[$name])) continue;
        $val = (float)$_POST[$name];
        if ($val < $rules[$name]['min'] || $val > $rules[$name]['max']) {
            $errors[] = "Valor de '{$name}' fora do intervalo [{$rules[$name]['min']}, {$rules[$name]['max']}].";
            continue;
        }
        set_setting_value($conn, param_key($tank_id, $name), (string)$val);
        $saved[$name] = $val;
    }

    if (!empty($errors)) {
        return_json(['error' => implode(' ', $errors)], 422);
    }

    if (function_exists('log_action')) {
        log_action($conn, $_SESSION['user_id'] ?? 0, 'DYNAMIC_PARAMS_SAVE',
            "Tanque {$tank_id}: parâmetros setpoint dinâmico guardados: " . json_encode($saved));
    }

    return_json(['success' => true, 'message' => 'Parâmetros guardados com sucesso.', 'params' => read_params($conn, $tank_id)]);
}

return_json(['error' => 'Método não suportado'], 405);
