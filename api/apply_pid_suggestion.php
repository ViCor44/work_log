<?php
// Desabilita warnings/notices que podem quebrar JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Inicia output buffering
ob_start();

header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

// Função helper para retornar JSON com buffer limpo
function return_json_response($response, $code = 200) {
    http_response_code($code);
    if (ob_get_length()) {
        ob_end_clean();
    }
    echo json_encode($response);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    return_json_response(['error' => 'Acesso não autorizado'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return_json_response(['error' => 'Método não permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['tank_id'], $data['p'], $data['i'], $data['d'])) {
    return_json_response(['error' => 'Parâmetros obrigatórios ausentes'], 400);
}

$tank_id = (int)$data['tank_id'];
$p = (float)$data['p'];
$i = (float)$data['i'];
$d = (float)$data['d'];
$reason = isset($data['reason']) ? trim($data['reason']) : 'Sugestão automática aceita';
$force = isset($data['force']) && $data['force'] === true;
$user_id = (int)$_SESSION['user_id'];
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;

// Validações
if (
    $tank_id <= 0 ||
    !is_numeric($p) || !is_numeric($i) || !is_numeric($d) ||
    $p <= 0 || $i < 0 || $d < 0 ||
    $p > 100 || $i > 7200 || $d > 3600
) {
    return_json_response(['error' => 'Valores inválidos'], 400);
}

// Verifica se o tanque existe
$stmt = $conn->prepare("SELECT id FROM tanks WHERE id = ?");
$stmt->bind_param('i', $tank_id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    return_json_response(['error' => 'Tanque não encontrado'], 404);
}
$stmt->close();

// Verifica bloqueio de 72 horas após última alteração (ignorado em modo force)
$stmt_block = $conn->prepare("SELECT changed_at FROM tank_pid_changes WHERE tank_id = ? ORDER BY changed_at DESC LIMIT 1");
if ($stmt_block && !$force) {
    $stmt_block->bind_param('i', $tank_id);
    if ($stmt_block->execute()) {
        $result_block = $stmt_block->get_result();
        if ($result_block->num_rows > 0) {
            $last_change = $result_block->fetch_assoc();
            $last_change_time = strtotime($last_change['changed_at']);
            $hours_since_last_change = (time() - $last_change_time) / 3600;

            if ($hours_since_last_change < 72) {
                $remaining_hours = ceil(72 - $hours_since_last_change);
                return_json_response([
                    'error' => 'Período de monitorização ativo. Última alteração foi há ' . round($hours_since_last_change, 1) . ' horas. Aguarde mais ' . $remaining_hours . ' horas para aceitar nova sugestão.'
                ], 429); // 429 Too Many Requests
            }
        }
    }
    $stmt_block->close();
}

// Inicia transação
$conn->begin_transaction();

try {
    // 1. Insere o registro na tabela de histórico
    $stmt = $conn->prepare("INSERT INTO `tank_pid_changes` (`tank_id`,`p`,`i`,`d`,`reason`,`changed_by`,`ip_address`) VALUES (?,?,?,?,?,?,?)");
    if (!$stmt) {
        throw new Exception('Erro ao preparar inserção: ' . $conn->error);
    }
    $stmt->bind_param('idddsis', $tank_id, $p, $i, $d, $reason, $user_id, $ip);
    if (!$stmt->execute()) {
        throw new Exception('Falha ao registar alteração de PID: ' . $stmt->error);
    }
    $stmt->close();

    // 2. Verifica se as colunas PID existem na tabela tanks
    $cols = array();
    if ($res = $conn->query("SHOW COLUMNS FROM `tanks`")) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
        $res->free();
    }

    // 3. Se as colunas existem, atualiza os valores atuais
    if (isset($cols['pid_p']) && isset($cols['pid_i']) && isset($cols['pid_d'])) {
        $stmt = $conn->prepare("UPDATE tanks SET pid_p = ?, pid_i = ?, pid_d = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('dddi', $p, $i, $d, $tank_id);
            if (!$stmt->execute()) {
                throw new Exception('Falha ao atualizar valores atuais: ' . $stmt->error);
            }
            $stmt->close();
        }
    }

    // Confirma transação
    $conn->commit();

    if (ob_get_length()) {
        ob_end_clean();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Alteração de PID registada com sucesso',
        'tank_id' => $tank_id,
        'new_pid' => [
            'p' => $p,
            'i' => $i,
            'd' => $d
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    $conn->rollback();
    return_json_response(['error' => $e->getMessage()], 500);
}
