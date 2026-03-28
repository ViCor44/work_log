<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

if (!isset($_GET['tank_id']) || !is_numeric($_GET['tank_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do tanque inválido']);
    exit;
}

$tank_id = (int)$_GET['tank_id'];

$stmt = $conn->prepare("SELECT c.changed_at, c.p, c.i, c.d, c.reason, u.username AS changed_by FROM tank_pid_changes c LEFT JOIN users u ON c.changed_by = u.id WHERE c.tank_id = ? ORDER BY c.changed_at DESC LIMIT 100");
$stmt->bind_param('i', $tank_id);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($logs);
