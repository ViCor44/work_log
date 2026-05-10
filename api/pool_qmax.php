<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Não autenticado']); exit;
}

$tank_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : 0;
if ($tank_id <= 0) {
    echo json_encode(['error' => 'tank_id inválido']); exit;
}

// Garante que a tabela settings existe (mesmo padrão do dynamic_setpoint_config)
$conn->query("CREATE TABLE IF NOT EXISTS `settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(191) NOT NULL,
    `setting_value` text DEFAULT NULL,
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

$key = 'qmax_tank_' . $tank_id;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode(['qmax' => $row ? (float)$row['setting_value'] : null]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $qmax = isset($body['qmax']) ? (float)$body['qmax'] : null;
    if ($qmax === null || $qmax <= 0) {
        echo json_encode(['error' => 'Valor de Qmax inválido']); exit;
    }
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $qmax_str = (string)$qmax;
    $stmt->bind_param('ss', $key, $qmax_str);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'qmax' => $qmax]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método não suportado']);
}
