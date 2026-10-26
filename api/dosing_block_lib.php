<?php

function ensure_dosing_block_table(mysqli $conn): void
{
    if (!$conn->query("CREATE TABLE IF NOT EXISTS controller_dosing_block (
        tank_id INT NOT NULL PRIMARY KEY,
        mode VARCHAR(8) NOT NULL DEFAULT 'inherit',
        chlorine_delta DOUBLE NULL,
        ph_delta DOUBLE NULL,
        previous_chlorine DOUBLE NULL,
        previous_ph DOUBLE NULL,
        pending TINYINT NOT NULL DEFAULT 0,
        blocked TINYINT NOT NULL DEFAULT 0,
        reason TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
        throw new RuntimeException('Não foi possível preparar a configuração de bloqueio de dosagem.');
    }
}

function dosing_block_rows(mysqli $conn): array
{
    $result = $conn->query('SELECT * FROM controller_dosing_block');
    if (!$result) {
        throw new RuntimeException('Não foi possível consultar o bloqueio de dosagem.');
    }
    $rows = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $rows[(int)$row['tank_id']] = $row;
    }
    return $rows;
}

function dosing_block_config(array $rows, int $tankId): array
{
    $global = $rows[0] ?? ['mode' => 'off', 'chlorine_delta' => null, 'ph_delta' => null];
    $local = $rows[$tankId] ?? ['mode' => 'inherit'];
    return $local['mode'] === 'inherit' ? $global : $local;
}

function dosing_valid_reading($value, float $maximum): ?float
{
    if (!is_scalar($value) || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) && $number >= 0 && $number <= $maximum ? $number : null;
}

function dosing_discrepancies(array $state, array $data, array $config): array
{
    $reasons = [];
    foreach (['chlorine' => ['freeChlorine', PHP_FLOAT_MAX], 'ph' => ['pH', 14.0]] as $key => [$tag, $max]) {
        $current = dosing_valid_reading($data[$tag] ?? null, $max);
        $previous = $state['previous_' . $key] ?? null;
        $limit = $config[$key . '_delta'] ?? null;
        if ($current !== null && $previous !== null && $limit !== null
            && abs($current - (float)$previous) > (float)$limit) {
            $reasons[$key] = ['previous' => (float)$previous, 'current' => $current, 'limit' => (float)$limit];
        }
    }
    return $reasons;
}

function dosing_lock(mysqli $conn, int $tankId): void
{
    $name = 'worklog_dosing_' . $tankId;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ((int)$row['acquired'] !== 1) {
        throw new RuntimeException('Controlador ocupado. Tente novamente.');
    }
}

function dosing_unlock(mysqli $conn, int $tankId): void
{
    $name = 'worklog_dosing_' . $tankId;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->close();
}

function reset_dosing_block(mysqli $conn, int $tankId): void
{
    $stmt = $conn->prepare('UPDATE controller_dosing_block SET pending=0, blocked=0,
        previous_chlorine=NULL, previous_ph=NULL, reason=NULL WHERE tank_id=?');
    $stmt->bind_param('i', $tankId);
    if (!$stmt->execute()) {
        throw new RuntimeException('O reset foi enviado, mas não foi possível limpar o estado automático.');
    }
    $stmt->close();
}

function latch_manual_dosing_block(mysqli $conn, int $tankId, int $userId): void
{
    $reason = json_encode(['manual_user_id' => $userId]);
    $stmt = $conn->prepare('INSERT INTO controller_dosing_block (tank_id,pending,blocked,reason)
        VALUES (?,1,1,?) ON DUPLICATE KEY UPDATE pending=1,blocked=1,reason=VALUES(reason)');
    $stmt->bind_param('is', $tankId, $reason);
    if (!$stmt->execute()) {
        throw new RuntimeException('Não foi possível guardar o bloqueio manual.');
    }
    $stmt->close();
}

function process_dosing_block(mysqli $conn, array $pool, array $data): bool
{
    $tankId = (int)$pool['id'];
    dosing_lock($conn, $tankId);
    try {
        $rows = dosing_block_rows($conn);
        $config = dosing_block_config($rows, $tankId);
        $state = $rows[$tankId] ?? [];
        if (!empty($state['blocked'])) {
            if (empty($state['pending']) && dosing_valid_reading($data['relayBlocked'] ?? null, 1.0) === 1.0) {
                return true;
            }
            // Reassert a latched block if the Arduino restarted or feedback was lost.
            $state['pending'] = 1;
        }
        if ($config['mode'] !== 'on' && empty($state['pending'])) {
            return false;
        }
        $reasons = dosing_discrepancies($state, $data, $config);
        $pending = !empty($state['pending']) || !empty($reasons) ? 1 : 0;
        $reason = !empty($state['pending']) ? $state['reason'] : json_encode($reasons, JSON_UNESCAPED_UNICODE);
        $chlorine = dosing_valid_reading($data['freeChlorine'] ?? null, PHP_FLOAT_MAX);
        $ph = dosing_valid_reading($data['pH'] ?? null, 14.0);
        // Invalid readings break the comparison chain rather than becoming a baseline.
        $stmt = $conn->prepare("INSERT INTO controller_dosing_block
            (tank_id, previous_chlorine, previous_ph, pending, reason) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE previous_chlorine=VALUES(previous_chlorine),
                previous_ph=VALUES(previous_ph), pending=VALUES(pending), reason=VALUES(reason)");
        $stmt->bind_param('iddis', $tankId, $chlorine, $ph, $pending, $reason);
        if (!$stmt->execute()) {
            throw new RuntimeException('Não foi possível guardar o estado de bloqueio automático.');
        }
        $stmt->close();
        if (!$pending) {
            return false;
        }
        if (filter_var($pool['controller_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('IP inválido para o bloqueio automático.');
        }
        $curl = curl_init('http://' . $pool['controller_ip'] . '/relay/on');
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar o bloqueio automático.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false
        ]);
        $response = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        // Match the firmware acknowledgement, not just an unrelated HTTP 200 page.
        $accepted = $response !== false && $http === 200 && trim($response) === 'Rele ligado';
        $description = sprintf('Bloqueio automático %s | piscina_id=%d | piscina=%s | controlador=%s | discrepancia=%s | http=%d | erro=%s',
            $accepted ? 'SUCESSO' : 'FALHA', $tankId, $pool['name'], $pool['controller_ip'], $reason, $http, $error);
        if (function_exists('file_log')) {
            file_log('DOSING_AUTO_BLOCK | ' . $description);
        } else {
            error_log('DOSING_AUTO_BLOCK | ' . $description);
        }
        if (!$accepted) {
            throw new RuntimeException('Bloqueio automático não confirmado; será repetido no próximo ciclo.');
        }
        $stmt = $conn->prepare('UPDATE controller_dosing_block SET pending=0, blocked=1 WHERE tank_id=?');
        $stmt->bind_param('i', $tankId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Bloqueio enviado, mas não foi possível confirmar o estado guardado.');
        }
        $stmt->close();
        return true;
    } finally {
        dosing_unlock($conn, $tankId);
    }
}
