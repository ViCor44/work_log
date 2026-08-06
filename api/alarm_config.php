<?php
require_once '../core.php';
require_once 'alarm_config_lib.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Não autorizado']); exit; }
ensure_alarm_config_table($conn);
$rows=$conn->query("SELECT t.id,t.name,c.chlorine_min,c.chlorine_max,c.ph_min,c.ph_max,c.modal_delay_minutes,c.modal_enabled,c.sound_enabled FROM tanks t LEFT JOIN controller_alarm_config c ON c.tank_id=t.id WHERE t.has_controller=1 ORDER BY t.name")->fetch_all(MYSQLI_ASSOC);
foreach($rows as &$row) $row=array_merge(default_alarm_config(),array_filter($row,fn($v)=>$v!==null));
echo json_encode(['controllers'=>$rows]);
