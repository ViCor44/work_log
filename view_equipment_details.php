<?php
require_once 'header.php'; // Inclui o header do seu CMMS (sessão, BD, etc.)
?>

<!-- SCRIPT: Garante que esta página nunca abre dentro de um iframe -->
<script>
    if (window.top !== window.self) {
        window.top.location.href = window.location.href;
    }
</script>

<?php
// --- Validação do ID do Equipamento ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<div class="container mt-4"><div class="alert alert-danger">ID do equipamento inválido ou não fornecido.</div></div>';
    require_once 'footer.php';
    exit;
}
$equipment_id = (int)$_GET['id'];

// --- Ir buscar os dados do equipamento, incluindo IP e Slave ID ---
$stmt_equip = $conn->prepare("SELECT name, ip_address, slave_id FROM remote_equipment WHERE id = ?");
$stmt_equip->bind_param("i", $equipment_id);
$stmt_equip->execute();
$equipment = $stmt_equip->get_result()->fetch_assoc();
$stmt_equip->close();

if (!$equipment) {
    echo '<div class="container mt-4"><div class="alert alert-danger">Equipamento não encontrado.</div></div>';
    require_once 'footer.php';
    exit;
}
$equipment_name = $equipment['name'];

// =================================================================================
// <<< LÓGICA DE CÁLCULO E FUNÇÕES AUXILIARES >>>
// =================================================================================

function formatDuration($seconds) { if ($seconds < 0) return '0s'; $h = floor($seconds / 3600); $m = floor(($seconds % 3600) / 60); $s = $seconds % 60; return sprintf('%dh %02dm %02ds', $h, $m, $s); }
function extractFaultCode($details_string) { if (preg_match('/(0x[0-9a-fA-F]+)/', $details_string, $matches)) { return $matches[1]; } return null; }
function getFaultDescriptionFromHex($faultHex) { if ($faultHex === null) return 'N/A'; $faultMap = [ 0 => 'Sem falha', 1 => 'Proteção Inibida', 2 => 'Falha Interna', 3 => 'Curto-circuito/Sobrecorrente', 4 => 'Inversão de Fase', 5 => 'Falha Comunicação Linha', 6 => 'Falha Externa', 7 => 'Arranque Excessivo', 8 => 'Falha de Tensão', 9 => 'Falha de Fase', 10 => 'Sobreaquecimento', 11 => 'Rotor Bloqueado', 12 => 'Sobrecarga Térmica', 13 => 'Falha Frequência', 14 => 'Sub-carga Motor', 15 => 'Falha EEPROM', 16 => 'Sobrecarga Corrente', 17 => 'Config. Inválida', 18 => 'Falha Térmica (PTC)', 20 => 'Config. Inválida (Reset)', 21 => 'Perda Alimentação Controlo' ]; $faultNum = hexdec($faultHex); return isset($faultMap[$faultNum]) ? $faultMap[$faultNum] : 'Código desconhecido (' . $faultHex . ')'; }
function calculateWorkingHours($conn, $equipment_id, $startDate = null, $endDate = null) { $sql = "SELECT action, timestamp FROM equipment_log WHERE equipment_id = ? AND (action LIKE '%run' OR action LIKE '%stop' OR action LIKE '%clear_fault') "; $params = [$equipment_id]; $types = 'i'; if ($startDate && $endDate) { $sql .= "AND timestamp BETWEEN ? AND ? "; $params[] = $startDate; $params[] = $endDate; $types .= 'ss'; } $sql .= "ORDER BY timestamp ASC"; $stmt = $conn->prepare($sql); $bind_params = []; $bind_params[] = $types; for ($i = 0; $i < count($params); $i++) { $bind_params[] = &$params[$i]; } call_user_func_array([$stmt, 'bind_param'], $bind_params); $stmt->execute(); $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); $totalSeconds = 0; $lastRunTime = null; foreach ($logs as $log) { $isRunAction = strpos($log['action'], 'run') !== false; $isStopAction = (strpos($log['action'], 'stop') !== false || strpos($log['action'], 'clear_fault') !== false); if ($isRunAction && $lastRunTime === null) { $lastRunTime = new DateTime($log['timestamp']); } elseif ($isStopAction && $lastRunTime !== null) { $stopTime = new DateTime($log['timestamp']); $totalSeconds += $stopTime->getTimestamp() - $lastRunTime->getTimestamp(); $lastRunTime = null; } } if ($lastRunTime !== null) { $now = new DateTime(); $limitTime = ($endDate && new DateTime($endDate) < $now) ? new DateTime($endDate) : $now; $totalSeconds += $limitTime->getTimestamp() - $lastRunTime->getTimestamp(); } return $totalSeconds; }

date_default_timezone_set('Europe/Lisbon');
$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');

$today_seconds = calculateWorkingHours($conn, $equipment_id, $today_start, $today_end);
$total_seconds = calculateWorkingHours($conn, $equipment_id);

// --- NOVO: Ir buscar a última falha diretamente ao Arduino ---
$live_fault_code = null;
$live_fault_timestamp = null;

$url = "http://{$equipment['ip_address']}/api/status/{$equipment['slave_id']}";
$context = stream_context_create(['http' => ['timeout' => 5]]);
$response_json = @file_get_contents($url, false, $context);

if ($response_json) {
    $live_data = json_decode($response_json, true);
    if ($live_data && isset($live_data['faultHex'])) {
        $live_fault_code = $live_data['faultHex'];

        // Se a última falha não for 'Sem falha', procura o último registo dela na BD
        if (hexdec($live_fault_code) != 0) {
            $stmt_fault_time = $conn->prepare("
                SELECT timestamp FROM equipment_log
                WHERE equipment_id = ? AND action = 'fault_detected' AND details LIKE ?
                ORDER BY timestamp DESC LIMIT 1
            ");
            $like_pattern = "%" . $live_fault_code . "%";
            $stmt_fault_time->bind_param("is", $equipment_id, $like_pattern);
            $stmt_fault_time->execute();
            $fault_time_result = $stmt_fault_time->get_result()->fetch_assoc();
            if ($fault_time_result) {
                $live_fault_timestamp = $fault_time_result['timestamp'];
            }
            $stmt_fault_time->close();
        }
    }
}


// --- Lógica de Paginação ---
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 25;
$offset = ($page - 1) * $records_per_page;
$stmt_total = $conn->prepare("SELECT COUNT(*) FROM equipment_log WHERE equipment_id = ?");
$stmt_total->bind_param("i", $equipment_id);
$stmt_total->execute();
$total_records = $stmt_total->get_result()->fetch_row()[0];
$total_pages = ceil($total_records / $records_per_page);
$stmt_total->close();

// --- Ir buscar os registos para a página atual ---
$stmt = $conn->prepare("SELECT log.timestamp, COALESCE(usr.username, 'Sistema / Manual') AS user_name, log.action, log.details FROM equipment_log AS log LEFT JOIN users AS usr ON log.user_id = usr.id WHERE log.equipment_id = ? ORDER BY log.timestamp DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $equipment_id, $records_per_page, $offset);
$stmt->execute();
$log_entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function getActionBadgeClass($action) { if (strpos($action, 'run') !== false) return 'bg-success'; if (strpos($action, 'stop') !== false) return 'bg-warning text-dark'; if (strpos($action, 'fault') !== false) return 'bg-danger'; if (strpos($action, 'clear_fault') !== false) return 'bg-info text-dark'; return 'bg-secondary'; }
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">Detalhes do Equipamento - <span class="text-info"><?= htmlspecialchars($equipment_name) ?></span></h2>
        <a href="dashboard_scada.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
    </div>

    <div class="row mb-4">
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card bg-dark text-light h-100">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <h5 class="card-title text-white-50">Horas de Funcionamento (Hoje)</h5>
                    <p class="card-text display-6 fw-bold text-success mb-0"><?= formatDuration($today_seconds) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6 mb-3">
            <div class="card bg-dark text-light h-100">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <h5 class="card-title text-white-50">Total de Horas de Funcionamento</h5>
                    <p class="card-text display-6 fw-bold text-info mb-0"><?= formatDuration($total_seconds) ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12 mb-3">
            <div class="card bg-dark text-light h-100">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <h5 class="card-title text-white-50">Última Falha no Equipamento</h5>
                    <?php 
                        if ($live_fault_code !== null) {
                            $fault_description = getFaultDescriptionFromHex($live_fault_code);
                            $is_no_fault = (hexdec($live_fault_code) == 0);
                    ?>
                        <p class="card-text h4 fw-bold <?= $is_no_fault ? 'text-success' : 'text-danger' ?> mb-1" title="<?= htmlspecialchars($live_fault_code) ?>">
                            <?= htmlspecialchars($fault_description) ?>
                        </p>
                        <?php if (!$is_no_fault && $live_fault_timestamp): ?>
                            <small class="text-muted">Último registo: <?= date('d/m/Y H:i:s', strtotime($live_fault_timestamp)) ?></small>
                        <?php endif; ?>
                    <?php } else { ?>
                        <p class="card-text h4 fw-bold text-warning mb-0">Não foi possível ler</p>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>


    <div class="card bg-dark text-light shadow-sm">
        <div class="card-header">
            <h5>Histórico de Operações</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-dark table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Data e Hora</th>
                            <th>Utilizador / Origem</th>
                            <th>Ação</th>
                            <th>Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log_entries)): ?>
                            <tr><td colspan="4" class="text-center text-muted">Nenhum registo de operações para este equipamento.</td></tr>
                        <?php else: ?>
                            <?php foreach ($log_entries as $entry): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($entry['timestamp']))) ?></td>
                                    <td><?= htmlspecialchars($entry['user_name']) ?></td>
                                    <td><span class="badge <?= getActionBadgeClass($entry['action']) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $entry['action']))) ?></span></td>
                                    <td><?= htmlspecialchars($entry['details']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-dark">
            <nav><ul class="pagination justify-content-center mb-0">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $page) ? 'active' : '' ?>"><a class="page-link" href="view_equipment_details.php?id=<?= $equipment_id ?>&page=<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once 'footer.php';
?>

