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

const MODBUS_START = 78;
const MODBUS_COUNT = 13;
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
if (count($regs) < 13) {
    log_modbus_event('holding_register_count_error', [
        'filter_id' => $filter_id,
        'filter_name' => $filter['name'],
        'ip_address' => $filter['ip_address'],
        'slave_id' => (int) $filter['slave_id'],
        'register_count' => count($regs),
        'register_count_expected_min' => 13,
    ]);

    echo json_encode(['error' => 'Registos Modbus insuficientes na resposta', 'filter_id' => $filter_id]);
    exit;
}

//  Indices relativos a PDU start=78 (registo 400079)
//  Indice 0,1   → addr 78,79  → Pin             (registos 400079-400080, float32)
//  Indice 2,3   → addr 80,81  → Pout            (registos 400081-400082, float32)
//  Indice 4,5   → addr 82,83  → Delta P         (registos 400083-400084, float32)
//  Indice 6     → addr 84     → Fluxo           (registo  400085, uint16)
//  Indice 8     → addr 86     → Alarme          (registo  400087, uint16)
//  Indice 10    → addr 88     → Velocidade Bomba (registo  400089, uint16 0-100%)
$pin        = regs_to_float32($regs[0], $regs[1]);
$pout       = regs_to_float32($regs[2], $regs[3]);
$delta_p    = regs_to_float32($regs[4], $regs[5]);
$flow       = $regs[6];
$alarm_reg  = $regs[8];
$pump_state = regs_to_float32($regs[10], $regs[11]);  // float32 velocidade bomba (0-100%)

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

$precoat_active = ($precoat_coil === 1);

$active_fault = ($alarm_reg !== 0);
$is_running   = !$active_fault;

echo json_encode([
    'filter_id'   => $filter_id,
    'filter_name' => $filter['name'],
    'ip_address'  => $filter['ip_address'],
    'slave_id'    => $filter['slave_id'],
    'pin'         => $pin,
    'pout'        => $pout,
    'delta_p'     => $delta_p,
    'pump_state'  => $pump_state,
    'precoat_coil' => $precoat_coil,
    'precoat_active' => $precoat_active,
    'alarm_reg'   => $alarm_reg,
    'isRunning'   => $is_running,
    'activeFault' => $active_fault,
]);
