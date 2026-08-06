<?php
require_once '../core.php';
require_once 'alarm_log_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(401); echo json_encode(['success'=>false]); exit;
}
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$allowed = ['modal_shown','modal_ignored','modal_opened'];
$event = (string)($input['event_type'] ?? '');
if (!in_array($event, $allowed, true)) { http_response_code(422); echo json_encode(['success'=>false]); exit; }
$tankId = (int)($input['tank_id'] ?? 0);
$alarmType = substr((string)($input['alarm_type'] ?? 'desconhecido'), 0, 64);
$value = isset($input['current_value']) && is_numeric($input['current_value']) ? (float)$input['current_value'] : null;
$first = !empty($input['first_active_at']) ? date('Y-m-d H:i:s', strtotime($input['first_active_at'])) : null;
log_alarm_event($conn, $tankId ?: null, (int)$_SESSION['user_id'], $alarmType, $event, $value, $first,
    ['controller_name'=>(string)($input['name'] ?? ''), 'page'=>(string)($input['page'] ?? '')]);
echo json_encode(['success'=>true]);
