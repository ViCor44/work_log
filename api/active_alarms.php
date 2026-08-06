<?php
require_once '../core.php';
require_once 'alarm_config_lib.php';
require_once 'sms_alarm_notifier.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Não autorizado']); exit; }
ensure_alarm_config_table($conn);
$sql="SELECT s.tank_id,t.name,s.alarm_type,s.first_active_at,TIMESTAMPDIFF(SECOND,s.first_active_at,NOW()) age_seconds,COALESCE(c.modal_delay_minutes,5) modal_delay_minutes,COALESCE(c.sound_enabled,1) sound_enabled FROM controller_alarm_state s JOIN tanks t ON t.id=s.tank_id LEFT JOIN controller_alarm_config c ON c.tank_id=t.id WHERE s.is_active=1 AND COALESCE(c.modal_enabled,1)=1 AND s.first_active_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE,s.first_active_at,NOW()) >= COALESCE(c.modal_delay_minutes,5) ORDER BY s.first_active_at";
$result=$conn->query($sql);
if (!$result) { echo json_encode(['alarms'=>[],'error'=>'Estado de alarmes indisponível']); exit; }
$rows=$result->fetch_all(MYSQLI_ASSOC);
foreach($rows as &$r) $r['label']=alarm_label($r['alarm_type']);
echo json_encode(['alarms'=>$rows,'server_time'=>date('c')]);
