<?php
require_once '../core.php';
require_once 'alarm_config_lib.php';
require_once 'sms_alarm_notifier.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'Não autorizado']); exit; }
ensure_alarm_config_table($conn);
$conn->query("CREATE TABLE IF NOT EXISTS controller_alarm_state (
    tank_id INT NOT NULL, alarm_type VARCHAR(64) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0,
    first_active_at DATETIME NULL, last_seen_at DATETIME NULL, last_sms_at DATETIME NULL,
    last_cleared_at DATETIME NULL, PRIMARY KEY (tank_id, alarm_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Consulta diretamente os controladores para que o modal não dependa do cron/worker.
// Um lock e uma janela de 8 segundos evitam que várias páginas façam a mesma ronda.
$pollStamp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worklog_global_alarm_poll.stamp';
$pollLockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'worklog_global_alarm_poll.lock';
$pollLock = @fopen($pollLockPath, 'c');
$shouldPoll = !is_file($pollStamp) || (time() - (int)@filemtime($pollStamp)) >= 8;
if ($shouldPoll && $pollLock && @flock($pollLock, LOCK_EX | LOCK_NB)) {
    // Verifica novamente depois de obter o lock: outra chamada pode ter terminado entretanto.
    $shouldPoll = !is_file($pollStamp) || (time() - (int)@filemtime($pollStamp)) >= 8;
    if ($shouldPoll) {
        $controllers = $conn->query("SELECT id,name,controller_ip FROM tanks WHERE has_controller=1 AND controller_ip IS NOT NULL AND controller_ip<>''");
        $multi = curl_multi_init();
        $handles = [];
        if ($controllers) {
            while ($controller = $controllers->fetch_assoc()) {
                $url = 'http://' . trim($controller['controller_ip']) . '/ajax_inputs';
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>1, CURLOPT_TIMEOUT=>3, CURLOPT_NOSIGNAL=>1]);
                curl_multi_add_handle($multi, $ch);
                $handles[] = ['handle'=>$ch, 'controller'=>$controller];
            }
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 0.25);
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $item) {
            $ch = $item['handle'];
            $body = curl_multi_getcontent($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body !== false && $http === 200) {
                $payload = json_decode($body, true);
                if (!is_array($payload) && str_contains(ltrim($body), '<?xml')) {
                    try { $payload = json_decode(json_encode(new SimpleXMLElement($body)), true); } catch (Throwable $e) { $payload = null; }
                }
                if (is_array($payload)) {
                    $tankId = (int)$item['controller']['id'];
                    $payload['__alarm_config'] = get_alarm_config($conn, $tankId);
                    foreach (extract_controller_alarms($payload) as $type => $active) {
                        $previous = get_alarm_state($conn, $tankId, $type);
                        $wasActive = $previous && (int)$previous['is_active'] === 1;
                        upsert_alarm_state($conn, $tankId, $type, (bool)$active, false, $wasActive);
                    }
                }
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        @touch($pollStamp);
    }
    @flock($pollLock, LOCK_UN);
}
if ($pollLock) fclose($pollLock);

// Mantém os alarmes químicos atualizados mesmo quando o worker de SMS não está
// em execução. A leitura mais recente do histórico é a fonte comum a todas as páginas.
$latestSql = "SELECT h.tank_id,h.chlorine_value,h.ph_value
              FROM controller_history h
              JOIN (SELECT tank_id,MAX(log_datetime) max_dt FROM controller_history GROUP BY tank_id) x
                ON x.tank_id=h.tank_id AND x.max_dt=h.log_datetime
              JOIN tanks t ON t.id=h.tank_id AND t.has_controller=1";
$latestResult = $conn->query($latestSql);
if ($latestResult) {
    while ($reading = $latestResult->fetch_assoc()) {
        $tankId = (int)$reading['tank_id'];
        $cfg = get_alarm_config($conn, $tankId);
        $chlorine = is_numeric($reading['chlorine_value']) ? (float)$reading['chlorine_value'] : null;
        $ph = is_numeric($reading['ph_value']) ? (float)$reading['ph_value'] : null;
        $states = [];
        if ($chlorine !== null && $chlorine >= 0) {
            $states['cloro_baixo'] = $chlorine < (float)$cfg['chlorine_min'];
            $states['cloro_alto'] = $chlorine > (float)$cfg['chlorine_max'];
        }
        if ($ph !== null && $ph >= 0) {
            $states['ph_baixo'] = $ph < (float)$cfg['ph_min'];
            $states['ph_alto'] = $ph > (float)$cfg['ph_max'];
        }
        foreach ($states as $type => $active) {
            $previous = get_alarm_state($conn, $tankId, $type);
            $wasActive = $previous && (int)$previous['is_active'] === 1;
            upsert_alarm_state($conn, $tankId, $type, (bool)$active, false, $wasActive);
        }
    }
}
$sql="SELECT s.tank_id,t.name,s.alarm_type,s.first_active_at,TIMESTAMPDIFF(SECOND,s.first_active_at,NOW()) age_seconds,COALESCE(c.modal_delay_minutes,5) modal_delay_minutes,COALESCE(c.sound_enabled,1) sound_enabled FROM controller_alarm_state s JOIN tanks t ON t.id=s.tank_id LEFT JOIN controller_alarm_config c ON c.tank_id=t.id WHERE s.is_active=1 AND COALESCE(c.modal_enabled,1)=1 AND s.first_active_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE,s.first_active_at,NOW()) >= COALESCE(c.modal_delay_minutes,5) ORDER BY s.first_active_at";
$result=$conn->query($sql);
if (!$result) { echo json_encode(['alarms'=>[],'error'=>'Estado de alarmes indisponível']); exit; }
$rows=$result->fetch_all(MYSQLI_ASSOC);
foreach($rows as &$r) $r['label']=alarm_label($r['alarm_type']);
echo json_encode(['alarms'=>$rows,'server_time'=>date('c'),'latest_readings'=>($latestResult ? $latestResult->num_rows : 0)]);
