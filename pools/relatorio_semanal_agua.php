<?php
require_once '../header.php';
require_once 'meter_continuity.php';

// Link para configuração (exibe para todos os usuários)
if (isset($_SESSION['user_id'])) {
    echo '<div style="padding:10px;background:#f5f5f5;border-bottom:1px solid #ccc;">
        <a href="configurar_relatorio.php" style="font-weight:bold;">Configurar Relatório de Água</a>
    </div>';
}

// --- Lógica de Filtros ---
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$week = isset($_GET['week']) ? $_GET['week'] : date('W');

// --- LÓGICA DE DATA CORRIGIDA PARA COMPATIBILIDADE ---
$start_of_week = new DateTime();
$start_of_week->setISODate($year, $week);
$start_date_str = $start_of_week->format('Y-m-d');

$end_of_week_obj = clone $start_of_week;
$end_of_week_obj->modify('+6 days');
$end_date_str = $end_of_week_obj->format('Y-m-d');

$day_before_start_obj = clone $start_of_week;
$day_before_start_obj->modify('-1 day');
$day_before_start = $day_before_start_obj->format('Y-m-d');
// --- FIM DA CORREÇÃO DE DATA INICIAL ---

// --- Lógica de Busca e Processamento de Dados ---
$tanks_stmt = $conn->query("SELECT id, name, type, has_reject_counter, volume_m3 FROM tanks WHERE water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$reject_tanks = array_values(array_filter($tanks, function ($tank) {
    return $tank['type'] === 'piscina' && (int)$tank['has_reject_counter'] === 1;
}));

$sql = "
    SELECT tank_id, reading_datetime, meter_value
    FROM water_readings
    WHERE DATE(reading_datetime) BETWEEN ? AND ?
    ORDER BY tank_id, reading_datetime ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $day_before_start, $end_date_str);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$reject_sql = "
    SELECT tank_id, reading_datetime, meter_value
    FROM rejected_water_readings
    WHERE DATE(reading_datetime) BETWEEN ? AND ?
    ORDER BY tank_id, reading_datetime ASC
";
$reject_stmt = $conn->prepare($reject_sql);
$reject_stmt->bind_param("ss", $day_before_start, $end_date_str);
$reject_stmt->execute();
$reject_results = $reject_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reject_stmt->close();

$allTankIds = array_column($tanks, 'id');
$rejectTankIds = array_column($reject_tanks, 'id');
$normalOffsetIndex = get_meter_offset_index($conn, $allTankIds, 'normal');
$rejectOffsetIndex = get_meter_offset_index($conn, $rejectTankIds, 'rejected');

$report_data = [];
$readings_by_day = [];
$reject_readings_by_day = [];
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    $tankId = (int)$row['tank_id'];
    $adjustedValue = get_adjusted_meter_value($tankId, $row['reading_datetime'], (float)$row['meter_value'], $normalOffsetIndex);
    if (!isset($readings_by_day[$row['tank_id']][$date_key])) {
        $readings_by_day[$tankId][$date_key] = $adjustedValue;
    }
}

foreach ($reject_results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    $tankId = (int)$row['tank_id'];
    $adjustedValue = get_adjusted_meter_value($tankId, $row['reading_datetime'], (float)$row['meter_value'], $rejectOffsetIndex);
    if (!isset($reject_readings_by_day[$tankId][$date_key])) {
        $reject_readings_by_day[$tankId][$date_key] = $adjustedValue;
    }
}

for ($i = 0; $i < 7; $i++) {
    // --- LÓGICA DE DATA DENTRO DO LOOP CORRIGIDA PARA COMPATIBILIDADE ---
    $current_date_obj = clone $start_of_week;
    $current_date_obj->modify("+$i days");
    $current_date_str = $current_date_obj->format('Y-m-d');

    $prev_date_obj = clone $current_date_obj;
    $prev_date_obj->modify('-1 day');
    $prev_date_str = $prev_date_obj->format('Y-m-d');
    // --- FIM DA CORREÇÃO ---
    
    foreach ($tanks as $tank) {
        $tank_id = $tank['id'];
        $leitura = isset($readings_by_day[$tank_id][$current_date_str]) ? $readings_by_day[$tank_id][$current_date_str] : null;
        $leitura_ant = isset($readings_by_day[$tank_id][$prev_date_str]) ? $readings_by_day[$tank_id][$prev_date_str] : null;
        $leitura_rejeitada = null;
        $leitura_rejeitada_ant = null;
        $rejeitado = null;
        $percentagem_rejeitado = null;
        
        $consumo = null;
        if ($leitura !== null && $leitura_ant !== null) {
            $consumo = $leitura - $leitura_ant;
            if ($consumo < 0) $consumo = 0;
        }

        if ($tank['type'] === 'piscina' && (int)$tank['has_reject_counter'] === 1) {
            $leitura_rejeitada = isset($reject_readings_by_day[$tank_id][$current_date_str]) ? $reject_readings_by_day[$tank_id][$current_date_str] : null;
            $leitura_rejeitada_ant = isset($reject_readings_by_day[$tank_id][$prev_date_str]) ? $reject_readings_by_day[$tank_id][$prev_date_str] : null;

            if ($leitura_rejeitada !== null && $leitura_rejeitada_ant !== null) {
                $rejeitado = $leitura_rejeitada - $leitura_rejeitada_ant;
                if ($rejeitado < 0) $rejeitado = 0;
            }

            if ($rejeitado !== null && !empty($tank['volume_m3'])) {
                $percentagem_rejeitado = ($rejeitado / (float)$tank['volume_m3']) * 100;
            }
        }
        
        $report_data[$tank_id][$i] = [
            'leitura' => $leitura,
            'consumo' => $consumo,
            'leitura_rejeitada' => $leitura_rejeitada,
            'rejeitado' => $rejeitado,
            'percentagem_rejeitado' => $percentagem_rejeitado
        ];
    }
}
?>
<style>
    .weekly-report th, .weekly-report td { font-size: 0.8rem; text-align: center; vertical-align: middle; }
    .consumption-cell { background-color: #f8f9fa; font-weight: bold; }
    .reject-cell { background-color: #fff3cd; font-weight: bold; }
</style>
	<div class="container-fluid mt-4">
	    <h1 class="h3 mb-4">Relatório Semanal de Consumo de Água</h1>
	    
	    <div class="card shadow-sm mb-4">
	    <div class="card-body">
	        <form method="GET" action="" id="week-form" class="row g-3 align-items-end">
	            <div class="col-md-3">
	                <label for="week_picker" class="form-label">Selecionar Semana</label>
	                <input type="week" id="week_picker" name="week_input" class="form-control" value="<?= $year ?>-W<?= str_pad($week, 2, '0', STR_PAD_LEFT) ?>">
	            </div>
	            <div class="col-md-auto">
	                <button type="submit" class="btn btn-primary">Pesquisar</button>
	            </div>
	            <div class="col-md-auto">
	                <a href="menu_relatorios.php" class="btn btn-secondary">Voltar ao Menu</a>
	            </div>
	            <div class="col-md-auto">
                    <a href="gerar_pdf_agua_todos.php?year=<?= $year ?>&week=<?= $week ?>" target="_blank" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                    <a href="configurar_relatorio.php" class="btn btn-warning ms-2">
                        <i class="fas fa-cogs"></i> Configurar Relatório
                    </a>
	            </div>
	        </form>
	    </div>
	</div>

    <div class="card shadow-sm mb-4">
        <div class="card-body table-responsive">
            <h5 class="mb-3">Consumo Geral</h5>
            <table class="table table-bordered table-sm weekly-report">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle">Tanque</th>
                        <?php
                        $dias_semana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
                        for ($i = 0; $i < 7; $i++) {
                            $header_date_obj = clone $start_of_week;
                            $header_date_obj->modify("+$i days");
                            $header_date_str = $header_date_obj->format('d/m');
                            echo '<th colspan="2">' . $dias_semana[$i] . '<br>' . $header_date_str . '</th>';
                        }
                        ?>
                        <th rowspan="2" class="align-middle">Total Semanal (m³)</th>
                    </tr>
                    <tr>
                        <?php for ($i = 0; $i < 7; $i++): ?>
                            <th>Leitura</th>
                            <th>Consumo</th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tanks as $tank): ?>
                        <tr>
                            <td class="fw-bold text-start"><?= htmlspecialchars($tank['name']) ?></td>
                            <?php 
                            $weekly_total = 0;
                            for ($i = 0; $i < 7; $i++): 
                                $data = $report_data[$tank['id']][$i];
                                if (isset($data['consumo'])) {
                                    $weekly_total += $data['consumo'];
                                }
                            ?>
                                <td><?= $data['leitura'] !== null ? number_format($data['leitura'], 0) : '' ?></td>
                                <td class="consumption-cell"><?= $data['consumo'] !== null ? number_format($data['consumo'], 0) : '' ?></td>
                            <?php endfor; ?>
                            <td class="fw-bold table-light"><?= number_format($weekly_total, 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($reject_tanks)): ?>
        <div class="card shadow-sm">
            <div class="card-body table-responsive">
                <h5 class="mb-3">Rejeitado</h5>
                <table class="table table-bordered table-sm weekly-report">
                    <thead class="table-warning">
                        <tr>
                            <th rowspan="2" class="align-middle">Piscina</th>
                            <?php
                            for ($i = 0; $i < 7; $i++) {
                                $header_date_obj = clone $start_of_week;
                                $header_date_obj->modify("+$i days");
                                $header_date_str = $header_date_obj->format('d/m');
                                echo '<th colspan="3">' . $dias_semana[$i] . '<br>' . $header_date_str . '</th>';
                            }
                            ?>
                            <th rowspan="2" class="align-middle">Total Rej. (m³)</th>
                            <th rowspan="2" class="align-middle">% Vol. Sem.</th>
                        </tr>
                        <tr>
                            <?php for ($i = 0; $i < 7; $i++): ?>
                                <th>Leit. Rej.</th>
                                <th>Rej.</th>
                                <th>% Vol.</th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reject_tanks as $tank): ?>
                            <tr>
                                <td class="fw-bold text-start"><?= htmlspecialchars($tank['name']) ?></td>
                                <?php
                                $weekly_rejected_total = 0;
                                for ($i = 0; $i < 7; $i++):
                                    $data = $report_data[$tank['id']][$i];
                                    if ($data['rejeitado'] !== null) {
                                        $weekly_rejected_total += $data['rejeitado'];
                                    }
                                ?>
                                    <td><?= $data['leitura_rejeitada'] !== null ? number_format($data['leitura_rejeitada'], 0) : '' ?></td>
                                    <td class="reject-cell"><?= $data['rejeitado'] !== null ? number_format($data['rejeitado'], 0) : '' ?></td>
                                    <td class="reject-cell"><?= $data['percentagem_rejeitado'] !== null ? number_format($data['percentagem_rejeitado'], 2, ',', '.') . '%' : '' ?></td>
                                <?php endfor; ?>
                                <?php $weekly_rejected_percentage = !empty($tank['volume_m3']) ? (($weekly_rejected_total / (float)$tank['volume_m3']) * 100) : null; ?>
                                <td class="fw-bold table-warning"><?= $weekly_rejected_total > 0 ? number_format($weekly_rejected_total, 0) : '' ?></td>
                                <td class="fw-bold table-warning"><?= $weekly_rejected_percentage !== null ? number_format($weekly_rejected_percentage, 2, ',', '.') . '%' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Script para extrair o ano e a semana do input type="week"
document.getElementById('week-form').addEventListener('submit', function(e) {
    const weekInput = document.getElementById('week_picker').value;
    if (weekInput) {
        const [year, week] = weekInput.split('-W');
        this.insertAdjacentHTML('beforeend', `<input type="hidden" name="year" value="${year}" />`);
        this.insertAdjacentHTML('beforeend', `<input type="hidden" name="week" value="${week}" />`);
    }
});
</script>

<?php
require_once '../footer.php';
?>