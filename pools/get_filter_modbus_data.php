<?php
// Garantir que erros PHP não "vazam" para o JSON (display_errors ligado no XAMPP dev)
ini_set('display_errors', '0');
ob_start();

require_once '../core.php';

// Limpar qualquer output gerado pelo core.php (warnings, notices, etc.)
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso nao autorizado']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'ID do filtro invalido']);
    exit;
}

$filter_id = (int) $_GET['id'];

$stmt = $conn->prepare(
    "SELECT id, name, ip_address, slave_id FROM filter_equipment WHERE id = ? LIMIT 1"
);
if ($stmt === false) {
    echo json_encode(['error' => 'Tabela filter_equipment nao encontrada. Execute o SQL de criacao.']);
    exit;
}
$stmt->bind_param('i', $filter_id);
$stmt->execute();
$filter = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$filter) {
    echo json_encode(['error' => 'Filtro nao encontrado']);
    exit;
}

// ---- Mapeamento de registos Modbus (notacao 4x, 1-indexed) ----
//   400079-400080 → Pin           (float32 big-endian)
//   400081-400082 → Pout          (float32 big-endian)
//   400083-400084 → Delta P       (float32 big-endian)
//   400085        → Fluxo         (uint16)
//   400087        → Alarme        (uint16)
//   400089        → Estado Bomba  (uint16: 0=parado, 1-99=precoat, 100=filtracao)
// Endereco PDU 0-indexed: 78 … 89  →  12 registos

const MODBUS_START = 71;  // reg 40072 (estado do filtro)
const MODBUS_COUNT = 35;  // cobre 40072–40106 (total 35 registos)
const MODBUS_PORT  = 502;
const MODBUS_TIMEOUT = 5;
const PRECOAT_COIL_ADDRESS = 3;
const MODBUS_LOG_FILE = __DIR__ . '/../login_log.txt';

function log_modbus_event(string $stage, array $context = []): void {
    $entry = [
        'ts' => date('c'),
        'stage' => $stage,
        'context' => $context,
    ];

    $line = '[MODBUS] ' . json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(MODBUS_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function read_socket_bytes($sock, int $expected_len, float $deadline): array {
    $buffer = '';
    $last_meta = null;

    while (strlen($buffer) < $expected_len && microtime(true) < $deadline) {
        $chunk = fread($sock, $expected_len - strlen($buffer));

        if ($chunk === false) {
            break;
        }

        if ($chunk === '') {
            $last_meta = stream_get_meta_data($sock);
            if (!empty($last_meta['timed_out'])) {
                continue;
            }
            break;
        }

        $buffer .= $chunk;
    }

    return [
        'buffer' => $buffer,
        'meta'   => $last_meta,
    ];
}

function modbus_tcp_read_holding(string $ip, int $slave_id, int $start, int $count,
                                  int $port = 502, int $timeout = 3): array {
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        return [
            'error' => "Sem ligacao TCP ao dispositivo ($errstr)",
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'errno' => $errno,
            ],
        ];
    }
    stream_set_timeout($sock, $timeout);

    $tid     = mt_rand(1, 0xFFFF);
    $request = pack('nnnCCnn', $tid, 0x0000, 6, $slave_id & 0xFF, 0x03, $start, $count);
    fwrite($sock, $request);

    $deadline = microtime(true) + $timeout;

    // Ler cabeçalho MBAP (6 bytes)
    $header_read = read_socket_bytes($sock, 6, $deadline);
    $raw = $header_read['buffer'];
    if (strlen($raw) < 6) {
        fclose($sock);
        return [
            'error' => 'Cabecalho MBAP incompleto',
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'bytes_received' => strlen($raw),
                'bytes_expected' => 6,
                'timed_out' => (bool) ($header_read['meta']['timed_out'] ?? false),
            ],
        ];
    }

    $hdr      = unpack('ntid/nproto/nlength', $raw);
    $body_len = (int) $hdr['length'];

    // Ler corpo PDU
    $body_read = read_socket_bytes($sock, $body_len, $deadline);
    $body = $body_read['buffer'];
    fclose($sock);

    if (strlen($body) < $body_len) {
        return [
            'error' => 'Dados PDU incompletos',
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'bytes_received' => strlen($body),
                'bytes_expected' => $body_len,
                'timed_out' => (bool) ($body_read['meta']['timed_out'] ?? false),
            ],
        ];
    }
    if (strlen($body) < 3)         return ['error' => 'Resposta demasiado curta'];

    $meta = unpack('Cunit/Cfc/Cbytes', substr($body, 0, 3));

    // Exceção Modbus
    if ($meta['fc'] & 0x80) {
        $code = strlen($body) > 3 ? ord($body[3]) : 0;
        return ['error' => "Excecao Modbus: codigo $code"];
    }

    $registers = [];
    $data_raw  = substr($body, 3);
    $num_regs  = (int) ($meta['bytes'] / 2);
    for ($i = 0; $i < $num_regs; $i++) {
        $w = unpack('n', substr($data_raw, $i * 2, 2));
        $registers[] = (int) $w[1];
    }

    return ['registers' => $registers];
}

function modbus_tcp_read_coils(string $ip, int $slave_id, int $start, int $count,
                               int $port = 502, int $timeout = 3): array {
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) {
        return [
            'error' => "Sem ligacao TCP ao dispositivo ($errstr)",
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'errno' => $errno,
            ],
        ];
    }
    stream_set_timeout($sock, $timeout);

    $tid     = mt_rand(1, 0xFFFF);
    $request = pack('nnnCCnn', $tid, 0x0000, 6, $slave_id & 0xFF, 0x01, $start, $count);
    fwrite($sock, $request);

    $deadline = microtime(true) + $timeout;

    $header_read = read_socket_bytes($sock, 6, $deadline);
    $raw = $header_read['buffer'];
    if (strlen($raw) < 6) {
        fclose($sock);
        return [
            'error' => 'Cabecalho MBAP incompleto',
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'bytes_received' => strlen($raw),
                'bytes_expected' => 6,
                'timed_out' => (bool) ($header_read['meta']['timed_out'] ?? false),
            ],
        ];
    }

    $hdr      = unpack('ntid/nproto/nlength', $raw);
    $body_len = (int) $hdr['length'];

    $body_read = read_socket_bytes($sock, $body_len, $deadline);
    $body = $body_read['buffer'];
    fclose($sock);

    if (strlen($body) < $body_len) {
        return [
            'error' => 'Dados PDU incompletos',
            'details' => [
                'ip' => $ip,
                'port' => $port,
                'slave_id' => $slave_id,
                'timeout_s' => $timeout,
                'bytes_received' => strlen($body),
                'bytes_expected' => $body_len,
                'timed_out' => (bool) ($body_read['meta']['timed_out'] ?? false),
            ],
        ];
    }
    if (strlen($body) < 3)         return ['error' => 'Resposta demasiado curta'];

    $meta = unpack('Cunit/Cfc/Cbytes', substr($body, 0, 3));

    if ($meta['fc'] & 0x80) {
        $code = strlen($body) > 3 ? ord($body[3]) : 0;
        return ['error' => "Excecao Modbus: codigo $code"];
    }

    $coil_bits = [];
    $data_raw  = substr($body, 3);
    for ($i = 0; $i < $count; $i++) {
        $byte_index = intdiv($i, 8);
        if ($byte_index >= strlen($data_raw)) {
            $coil_bits[] = null;
            continue;
        }
        $byte = ord($data_raw[$byte_index]);
        $bit  = ($byte >> ($i % 8)) & 0x01;
        $coil_bits[] = $bit;
    }

    return ['coils' => $coil_bits];
}

function regs_to_float32(int $hi, int $lo): ?float {
    // Ordem de palavras: big-endian (ABCD)
    $bytes = pack('nn', $hi, $lo);
    $f     = unpack('G', $bytes);
    $val   = $f[1];
    return (is_nan($val) || is_infinite($val)) ? null : round($val, 4);
}

$result = modbus_tcp_read_holding(
    $filter['ip_address'],
    (int) $filter['slave_id'],
    MODBUS_START,
    MODBUS_COUNT,
    MODBUS_PORT,
    MODBUS_TIMEOUT
);

if (isset($result['error'])) {
    $error_payload = [
        'error'       => $result['error'],
        'filter_id'   => $filter_id,
        'filter_name' => $filter['name'],
    ];

    if (isset($result['details'])) {
        $error_payload['details'] = $result['details'];
    }

    log_modbus_event('holding_read_error', [
        'filter_id' => $filter_id,
        'filter_name' => $filter['name'],
        'ip_address' => $filter['ip_address'],
        'slave_id' => (int) $filter['slave_id'],
        'error' => $result['error'],
        'details' => $result['details'] ?? null,
    ]);

    echo json_encode($error_payload);
    exit;
}

$regs = $result['registers'];
if (count($regs) < 34) {
    log_modbus_event('holding_register_count_error', [
        'filter_id' => $filter_id,
        'filter_name' => $filter['name'],
        'ip_address' => $filter['ip_address'],
        'slave_id' => (int) $filter['slave_id'],
        'register_count' => count($regs),
        'register_count_expected_min' => 34,
    ]);

    echo json_encode(['error' => 'Registos Modbus insuficientes na resposta', 'filter_id' => $filter_id]);
    exit;
}

//  Indices relativos a PDU start=71 (registo 40072)
//  Indice 0     → addr 71     → Estado do filtro  (registo 40072, bits de estado)
//  Indice 1     → addr 72     → Fins de curso     (registo 40073, bits válvulas)
//  Indice 7,8   → addr 78,79  → Pin               (registos 40079-40080, float32)
//  Indice 9,10  → addr 80,81  → Pout              (registos 40081-40082, float32)
//  Indice 11,12 → addr 82,83  → Delta P           (registos 40083-40084, float32)
//  Indice 13    → addr 84     → Fluxo             (registo  40085, uint16)
//  Indice 15    → addr 86     → Alarme            (registo  40087, uint16)
//  Indice 17,18 → addr 88,89  → Velocidade Bomba  (registos 40089-40090, float32)

// --- Registo 40072: bits de estado do filtro (ADRESSLIST Rev.3) ---
$status_word         = $regs[0];
$filter_off          = (bool)(($status_word >> 0) & 1); // bit 0: Filtro em Serviço/OFF
$filter_interruption = (bool)(($status_word >> 1) & 1); // bit 1: Interrupção
$filter_precoat      = (bool)(($status_word >> 2) & 1); // bit 2: Pré-coat
$filter_in_service   = (bool)(($status_word >> 3) & 1); // bit 3: Em Filtração
$filter_fill_drain   = (bool)(($status_word >> 4) & 1); // bit 4: Enchimento/Drenagem
$filter_bump         = (bool)(($status_word >> 5) & 1); // bit 5: Bump
$pump1_start         = (bool)(($status_word >> 6) & 1); // bit 6: Arranque Bomba 1 (VFD)
$pump2_start         = (bool)(($status_word >> 7) & 1); // bit 7: Arranque Bomba 2 (VFD)

// --- Registo 40073: fins de curso das válvulas ---
$ls_word          = $regs[1];
$effluent_open    = (bool)(($ls_word >> 0) & 1); // bit 0: Válvula Efluente aberta
$effluent_closed  = (bool)(($ls_word >> 1) & 1); // bit 1: Válvula Efluente fechada
$precoat_open     = (bool)(($ls_word >> 2) & 1); // bit 2: Válvula Pré-coat aberta
$precoat_closed   = (bool)(($ls_word >> 3) & 1); // bit 3: Válvula Pré-coat fechada
$influent_open    = (bool)(($ls_word >> 4) & 1); // bit 4: Válvula Influente aberta
$influent_closed  = (bool)(($ls_word >> 5) & 1); // bit 5: Válvula Influente fechada

// --- Estado do filtro derivado dos bits ---
if ($filter_interruption) {
    $filter_state = 'Interrompido';
} elseif ($filter_precoat) {
    $filter_state = 'Pré-coat';
} elseif ($filter_in_service) {
    $filter_state = 'Em Filtração';
} elseif ($filter_fill_drain) {
    $filter_state = 'Enchimento/Drenagem';
} elseif ($filter_bump) {
    $filter_state = 'Bump';
} elseif ($filter_in_service) {
    $filter_state = 'Em Filtração';
} elseif ($pump1_start || $pump2_start) {
    $filter_state = 'Bomba a Arrancar';
} elseif (!$filter_off) {
    $filter_state = 'Parado';
} else {
    $filter_state = 'Inativo';
}

//  Indice 5,6   → 40077,78  → Ar Pneumático      (float32, 0-10 bar)
//  Indice 7,8   → 40079,80  → Pin                (float32, 0-2.50 bar)
//  Indice 9,10  → 40081,82  → Pout               (float32, 0-2.50 bar)
//  Indice 11,12 → 40083,84  → ΔP                 (float32, 0-99.99 bar)
//  Indice 13,14 → 40085,86  → Caudal filtrado     (float32, 0-300 m³/h)
//  Indice 15,16 → 40087,88  → Setpoint VFD ext.   (float32, 0-100%)
//  Indice 17,18 → 40089,90  → Setpoint VFD Bomba1 (float32, 0-100%)
//  Indice 19,20 → 40091,92  → Horas filtro        (float32, 0-999999.9)
//  Indice 21,22 → 40093,94  → Intervalo perlita   (float32)
//  Indice 23,24 → 40095,96  → Tempo restante [d]  (float32)
//  Indice 25,26 → 40097,98  → Ciclos perlita      (float32)
//  Indice 27,28 → 40099,100 → Setpoint VFD Bomba2 (float32, 0-100%)
//  Indice 29,30 → 40101,102 → Horas Bomba 1       (float32)
//  Indice 31,32 → 40103,104 → Horas Bomba 2       (float32)
//  Indice 33    → 40105     → Feedback bits (W)   (bits bomba 1/2 estado e falha)
$pneumatic_air    = regs_to_float32($regs[5],  $regs[6]);
$pin              = regs_to_float32($regs[7],  $regs[8]);
$pout             = regs_to_float32($regs[9],  $regs[10]);
$delta_p          = regs_to_float32($regs[11], $regs[12]);
$flow             = regs_to_float32($regs[13], $regs[14]);
$setpoint_vfd_ext = regs_to_float32($regs[15], $regs[16]);
$setpoint_vfd_p1  = regs_to_float32($regs[17], $regs[18]);
$op_hours_filter  = regs_to_float32($regs[19], $regs[20]);
$interval_perlite = regs_to_float32($regs[21], $regs[22]);
$remaining_time   = regs_to_float32($regs[23], $regs[24]);
$charging_cycles  = regs_to_float32($regs[25], $regs[26]);
$setpoint_vfd_p2  = regs_to_float32($regs[27], $regs[28]);
$op_hours_pump1   = regs_to_float32($regs[29], $regs[30]);
$op_hours_pump2   = regs_to_float32($regs[31], $regs[32]);
$feedback_word    = isset($regs[33]) ? $regs[33] : 0;

$coil_result = modbus_tcp_read_coils(
    $filter['ip_address'],
    (int) $filter['slave_id'],
    PRECOAT_COIL_ADDRESS,
    1,
    MODBUS_PORT,
    MODBUS_TIMEOUT
);

$precoat_coil = null;
if (!isset($coil_result['error']) && isset($coil_result['coils'][0])) {
    $precoat_coil = (int) $coil_result['coils'][0];
}

if (isset($coil_result['error'])) {
    log_modbus_event('coil_read_error', [
        'filter_id' => $filter_id,
        'filter_name' => $filter['name'],
        'ip_address' => $filter['ip_address'],
        'slave_id' => (int) $filter['slave_id'],
        'coil_address' => PRECOAT_COIL_ADDRESS,
        'error' => $coil_result['error'],
        'details' => $coil_result['details'] ?? null,
    ]);
}

$precoat_active = ($precoat_coil === 1) || $filter_precoat;

// --- Registo 40105: feedback das bombas (retrosinais) ---
$ext_release   = (bool)(($feedback_word >> 0) & 1); // bit 0: Autorização externa
$pump1_running = (bool)(($feedback_word >> 1) & 1); // bit 1: Bomba 1 em serviço
$pump1_fault   = (bool)(($feedback_word >> 2) & 1); // bit 2: Falha Bomba 1
$pump2_running = (bool)(($feedback_word >> 3) & 1); // bit 3: Bomba 2 em serviço
$pump2_fault   = (bool)(($feedback_word >> 4) & 1); // bit 4: Falha Bomba 2

$active_fault = $pump1_fault || $pump2_fault;
$is_running   = $pump1_running || $pump2_running;

echo json_encode([
    'filter_id'       => $filter_id,
    'filter_name'     => $filter['name'],
    'ip_address'      => $filter['ip_address'],
    'slave_id'        => $filter['slave_id'],
    // Estado principal
    'filter_state'    => $filter_state,
    'status_bits'     => [
        'filter_off'          => $filter_off,
        'filter_interruption' => $filter_interruption,
        'filter_precoat'      => $filter_precoat,
        'filter_in_service'   => $filter_in_service,
        'filter_fill_drain'   => $filter_fill_drain,
        'filter_bump'         => $filter_bump,
        'pump1_start'         => $pump1_start,
        'pump2_start'         => $pump2_start,
    ],
    // Fins de curso das válvulas
    'limit_switches'  => [
        'effluent_open'   => $effluent_open,
        'effluent_closed' => $effluent_closed,
        'precoat_open'    => $precoat_open,
        'precoat_closed'  => $precoat_closed,
        'influent_open'   => $influent_open,
        'influent_closed' => $influent_closed,
    ],
    // Feedback das bombas (retrosinais - reg 40105)
    'feedback_bits'   => [
        'ext_release'   => $ext_release,
        'pump1_running' => $pump1_running,
        'pump1_fault'   => $pump1_fault,
        'pump2_running' => $pump2_running,
        'pump2_fault'   => $pump2_fault,
    ],
    // Medições analógicas
    'pneumatic_air'   => $pneumatic_air,
    'pin'             => $pin,
    'pout'            => $pout,
    'delta_p'         => $delta_p,
    'flow'            => $flow,
    // Setpoints VFD
    'setpoint_vfd_ext' => $setpoint_vfd_ext,
    'setpoint_vfd_p1'  => $setpoint_vfd_p1,
    'setpoint_vfd_p2'  => $setpoint_vfd_p2,
    // Horas de operação
    'op_hours_filter' => $op_hours_filter,
    'op_hours_pump1'  => $op_hours_pump1,
    'op_hours_pump2'  => $op_hours_pump2,
    // Perlita
    'interval_perlite' => $interval_perlite,
    'remaining_time'   => $remaining_time,
    'charging_cycles'  => $charging_cycles,
    // Compatibilidade
    'pump_state'      => $setpoint_vfd_p1,
    'precoat_coil'    => $precoat_coil,
    'precoat_active'  => $precoat_active,
    'isRunning'       => $is_running,
    'activeFault'     => $active_fault,
]);
