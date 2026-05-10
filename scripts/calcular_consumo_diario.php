<?php
/**
 * calcular_consumo_diario.php
 *
 * Corre diariamente às 09:00 via Windows Task Scheduler.
 * Para cada tanque com Qmax configurado, calcula o integral de dosagem
 * do período das 09:00 do dia anterior até às 09:00 de hoje,
 * e guarda a estimativa de consumo de hipoclorito na tabela hipoclorito_diario.
 *
 * Uso: php -f "C:\xampp\htdocs\work_log\scripts\calcular_consumo_diario.php"
 */

require_once dirname(__DIR__) . '/core.php';

// ─── Logger ──────────────────────────────────────────────────────────────────
$logDir  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . DIRECTORY_SEPARATOR . 'consumo_diario_' . date('Y-m-d') . '.log';

function clog(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
// ─────────────────────────────────────────────────────────────────────────────

// Garante tabela de consumo diário
$conn->query("CREATE TABLE IF NOT EXISTS `hipoclorito_diario` (
    `id`                 int(11) NOT NULL AUTO_INCREMENT,
    `tank_id`            int(11) NOT NULL,
    `data_referencia`    date NOT NULL COMMENT 'Dia a que se refere (9:00 desse dia -> 9:00 dia seguinte)',
    `hora_inicio`        datetime NOT NULL,
    `hora_fim`           datetime NOT NULL,
    `integral_dosagem`   float NOT NULL,
    `qmax_lh`            float NOT NULL,
    `consumo_estimado_l` float NOT NULL,
    `n_registos`         int(11) NOT NULL DEFAULT 0,
    `created_at`         timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_tank_data` (`tank_id`, `data_referencia`),
    KEY `idx_tank_id` (`tank_id`),
    KEY `idx_data_referencia` (`data_referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Período: ontem 09:00 → hoje 09:00
$horaFim    = date('Y-m-d') . ' 09:00:00';
$horaInicio = date('Y-m-d', strtotime('-1 day')) . ' 09:00:00';
$dataRef    = date('Y-m-d', strtotime('-1 day')); // o "dia" é o de início

clog("=== INÍCIO calcular_consumo_diario ===");
clog("Período: {$horaInicio} → {$horaFim} | data_referencia: {$dataRef}");

// Buscar todos os tanques com Qmax configurado
$tanks_stmt = $conn->query("
    SELECT t.id, t.name, s.setting_value AS qmax
    FROM tanks t
    INNER JOIN settings s ON s.setting_key = CONCAT('qmax_tank_', t.id)
    WHERE s.setting_value IS NOT NULL AND s.setting_value > 0
");

if (!$tanks_stmt) {
    clog("ERRO: falha ao consultar tanques: " . $conn->error);
    exit(1);
}

$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
clog("Tanques com Qmax: " . count($tanks));

if (count($tanks) === 0) {
    clog("Nenhum tanque com Qmax configurado. A terminar.");
    exit(0);
}

// Prepara INSERT com ON DUPLICATE KEY UPDATE (re-calcula se correr 2× no mesmo dia)
$stmt_insert = $conn->prepare("
    INSERT INTO hipoclorito_diario
        (tank_id, data_referencia, hora_inicio, hora_fim, integral_dosagem, qmax_lh, consumo_estimado_l, n_registos)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        hora_inicio        = VALUES(hora_inicio),
        hora_fim           = VALUES(hora_fim),
        integral_dosagem   = VALUES(integral_dosagem),
        qmax_lh            = VALUES(qmax_lh),
        consumo_estimado_l = VALUES(consumo_estimado_l),
        n_registos         = VALUES(n_registos)
");

if (!$stmt_insert) {
    clog("ERRO: falha ao preparar INSERT: " . $conn->error);
    exit(1);
}

// Buscar histórico de dosagem
$stmt_hist = $conn->prepare("
    SELECT log_datetime, cl_controller_state
    FROM controller_history
    WHERE tank_id = ?
      AND log_datetime >= ?
      AND log_datetime <= ?
      AND cl_controller_state IS NOT NULL
    ORDER BY log_datetime ASC
");

foreach ($tanks as $tank) {
    $tank_id = (int)$tank['id'];
    $qmax    = (float)$tank['qmax'];
    $name    = $tank['name'];

    $stmt_hist->bind_param("iss", $tank_id, $horaInicio, $horaFim);
    $stmt_hist->execute();
    $records = $stmt_hist->get_result()->fetch_all(MYSQLI_ASSOC);
    $n = count($records);

    if ($n < 2) {
        clog("SKIP tanque={$name} id={$tank_id} — apenas {$n} registos, impossível calcular integral");
        continue;
    }

    // Integral trapezoidal de cl_controller_state (%) ao longo do tempo (horas)
    $integral = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $tA = strtotime($records[$i-1]['log_datetime']);
        $tB = strtotime($records[$i]['log_datetime']);
        $dA = (float)$records[$i-1]['cl_controller_state'];
        $dB = (float)$records[$i]['cl_controller_state'];
        $dtH = ($tB - $tA) / 3600.0;
        $integral += ($dA + $dB) / 2.0 * $dtH;
    }

    // Consumo estimado = (Qmax / 100) × integral
    $consumo = ($qmax / 100.0) * $integral;

    // Hora real de início e fim dos dados efectivos
    $horaInicioEfetiva = $records[0]['log_datetime'];
    $horaFimEfetiva    = $records[$n - 1]['log_datetime'];

    $stmt_insert->bind_param(
        "isssdddi",
        $tank_id, $dataRef, $horaInicioEfetiva, $horaFimEfetiva,
        $integral, $qmax, $consumo, $n
    );
    $ok = $stmt_insert->execute();

    if ($ok) {
        clog(sprintf(
            "OK tanque=%s id=%d | integral=%.2f %%-h | Qmax=%.2f L/h | consumo=%.1f L | registos=%d",
            $name, $tank_id, $integral, $qmax, $consumo, $n
        ));
    } else {
        clog("ERRO INSERT tanque={$name} id={$tank_id}: " . $stmt_insert->error);
    }
}

$stmt_hist->close();
$stmt_insert->close();
$conn->close();
clog("=== FIM calcular_consumo_diario ===");
