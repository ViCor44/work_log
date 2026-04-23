<?php
require_once '../header.php';

// --- Lógica de busca e processamento de dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

// Busca apenas piscinas que têm contador de rejeitado
$tanks_stmt = $conn->query("SELECT id, name, volume_m3 FROM tanks WHERE type = 'piscina' AND has_reject_counter = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');

if (empty($tank_ids)) {
    $tank_ids = [0]; // Para evitar erro na sintaxe SQL
}

$first_day_of_month = "$year-$month-01";
$last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));

$placeholders = implode(',', array_fill(0, count($tank_ids), '?'));
$sql = "
    SELECT 
        tank_id,
        reading_datetime,
        meter_value
    FROM rejected_water_readings
    WHERE 
        tank_id IN ($placeholders)
        AND (
            (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?)
            OR DATE(reading_datetime) = ?
        )
    ORDER BY tank_id, reading_datetime ASC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Erro ao preparar a consulta SQL: " . $conn->error); }

// Bind dos tank_ids
$types = str_repeat('i', count($tank_ids));
$bind_params = array_merge($tank_ids, [$month, $year, $last_day_of_prev_month]);
$stmt->bind_param($types . "sss", ...$bind_params);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- PROCESSAMENTO DE DADOS ---
$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0.0);
$readings_by_day = [];

// 1. Organiza todas as leituras por dia para cada tanque
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    $readings_by_day[$row['tank_id']][$date_key][] = [
        'time' => $row['reading_datetime'],
        'value' => (float)$row['meter_value']
    ];
}

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// 2. Constrói a matriz final para o relatório
foreach ($tanks as $tank) {
    $tank_id = $tank['id'];
    $last_level = null;

    if (isset($readings_by_day[$tank_id][$last_day_of_prev_month])) {
        $last_level = end($readings_by_day[$tank_id][$last_day_of_prev_month])['value'];
    }

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        
        if (isset($readings_by_day[$tank_id][$current_date_str])) {
            $today_readings = $readings_by_day[$tank_id][$current_date_str];
            
            foreach ($today_readings as $reading) {
                $current_level = $reading['value'];
                $consumption = 0.0;

                if ($last_level !== null) {
                    if ($current_level >= $last_level) {
                        $consumption = $current_level - $last_level;
                    }
                }
                
                $period = (date('H', strtotime($reading['time'])) < 13) ? 'manha' : 'tarde';

                $report_data[$day][$tank_id][$period] = [
                    'leitura' => $current_level,
                    'consumo' => $consumption
                ];
                
                $tank_totals[$tank_id] += $consumption;
                $last_level = $current_level;
            }
        }
    }
}
?>

<style>
    .report-table { font-size: 0.8rem; }
    .report-table th, .report-table td { text-align: center; vertical-align: middle; padding: 4px; border: 1px solid #dee2e6; }
    .report-table thead th { background-color: #f8f9fa; }
    .col-gasto { background-color: #e9ecef; }
    .total-col, .total-row { font-weight: bold; background-color: #e9ecef; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório Mensal de Água Rejeitada (Piscinas)</h1>
        <div>
            <a href="form_agua_manha.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Registo</a>
            <a href="menu_relatorios.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="month" class="form-label">Selecionar Mês/Ano</label>
                    <input type="month" id="month" name="month" class="form-control" value="<?= htmlspecialchars($current_month) ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Pesquisar</button>
                </div>
                <?php if (!empty($tanks)): ?>
                    <div class="col-md-auto">
                        <a href="gerar_pdf_agua_rejeitada.php?month=<?= htmlspecialchars($current_month) ?>" class="btn btn-danger" target="_blank">
                            <i class="fas fa-file-pdf me-2"></i>Exportar PDF
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (empty($tanks)): ?>
        <div class="alert alert-warning">Nenhuma piscina configurada com contador de rejeitado.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover report-table">
                <thead class="table-light">
                    <tr>
                        <th>Dia</th>
                        <?php foreach ($tanks as $tank): ?>
                            <th colspan="3"><?= htmlspecialchars($tank['name']) ?></th>
                        <?php endforeach; ?>
                        <th>Total (m³)</th>
                        <th></th>
                    </tr>
                    <tr>
                        <th></th>
                        <?php foreach ($tanks as $tank): ?>
                            <th style="font-size: 0.7rem;">Leitura</th>
                            <th style="font-size: 0.7rem;">Rejeitado</th>
                            <th style="font-size: 0.7rem;">% Vol.</th>
                        <?php endforeach; ?>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        echo '<tr>';
                        echo '<td><strong>' . $day . '</strong></td>';
                        
                        $day_total = 0;
                        foreach ($tanks as $tank) {
                            $tank_id = $tank['id'];
                            $tank_volume = isset($tank['volume_m3']) ? (float)$tank['volume_m3'] : 0.0;
                            $dailyData = null;
                            if (isset($report_data[$day][$tank_id]['manha'])) {
                                $dailyData = $report_data[$day][$tank_id]['manha'];
                            } elseif (isset($report_data[$day][$tank_id]['tarde'])) {
                                $dailyData = $report_data[$day][$tank_id]['tarde'];
                            }

                            if ($dailyData !== null) {
                                $data = $dailyData;
                                $percentage = $tank_volume > 0 ? (($data['consumo'] / $tank_volume) * 100) : null;
                                echo '<td>' . number_format($data['leitura'], 0, ',', '.') . '</td>';
                                echo '<td class="col-gasto">' . number_format($data['consumo'], 2, ',', '.') . '</td>';
                                echo '<td class="col-gasto">' . ($percentage !== null ? number_format($percentage, 2, ',', '.') . '%' : '-') . '</td>';
                                $day_total += $data['consumo'];
                            } else {
                                echo '<td>-</td>';
                                echo '<td class="col-gasto">-</td>';
                                echo '<td class="col-gasto">-</td>';
                            }
                        }
                        echo '<td class="total-col">' . number_format($day_total, 2, ',', '.') . '</td>';
                        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
                        $has_data = isset($report_data[$day]) && !empty($report_data[$day]);
                        echo '<td style="white-space:nowrap;">';
                        if ($has_data) {
                            echo '<a href="form_editar_agua_rejeitada.php?date=' . $current_date_str . '" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i></a>';
                        } else {
                            echo '<a href="form_editar_agua_rejeitada.php?date=' . $current_date_str . '" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus"></i></a>';
                        }
                        echo '</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>Total do Mês (m³)</td>
                        <?php foreach ($tanks as $tank): ?>
                            <?php $monthly_percentage = !empty($tank['volume_m3']) ? (($tank_totals[$tank['id']] / (float)$tank['volume_m3']) * 100) : null; ?>
                            <td>-</td>
                            <td><?= number_format($tank_totals[$tank['id']], 2, ',', '.') ?></td>
                            <td><?= $monthly_percentage !== null ? number_format($monthly_percentage, 2, ',', '.') . '%' : '-' ?></td>
                        <?php endforeach; ?>
                        <td><?= number_format(array_sum($tank_totals), 2, ',', '.') ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
require_once '../footer.php';
?>
