<?php
/**
 * Deteção de transições de alarme e envio de SMS via modem Teltonika.
 *
 * Chamado pelo worker scripts/fetch_controller_data.php após parse do XML
 * de cada controlador. Compara o estado atual com o guardado em
 * controller_alarm_state, envia SMS apenas em transições OK -> ALARME
 * e respeita o debounce definido em SMS_DEBOUNCE_MINUTES.
 *
 * Destinatários: utilizadores com receive_sms_alarms = 1 e phone preenchido.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/sms_client.php';

/**
 * Extrai o mapa {alarm_type => bool} a partir do array $data do controlador.
 * O array segue a mesma estrutura devolvida por get_controller_data.php
 * (JSON convertido a partir do XML de /ajax_inputs).
 */
function extract_controller_alarms(array $data): array
{
    $alarms = [];

    // Alarme interno principal. O firmware envia 'alarme' onde 0 == alarme ativo.
    if (array_key_exists('alarme', $data) && is_numeric($data['alarme'])) {
        $alarms['controlador_interno'] = ((int)$data['alarme'] === 0);
    }

    // Bits específicos vindos em <alarms>...</alarms>.
    $al = isset($data['alarms']) && is_array($data['alarms']) ? $data['alarms'] : [];
    $bitmap = [
        'power_failure' => 'Pane de corrente',
        'pneumatic_low' => 'Pressao pneumatica baixa',
        'pin_high'      => 'Pressao entrada filtro alta',
        'pout_high'     => 'Pressao saida filtro alta',
        'delta_p_high'  => 'Pressao diferencial alta',
        'pump1_fault'   => 'Falha Bomba 1',
        'pump2_fault'   => 'Falha Bomba 2',
    ];
    foreach ($bitmap as $key => $_label) {
        if (array_key_exists($key, $al)) {
            $alarms[$key] = !empty($al[$key]) && $al[$key] !== '0';
        }
    }

    return $alarms;
}

/**
 * Devolve etiqueta legível para cada tipo de alarme.
 */
function alarm_label(string $type): string
{
    $map = [
        'controlador_interno' => 'Alarme interno do controlador',
        'power_failure'       => 'Pane de corrente',
        'pneumatic_low'       => 'Pressao pneumatica baixa',
        'pin_high'            => 'Pressao entrada filtro alta',
        'pout_high'           => 'Pressao saida filtro alta',
        'delta_p_high'        => 'Pressao diferencial alta',
        'pump1_fault'         => 'Falha Bomba 1',
        'pump2_fault'         => 'Falha Bomba 2',
    ];
    return $map[$type] ?? $type;
}

/**
 * Devolve a lista de destinatários (arrays com id, nome, phone).
 */
function get_sms_recipients(mysqli $conn): array
{
    $res = $conn->query(
        "SELECT id, first_name, last_name, phone
         FROM users
         WHERE receive_sms_alarms = 1
           AND phone IS NOT NULL AND phone <> ''"
    );
    if (!$res) { return []; }
    return $res->fetch_all(MYSQLI_ASSOC);
}

/**
 * Grava um envio no log.
 */
function log_sms(mysqli $conn, string $to, string $msg, string $status, ?string $response, ?int $tankId, ?string $alarmType): void
{
    $stmt = $conn->prepare(
        "INSERT INTO sms_log (to_number, message, status, response, tank_id, alarm_type)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) { return; }
    $stmt->bind_param('ssssis', $to, $msg, $status, $response, $tankId, $alarmType);
    $stmt->execute();
    $stmt->close();
}

/**
 * Lê o estado guardado para um par (tank, alarm_type).
 */
function get_alarm_state(mysqli $conn, int $tankId, string $type): ?array
{
    $stmt = $conn->prepare(
        "SELECT is_active, first_active_at, last_sms_at
         FROM controller_alarm_state
         WHERE tank_id = ? AND alarm_type = ?
         LIMIT 1"
    );
    if (!$stmt) { return null; }
    $stmt->bind_param('is', $tankId, $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * Actualiza o estado do alarme na DB.
 *  - Se transitou para ativo: first_active_at = NOW()
 *  - Se transitou para inativo: last_cleared_at = NOW()
 *  - Sempre: last_seen_at = NOW()
 *  - Se sentSms=true: last_sms_at = NOW()
 */
function upsert_alarm_state(mysqli $conn, int $tankId, string $type, bool $active, bool $sentSms, bool $wasActive): void
{
    // UPSERT: se não existir cria; se existir actualiza os campos apropriados.
    $stmt = $conn->prepare(
        "INSERT INTO controller_alarm_state
            (tank_id, alarm_type, is_active, first_active_at, last_seen_at, last_sms_at, last_cleared_at)
         VALUES (?, ?, ?, CASE WHEN ? = 1 THEN NOW() ELSE NULL END, NOW(),
                 CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                 CASE WHEN ? = 0 THEN NOW() ELSE NULL END)
         ON DUPLICATE KEY UPDATE
            is_active       = VALUES(is_active),
            last_seen_at    = NOW(),
            first_active_at = CASE
                                 WHEN VALUES(is_active) = 1 AND is_active = 0 THEN NOW()
                                 WHEN VALUES(is_active) = 0 THEN NULL
                                 ELSE first_active_at
                              END,
            last_sms_at     = CASE WHEN ? = 1 THEN NOW() ELSE last_sms_at END,
            last_cleared_at = CASE
                                 WHEN VALUES(is_active) = 0 AND is_active = 1 THEN NOW()
                                 ELSE last_cleared_at
                              END"
    );
    if (!$stmt) { return; }
    $activeInt   = $active ? 1 : 0;
    $sentInt     = $sentSms ? 1 : 0;
    // Placeholders (para INSERT):
    //   tank_id(i), alarm_type(s), is_active(i), is_active(i) [first_active], sent(i) [last_sms], is_active(i) [last_cleared]
    // ON DUPLICATE: sent(i) [last_sms_at]
    $wasIntPlaceholder = $wasActive; // não usado pelo SQL directamente, mantido para futuro
    $stmt->bind_param(
        'isiiiii',
        $tankId,
        $type,
        $activeInt,
        $activeInt,
        $sentInt,
        $activeInt,
        $sentInt
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Ponto de entrada — chamado uma vez por iteração de tanque.
 * $pool = ['id'=>..., 'name'=>...]
 * $data = array do XML já decodificado
 */
function process_controller_alarms(mysqli $conn, array $pool, array $data): void
{
    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        return;
    }

    $tankId   = (int)$pool['id'];
    $tankName = isset($pool['name']) ? (string)$pool['name'] : ('tanque_' . $tankId);

    $current = extract_controller_alarms($data);
    if (empty($current)) {
        return; // nenhum campo de alarme reconhecido no payload
    }

    $debounceMin = defined('SMS_DEBOUNCE_MINUTES') ? (int)SMS_DEBOUNCE_MINUTES : 15;
    $recipients  = null;   // lazy load — só carrega se realmente houver alarme novo
    $client      = null;

    foreach ($current as $type => $active) {
        $prev      = get_alarm_state($conn, $tankId, $type);
        $wasActive = $prev ? (int)$prev['is_active'] === 1 : false;

        $shouldSend = false;
        // Transição OK -> ALARME → sempre envia
        if ($active && !$wasActive) {
            $shouldSend = true;
        }
        // Alarme persistente → reenvia se passou o debounce
        elseif ($active && $wasActive && $debounceMin > 0 && $prev && !empty($prev['last_sms_at'])) {
            $lastTs = strtotime($prev['last_sms_at']);
            if ($lastTs !== false && (time() - $lastTs) >= ($debounceMin * 60)) {
                // NÃO reenviar automaticamente enquanto persiste — só edge é obrigatório.
                // Deixa comentado: só enviar em transição. Caso queira lembretes:
                //   $shouldSend = true;
                $shouldSend = false;
            }
        }

        $sentOk = false;
        if ($shouldSend) {
            if ($recipients === null) {
                $recipients = get_sms_recipients($conn);
                $client     = new TeltonikaSmsClient();
            }
            if (!empty($recipients)) {
                $msg = build_alarm_message($tankName, $type);
                foreach ($recipients as $r) {
                    $to = trim((string)$r['phone']);
                    if ($to === '') { continue; }
                    $res = $client->send($to, $msg);
                    $status   = $res['ok'] ? 'sent' : 'failed';
                    $respTxt  = $res['ok'] ? (is_string($res['response']) ? $res['response'] : json_encode($res['response']))
                                           : ($res['error'] ?? '');
                    log_sms($conn, $to, $msg, $status, $respTxt, $tankId, $type);
                    if ($res['ok']) { $sentOk = true; }
                }
            } else {
                log_sms($conn, '(sem destinatarios)', build_alarm_message($tankName, $type),
                        'skipped', 'Nenhum utilizador com receive_sms_alarms=1', $tankId, $type);
            }
        }

        upsert_alarm_state($conn, $tankId, $type, (bool)$active, $sentOk, (bool)$wasActive);
    }
}

/**
 * Constrói a mensagem de SMS (curta, sem acentos, dentro de 160 chars).
 */
function build_alarm_message(string $tankName, string $type): string
{
    $label = alarm_label($type);
    $ts = date('d/m H:i');
    // Remove acentos para caber em GSM-7 e não usar Unicode (que reduz o limite para 70 chars).
    $safe = strtr($label, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a',
        'é'=>'e','ê'=>'e',
        'í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o',
        'ú'=>'u',
        'ç'=>'c',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A',
        'É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C',
    ]);
    $tankSafe = strtr($tankName, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C',
    ]);
    $msg = "[ALARME] {$tankSafe}: {$safe} ({$ts})";
    if (strlen($msg) > 160) { $msg = substr($msg, 0, 157) . '...'; }
    return $msg;
}
