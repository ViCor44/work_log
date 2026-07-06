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

/** Converte para float ou devolve null. */
function sms_float_or_null($v): ?float
{
    if ($v === null || $v === '' || $v === 'NAN') { return null; }
    if (is_array($v)) { return null; }
    $s = str_replace(',', '.', (string)$v);
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Log dedicado do notifier de alarmes. Escreve em logs/sms_alarms_YYYY-MM-DD.log
 * e replica no logger global do worker (file_log), se existir.
 */
function sms_alarm_log(string $msg): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($dir . '/sms_alarms_' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    if (function_exists('file_log')) { file_log('SMS_ALARM ' . $msg); }
}

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

    // Valores químicos fora dos limites (envia em ambas as transições).
    $cloro = sms_float_or_null($data['freeChlorine'] ?? null);
    if ($cloro !== null) {
        $alarms['cloro_baixo'] = $cloro < (defined('LIMIT_CLORO_MIN') ? LIMIT_CLORO_MIN : 1.0);
        $alarms['cloro_alto']  = $cloro > (defined('LIMIT_CLORO_MAX') ? LIMIT_CLORO_MAX : 3.0);
    }
    $ph = sms_float_or_null($data['pH'] ?? null);
    if ($ph !== null) {
        $alarms['ph_baixo'] = $ph < (defined('LIMIT_PH_MIN') ? LIMIT_PH_MIN : 7.0);
        $alarms['ph_alto']  = $ph > (defined('LIMIT_PH_MAX') ? LIMIT_PH_MAX : 7.8);
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
        'cloro_baixo'         => 'Cloro baixo',
        'cloro_alto'          => 'Cloro alto',
        'ph_baixo'            => 'pH baixo',
        'ph_alto'             => 'pH alto',
        'lora_offline'        => 'LoRa offline (sem sinal)',
        'equipment_off'       => 'Equipamento desligado',
        'perlite_change_due'  => 'Substituir perlite (falta 1 dia)',
    ];
    return $map[$type] ?? $type;
}

/**
 * Classifica o tipo de alarme para aplicar preferências por utilizador.
 */
function sms_alarm_group(string $type): string
{
    if (in_array($type, ['cloro_baixo', 'cloro_alto', 'ph_baixo', 'ph_alto'], true)) {
        return 'chemical';
    }
    if ($type === 'lora_offline') {
        return 'lora_offline';
    }
    if ($type === 'equipment_off') {
        return 'equipment_off';
    }
    if ($type === 'perlite_change_due') {
        return 'perlite';
    }
    return 'controller';
}

/**
 * Devolve a lista de destinatários (arrays com id, nome, phone).
 */
function get_sms_recipients(mysqli $conn): array
{
    $defaultMin = defined('SMS_ALARM_MIN_MINUTES') ? (int)SMS_ALARM_MIN_MINUTES : 17;

    $sqlNew = "SELECT id, first_name, last_name, phone,
                      receive_sms_alarms,
                      COALESCE(receive_sms_controller, receive_sms_alarms) AS receive_sms_controller,
                      COALESCE(receive_sms_chemical, receive_sms_alarms) AS receive_sms_chemical,
                      COALESCE(receive_sms_lora_offline, receive_sms_alarms) AS receive_sms_lora_offline,
                      COALESCE(receive_sms_equipment_off, receive_sms_alarms) AS receive_sms_equipment_off,
                      COALESCE(receive_sms_perlite, receive_sms_alarms) AS receive_sms_perlite,
                      COALESCE(sms_alarm_min_minutes, {$defaultMin}) AS sms_alarm_min_minutes
               FROM users
               WHERE receive_sms_alarms = 1
                 AND phone IS NOT NULL AND phone <> ''";
    $res = $conn->query($sqlNew);
    if ($res) {
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    // Compatibilidade com esquemas antigos (sem colunas novas).
    $sqlOld = "SELECT id, first_name, last_name, phone,
                      receive_sms_alarms,
                      receive_sms_alarms AS receive_sms_controller,
                      receive_sms_alarms AS receive_sms_chemical,
                      receive_sms_alarms AS receive_sms_lora_offline,
                      receive_sms_alarms AS receive_sms_equipment_off,
                      receive_sms_alarms AS receive_sms_perlite,
                      {$defaultMin} AS sms_alarm_min_minutes
               FROM users
               WHERE receive_sms_alarms = 1
                 AND phone IS NOT NULL AND phone <> ''";
    $resOld = $conn->query($sqlOld);
    if (!$resOld) {
        return [];
    }
    return $resOld->fetch_all(MYSQLI_ASSOC);
}

/**
 * Verifica se o utilizador pretende receber este tipo de alarme.
 */
function user_wants_alarm(array $user, string $type): bool
{
    if (empty($user['receive_sms_alarms'])) {
        return false;
    }

    $group = sms_alarm_group($type);
    if ($group === 'controller') {
        return !empty($user['receive_sms_controller']);
    }
    if ($group === 'chemical') {
        return !empty($user['receive_sms_chemical']);
    }
    if ($group === 'lora_offline') {
        return !empty($user['receive_sms_lora_offline']);
    }
    if ($group === 'equipment_off') {
        return !empty($user['receive_sms_equipment_off']);
    }
    if ($group === 'perlite') {
        return !empty($user['receive_sms_perlite']);
    }
    return true;
}

/**
 * Minutos mínimos de alarme para envio (por utilizador).
 * Aplicado a alarmes de controlador e químicos.
 */
function user_alarm_min_minutes(array $user, string $type): int
{
    $group = sms_alarm_group($type);
    if (!in_array($group, ['controller', 'chemical'], true)) {
        return 0;
    }

    $v = isset($user['sms_alarm_min_minutes']) ? (int)$user['sms_alarm_min_minutes'] : 0;
    if ($v < 0) { $v = 0; }
    if ($v > 1440) { $v = 1440; }
    return $v;
}

/**
 * Verifica se já foi enviado SMS deste evento para o utilizador na janela atual.
 */
function was_event_sent_to_user_in_window(
    mysqli $conn,
    string $to,
    int $tankId,
    string $type,
    string $event,
    ?string $firstActiveAt
): bool {
    if ($firstActiveAt === null || $firstActiveAt === '') {
        return false;
    }
    $prefix = $event === 'OK' ? '[OK]%' : '[ALARME]%';
    // Tolerância de 60s: o log_sms pode ter sido gravado uns milissegundos
    // antes de first_active_at ser persistido em controller_alarm_state.
    $stmt = $conn->prepare(
        "SELECT 1
         FROM sms_log
         WHERE to_number = ?
           AND tank_id = ?
           AND alarm_type = ?
           AND status = 'sent'
           AND ts >= DATE_SUB(?, INTERVAL 60 SECOND)
           AND message LIKE ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sisss', $to, $tankId, $type, $firstActiveAt, $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res ? (bool)$res->fetch_row() : false;
    $stmt->close();
    return $ok;
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
 * Guarda global: verifica se já foi enviado (status='sent') um SMS ao mesmo
 * destinatário para o mesmo tank_id/alarm_type nos últimos $windowSec segundos.
 * Usada como defesa em profundidade contra execuções concorrentes do worker.
 */
function was_recently_sent(mysqli $conn, string $to, ?int $tankId, ?string $alarmType, int $windowSec = 30): bool
{
    if ($tankId === null || $alarmType === null) { return false; }
    $stmt = $conn->prepare(
        "SELECT 1 FROM sms_log
          WHERE to_number = ?
            AND tank_id = ?
            AND alarm_type = ?
            AND status = 'sent'
            AND ts >= DATE_SUB(NOW(), INTERVAL ? SECOND)
          LIMIT 1"
    );
    if (!$stmt) { return false; }
    $stmt->bind_param('sisi', $to, $tankId, $alarmType, $windowSec);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res ? (bool)$res->fetch_row() : false;
    $stmt->close();
    return $ok;
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
        // Não encontrou nenhum campo de alarme reconhecido. Registar as chaves
        // do payload para podermos ver o que o XML deste controlador expõe.
        $keys = is_array($data) ? array_keys($data) : [];
        sms_alarm_log("tanque={$tankName} id={$tankId} SEM_CAMPOS_ALARME payload_keys=" . json_encode($keys));
        return;
    }
    sms_alarm_log("tanque={$tankName} id={$tankId} alarmes_detetados=" . json_encode($current));

    $recipients  = null;   // lazy load — só carrega se realmente houver alarme novo
    $client      = null;

    foreach ($current as $type => $active) {
        $prev      = get_alarm_state($conn, $tankId, $type);
        $wasActive = $prev ? (int)$prev['is_active'] === 1 : false;
        $firstActiveAt = $prev['first_active_at'] ?? null;
        $shouldSendAny = false;
        $reason        = 'NO_CHANGE';

        // Histerese para alarmes químicos: se o alarme já estava ATIVO e o valor
        // apenas roçou a normalidade, mantém-o ativo para evitar SMS repetidos
        // por flapping ao redor do limite.
        if ($wasActive && !$active) {
            $cloro   = sms_float_or_null($data['freeChlorine'] ?? null);
            $ph      = sms_float_or_null($data['pH'] ?? null);
            $hCloro  = defined('LIMIT_CLORO_HYST') ? (float)LIMIT_CLORO_HYST : 0.0;
            $hPh     = defined('LIMIT_PH_HYST')    ? (float)LIMIT_PH_HYST    : 0.0;
            $lCMin   = defined('LIMIT_CLORO_MIN')  ? (float)LIMIT_CLORO_MIN  : 1.0;
            $lCMax   = defined('LIMIT_CLORO_MAX')  ? (float)LIMIT_CLORO_MAX  : 3.0;
            $lPhMin  = defined('LIMIT_PH_MIN')     ? (float)LIMIT_PH_MIN     : 7.0;
            $lPhMax  = defined('LIMIT_PH_MAX')     ? (float)LIMIT_PH_MAX     : 7.8;

            if ($type === 'cloro_alto' && $cloro !== null && $cloro > ($lCMax - $hCloro)) {
                $active = true;
                sms_alarm_log("  tipo={$type} HISTERESE cloro={$cloro} > " . ($lCMax - $hCloro) . " -> mantem_ativo");
            } elseif ($type === 'cloro_baixo' && $cloro !== null && $cloro < ($lCMin + $hCloro)) {
                $active = true;
                sms_alarm_log("  tipo={$type} HISTERESE cloro={$cloro} < " . ($lCMin + $hCloro) . " -> mantem_ativo");
            } elseif ($type === 'ph_alto' && $ph !== null && $ph > ($lPhMax - $hPh)) {
                $active = true;
                sms_alarm_log("  tipo={$type} HISTERESE ph={$ph} > " . ($lPhMax - $hPh) . " -> mantem_ativo");
            } elseif ($type === 'ph_baixo' && $ph !== null && $ph < ($lPhMin + $hPh)) {
                $active = true;
                sms_alarm_log("  tipo={$type} HISTERESE ph={$ph} < " . ($lPhMin + $hPh) . " -> mantem_ativo");
            }
        }

        // Se acabou de entrar em alarme, considera "first_active_at" = agora
        // para efeitos de cálculo de idade nesta iteração (o valor é persistido
        // no upsert_alarm_state no fim do ciclo).
        $effectiveFirstActive = $firstActiveAt;
        if ($active && !$wasActive) {
            $effectiveFirstActive = date('Y-m-d H:i:s');
            // Ainda marcamos como "arme" no log, mas utilizadores com min=0
            // recebem já nesta iteração (ver bloco de envio abaixo).
            $reason = 'ARMED_WAIT_USER_MINUTES';
        } elseif ($active && $wasActive) {
            if ($firstActiveAt === null) {
                $reason = 'WAITING_FIRST_ACTIVE_TIMESTAMP';
            } else {
                $ageSec = time() - strtotime((string)$firstActiveAt);
                $ageMin = $ageSec / 60;
                $reason = 'ACTIVE_' . round($ageMin, 1) . 'MIN';
            }
        } elseif (!$active && $wasActive) {
            // Recuperação: envio é decidido por utilizador, apenas para quem recebeu [ALARME].
            $reason = 'RECOVERY_CHECK_PER_USER';
        }

        $decision = $reason;
        sms_alarm_log("  tipo={$type} active=" . ($active ? '1' : '0')
            . ' was_active=' . ($wasActive ? '1' : '0')
            . ' first=' . ($firstActiveAt ?? '-')
            . " -> {$decision}");

        $sentOk = false;
        // Passa a considerar também a primeira detecção ($active && !$wasActive):
        // utilizadores com min_minutes = 0 devem receber já.
        if ($active || (!$active && $wasActive)) {
            if ($recipients === null) {
                $recipients = get_sms_recipients($conn);
                $client     = new TeltonikaSmsClient();
                sms_alarm_log('destinatarios=' . count($recipients));
            }

            if (!empty($recipients)) {
                foreach ($recipients as $r) {
                    $to = trim((string)$r['phone']);
                    if ($to === '') { continue; }
                    if (!user_wants_alarm($r, $type)) { continue; }

                    if ($active) {
                        if ($effectiveFirstActive === null) { continue; }
                        $ageSec = time() - strtotime((string)$effectiveFirstActive);
                        $ageMin = $ageSec / 60;
                        $minForUser = user_alarm_min_minutes($r, $type);
                        if ($ageMin < $minForUser) {
                            continue;
                        }
                        // Dedup: usa effectiveFirstActive para cobrir também a primeira
                        // iteração (quando firstActiveAt ainda é null na BD).
                        if (was_event_sent_to_user_in_window($conn, $to, $tankId, $type, 'ALARME', $effectiveFirstActive)) {
                            continue;
                        }
                        // Defesa concorrente: não repete o mesmo SMS em 30s.
                        if (was_recently_sent($conn, $to, $tankId, $type, 30)) {
                            sms_alarm_log("SMS to={$to} tipo={$type} SKIP_DUP_30s");
                            continue;
                        }

                        $msg = build_alarm_message($tankName, $type, 'ALARME', $data);
                        $res = $client->send($to, $msg);
                        $status   = $res['ok'] ? 'sent' : 'failed';
                        $respTxt  = $res['ok'] ? (is_string($res['response']) ? $res['response'] : json_encode($res['response']))
                                               : ($res['error'] ?? '');
                        log_sms($conn, $to, $msg, $status, $respTxt, $tankId, $type);
                        sms_alarm_log("SMS to={$to} tipo={$type} event=ALARME min_user={$minForUser} status={$status} resp=" . substr($respTxt, 0, 200));
                        if ($res['ok']) {
                            $sentOk = true;
                            $shouldSendAny = true;
                        }
                    } elseif (!$active && $wasActive) {
                        if ($firstActiveAt === null) { continue; }
                        if (!was_event_sent_to_user_in_window($conn, $to, $tankId, $type, 'ALARME', $firstActiveAt)) {
                            continue;
                        }
                        if (was_event_sent_to_user_in_window($conn, $to, $tankId, $type, 'OK', $firstActiveAt)) {
                            continue;
                        }
                        if (was_recently_sent($conn, $to, $tankId, $type, 30)) {
                            sms_alarm_log("SMS to={$to} tipo={$type} event=OK SKIP_DUP_30s");
                            continue;
                        }

                        $msg = build_alarm_message($tankName, $type, 'OK', $data);
                        $res = $client->send($to, $msg);
                        $status   = $res['ok'] ? 'sent' : 'failed';
                        $respTxt  = $res['ok'] ? (is_string($res['response']) ? $res['response'] : json_encode($res['response']))
                                               : ($res['error'] ?? '');
                        log_sms($conn, $to, $msg, $status, $respTxt, $tankId, $type);
                        sms_alarm_log("SMS to={$to} tipo={$type} event=OK status={$status} resp=" . substr($respTxt, 0, 200));
                        if ($res['ok']) {
                            $sentOk = true;
                            $shouldSendAny = true;
                        }
                    }
                }
            } else {
                sms_alarm_log('SEM DESTINATARIOS (nenhum utilizador com receive_sms_alarms=1 e phone)');
                log_sms($conn, '(sem destinatarios)', '[SKIPPED] sem destinatarios para este alarme',
                        'skipped', 'Nenhum utilizador com receive_sms_alarms=1', $tankId, $type);
            }
        }

        if ($shouldSendAny) {
            sms_alarm_log("ENVIO_REAL tanque={$tankName} tipo={$type}");
        }

        upsert_alarm_state($conn, $tankId, $type, (bool)$active, $sentOk, (bool)$wasActive);
    }
}

/**
 * Constrói a mensagem de SMS (curta, sem acentos, dentro de 160 chars).
 * $event = 'ALARME' | 'OK' (recuperação)
 * $data  = payload do controlador (opcional — usado para meter valor atual em alarmes químicos)
 */
function build_alarm_message(string $tankName, string $type, string $event = 'ALARME', array $data = []): string
{
    // Etiquetas específicas para o evento OK (mais naturais que apenas
    // acrescentar " normalizado" à etiqueta de ALARME).
    $okLabels = [
        'equipment_off'      => 'Equipamento em funcionamento',
        'lora_offline'       => 'LoRa online',
        'perlite_change_due' => 'Perlite substituida',
    ];
    if ($event === 'OK' && isset($okLabels[$type])) {
        $label = $okLabels[$type];
        $useSuffix = false;
    } else {
        $label = alarm_label($type);
        $useSuffix = ($event === 'OK');
    }
    $ts = date('d/m H:i');

    // Contexto numérico para alarmes químicos
    $ctx = '';
    if ($event === 'ALARME') {
        if (in_array($type, ['cloro_baixo', 'cloro_alto'], true)) {
            $v = sms_float_or_null($data['freeChlorine'] ?? null);
            if ($v !== null) { $ctx = ' (' . number_format($v, 2, '.', '') . ')'; }
        } elseif (in_array($type, ['ph_baixo', 'ph_alto'], true)) {
            $v = sms_float_or_null($data['pH'] ?? null);
            if ($v !== null) { $ctx = ' (' . number_format($v, 2, '.', '') . ')'; }
        }
    }

    $prefix = $event === 'OK' ? '[OK]' : '[ALARME]';
    $suffix = $useSuffix ? ' normalizado' : '';

    $strip = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a',
        'é'=>'e','ê'=>'e',
        'í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o',
        'ú'=>'u',
        'ç'=>'c',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A',
        'É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C',
    ];
    $safe     = strtr($label,    $strip);
    $tankSafe = strtr($tankName, $strip);

    $msg = "{$prefix} {$tankSafe}: {$safe}{$suffix}{$ctx} ({$ts})";
    if (strlen($msg) > 160) { $msg = substr($msg, 0, 157) . '...'; }
    return $msg;
}

/**
 * Processa alarmes dos dispositivos LoRaWAN (osmoses, etc.).
 *
 * Estado guardado em controller_alarm_state usando tank_id = -device_id
 * (negativo para distinguir de tanques). Dois tipos de alarme por dispositivo:
 *   - lora_offline:  status != 'On'    (link LoRa perdido / timeout)
 *   - equipment_off: equipment_status == 'Off' (equipamento desligado)
 *
 * Envia SMS em ambas as transições (entrada em alarme e recuperação).
 * Chamado no fim de scripts/check_lorawan_status.php.
 */
function process_lora_alarms(mysqli $conn): void
{
    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        return;
    }

    $res = $conn->query("SELECT id, name, status, equipment_status FROM lorawan_devices");
    if (!$res) { return; }
    $devices = $res->fetch_all(MYSQLI_ASSOC);
    if (empty($devices)) { return; }

    $recipients = null;
    $client     = null;

    foreach ($devices as $dev) {
        $devId    = (int)$dev['id'];
        $devName  = (string)$dev['name'];
        $stateKey = -$devId; // convenção: id negativo em controller_alarm_state

        // Só considera equipment_off se o LoRa estiver online e o valor for 'Off'.
        // Se o LoRa estiver offline, ignoramos equipment_off (não sabemos o real).
        $loraOffline   = ($dev['status'] !== 'On');
        $equipmentOff  = !$loraOffline
                         && isset($dev['equipment_status'])
                         && $dev['equipment_status'] === 'Off';

        $checks = [
            'lora_offline'  => $loraOffline,
            'equipment_off' => $equipmentOff,
        ];

        sms_alarm_log("lora={$devName} id={$devId} status={$dev['status']} equip={$dev['equipment_status']}");

        foreach ($checks as $type => $active) {
            $prev      = get_alarm_state($conn, $stateKey, $type);
            $wasActive = $prev ? (int)$prev['is_active'] === 1 : false;

            $shouldSend = false;
            $event      = null;
            if ($active && !$wasActive)      { $shouldSend = true; $event = 'ALARME'; }
            elseif (!$active && $wasActive)  { $shouldSend = true; $event = 'OK'; }

            $decision = $shouldSend ? ('SEND(' . $event . ')') : 'NO_CHANGE';
            sms_alarm_log("  lora tipo={$type} active=" . ($active ? '1' : '0')
                . ' was_active=' . ($wasActive ? '1' : '0') . " -> {$decision}");

            $sentOk = false;
            if ($shouldSend) {
                if ($recipients === null) {
                    $recipients = get_sms_recipients($conn);
                    $client     = new TeltonikaSmsClient();
                    sms_alarm_log('destinatarios=' . count($recipients));
                }
                $msg = build_alarm_message($devName, $type, $event);
                if (!empty($recipients)) {
                    foreach ($recipients as $r) {
                        $to = trim((string)$r['phone']);
                        if ($to === '') { continue; }
                        if (!user_wants_alarm($r, $type)) { continue; }
                        // Defesa: se este mesmo SMS foi enviado h\u00e1 poucos segundos
                        // (ex.: cron concorrente antes do flock), n\u00e3o repete.
                        if (was_recently_sent($conn, $to, $stateKey, $type, 30)) {
                            sms_alarm_log("SMS lora to={$to} tipo={$type} SKIP_DUP_30s");
                            continue;
                        }
                        $r2 = $client->send($to, $msg);
                        $status  = $r2['ok'] ? 'sent' : 'failed';
                        $respTxt = $r2['ok'] ? (is_string($r2['response']) ? $r2['response'] : json_encode($r2['response']))
                                             : ($r2['error'] ?? '');
                        log_sms($conn, $to, $msg, $status, $respTxt, $stateKey, $type);
                        sms_alarm_log("SMS lora to={$to} status={$status} resp=" . substr($respTxt, 0, 200));
                        if ($r2['ok']) { $sentOk = true; }
                    }
                } else {
                    sms_alarm_log('SEM DESTINATARIOS (lora)');
                    log_sms($conn, '(sem destinatarios)', $msg,
                            'skipped', 'Nenhum utilizador com receive_sms_alarms=1', $stateKey, $type);
                }
            }

            upsert_alarm_state($conn, $stateKey, $type, (bool)$active, $sentOk, (bool)$wasActive);
        }
    }
}

/**
 * Convenção de chave de estado para filtros (evita colisão com tanks / lora):
 *   tank_id = -(1000000 + filter_id).
 */
function filter_perlite_state_key(int $filterId): int
{
    return -(1000000 + $filterId);
}

/**
 * Processa o alarme "falta 1 dia para trocar perlite" de um filtro específico.
 * Chamado a partir de pools/get_filter_modbus_data.php após a leitura Modbus.
 *
 * Trigger:
 *   - ALARME: remaining_time <= FILTER_PERLITE_ALERT_DAYS (default 1)
 *   - OK    : remaining_time  > FILTER_PERLITE_ALERT_DAYS após ter estado ativo
 */
function process_filter_perlite_alarm(mysqli $conn, int $filterId, string $filterName, $remainingDays): void
{
    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        return;
    }
    $remaining = sms_float_or_null($remainingDays);
    if ($remaining === null) {
        return;
    }
    $threshold = defined('FILTER_PERLITE_ALERT_DAYS') ? (float)FILTER_PERLITE_ALERT_DAYS : 1.0;

    $type      = 'perlite_change_due';
    $stateKey  = filter_perlite_state_key($filterId);
    $active    = ($remaining <= $threshold);

    $prev      = get_alarm_state($conn, $stateKey, $type);
    $wasActive = $prev ? (int)$prev['is_active'] === 1 : false;

    $shouldSend = false;
    $event      = null;
    if ($active && !$wasActive)      { $shouldSend = true; $event = 'ALARME'; }
    elseif (!$active && $wasActive)  { $shouldSend = true; $event = 'OK'; }

    sms_alarm_log("filtro={$filterName} id={$filterId} restantes={$remaining}d thr={$threshold}d"
        . ' active=' . ($active ? '1' : '0') . ' was=' . ($wasActive ? '1' : '0')
        . ' -> ' . ($shouldSend ? "SEND({$event})" : 'NO_CHANGE'));

    $sentOk = false;
    if ($shouldSend) {
        $recipients = get_sms_recipients($conn);
        if (!empty($recipients)) {
            $client = new TeltonikaSmsClient();
            $msg = build_alarm_message($filterName, $type, $event);
            foreach ($recipients as $r) {
                $to = trim((string)$r['phone']);
                if ($to === '') { continue; }
                if (!user_wants_alarm($r, $type)) { continue; }
                // Defesa concorrente / on-view: várias abas / recargas podem
                // disparar em segundos — não repetir o mesmo SMS em 30s.
                if (was_recently_sent($conn, $to, $stateKey, $type, 30)) {
                    sms_alarm_log("SMS perlite to={$to} filtro={$filterName} SKIP_DUP_30s");
                    continue;
                }
                $r2 = $client->send($to, $msg);
                $status  = $r2['ok'] ? 'sent' : 'failed';
                $respTxt = $r2['ok'] ? (is_string($r2['response']) ? $r2['response'] : json_encode($r2['response']))
                                     : ($r2['error'] ?? '');
                log_sms($conn, $to, $msg, $status, $respTxt, $stateKey, $type);
                sms_alarm_log("SMS perlite to={$to} filtro={$filterName} event={$event} status={$status} resp=" . substr($respTxt, 0, 200));
                if ($r2['ok']) { $sentOk = true; }
            }
        } else {
            sms_alarm_log('SEM DESTINATARIOS (perlite)');
            log_sms($conn, '(sem destinatarios)', "[SKIPPED] sem destinatarios perlite {$filterName}",
                    'skipped', 'Nenhum utilizador com receive_sms_alarms=1', $stateKey, $type);
        }
    }

    upsert_alarm_state($conn, $stateKey, $type, $active, $sentOk, $wasActive);
}