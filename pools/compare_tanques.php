<?php
require_once '../header.php';

// --- 1. Lógica para buscar todos os tanques para os dropdowns ---
$tanks_list_stmt = $conn->query("SELECT id, name FROM tanks WHERE water_reading_frequency > 0 ORDER BY name ASC");
$all_tanks = $tanks_list_stmt->fetch_all(MYSQLI_ASSOC);

// --- 2. Lógica para processar o formulário e buscar os dados para comparação ---
$tank1_id = isset($_GET['tank1']) ? $_GET['tank1'] : null;
$tank2_id = isset($_GET['tank2']) ? $_GET['tank2'] : null;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$comparison_data = [];
$chart_data = ['labels' => [], 'tank1_consumption' => [], 'tank2_consumption' => []];
$tank1_name = '';
$tank2_name = '';

if ($tank1_id && $tank2_id) {
    // Encontra os nomes dos tanques selecionados
    foreach ($all_tanks as $tank) {
        if ($tank['id'] == $tank1_id) $tank1_name = $tank['name'];
        if ($tank['id'] == $tank2_id) $tank2_name = $tank['name'];
    }

    // Busca as leituras para os dois tanques no intervalo de datas
    $sql = "
        SELECT tank_id, reading_datetime, meter_value
        FROM water_readings
        WHERE tank_id IN (?, ?) AND DATE(reading_datetime) BETWEEN ? AND ?
        ORDER BY reading_datetime ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $tank1_id, $tank2_id, $start_date, $end_date);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Processa os dados para a tabela e para o gráfico
    $readings_by_day = [];
    foreach ($results as $row) {
        $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
        $readings_by_day[$row['tank_id']][$date_key] = $row['meter_value'];
    }

    $current_date = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);

    while ($current_date <= $end_date_obj) {
        $date_str = $current_date->format('Y-m-d');
        $prev_date_obj = clone $current_date;
		$prev_date_obj->modify('-1 day');
		$prev_date_str = $prev_date_obj->format('Y-m-d');
        
        // Dados para o Tanque 1
        $t1_leitura = isset($readings_by_day[$tank1_id][$date_str]) ? $readings_by_day[$tank1_id][$date_str] : null;
        $t1_leitura_ant = isset($readings_by_day[$tank1_id][$prev_date_str]) ? $readings_by_day[$tank1_id][$prev_date_str] : null;
        $t1_consumo = ($t1_leitura && $t1_leitura_ant) ? $t1_leitura - $t1_leitura_ant : null;
        if ($t1_consumo < 0) $t1_consumo = 0;
        
        // Dados para o Tanque 2
        $t2_leitura = isset($readings_by_day[$tank2_id][$date_str]) ? $readings_by_day[$tank2_id][$date_str] : null;
        $t2_leitura_ant = isset($readings_by_day[$tank2_id][$prev_date_str]) ? $readings_by_day[$tank2_id][$prev_date_str] : null;
        $t2_consumo = ($t2_leitura && $t2_leitura_ant) ? $t2_leitura - $t2_leitura_ant : null;
        if ($t2_consumo < 0) $t2_consumo = 0;

        if ($t1_leitura !== null || $t2_leitura !== null) {
            $comparison_data[$date_str] = [
                'tank1_leitura' => $t1_leitura, 'tank1_consumo' => $t1_consumo,
                'tank2_leitura' => $t2_leitura, 'tank2_consumo' => $t2_consumo
            ];
            
            // Adiciona dados ao array do gráfico
            $chart_data['labels'][] = $current_date->format('d/m');
            $chart_data['tank1_consumption'][] = $t1_consumo;
            $chart_data['tank2_consumption'][] = $t2_consumo;
        }
        
        $current_date->modify('+1 day');
    }
}
?>

<script src="/work_log/js/chart.js"></script>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Comparativo de Consumo de Água</h1>
        <div>
            <a href="menu_relatorios.php" class="btn btn-secondary">Voltar ao Menu</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="tank1" class="form-label">Tanque 1</label>
                    <select id="tank1" name="tank1" class="form-select" required>
                        <option value="">Selecione um tanque...</option>
                        <?php foreach($all_tanks as $tank): ?>
                            <option value="<?= $tank['id'] ?>" <?= ($tank1_id == $tank['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tank['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="tank2" class="form-label">Tanque 2</label>
                    <select id="tank2" name="tank2" class="form-select" required>
                        <option value="">Selecione um tanque...</option>
                        <?php foreach($all_tanks as $tank): ?>
                            <option value="<?= $tank['id'] ?>" <?= ($tank2_id == $tank['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tank['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="start_date" class="form-label">Data Início</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label for="end_date" class="form-label">Data Fim</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Comparar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tank1_id && $tank2_id): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h5 class="mb-0">Gráfico de Consumo (m³)</h5>
            </div>
            <div class="card-body">
                <canvas id="consumptionChart"></canvas>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h5 class="mb-0">Tabela de Dados Detalhada</h5>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-bordered table-hover text-center table-sm">
                    <thead>
                        <tr>
                            <th rowspan="2" class="align-middle">Data</th>
                            <th colspan="2"><?= htmlspecialchars($tank1_name) ?></th>
                            <th colspan="2"><?= htmlspecialchars($tank2_name) ?></th>
                        </tr>
                        <tr>
                            <th>Leitura (m³)</th>
                            <th>Consumo (m³)</th>
                            <th>Leitura (m³)</th>
                            <th>Consumo (m³)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($comparison_data as $date => $data): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($date)) ?></td>
                            <td><?= $data['tank1_leitura'] !== null ? number_format($data['tank1_leitura'], 0) : 'N/A' ?></td>
                            <td class="fw-bold"><?= $data['tank1_consumo'] !== null ? number_format($data['tank1_consumo'], 0) : 'N/A' ?></td>
                            <td><?= $data['tank2_leitura'] !== null ? number_format($data['tank2_leitura'], 0) : 'N/A' ?></td>
                            <td class="fw-bold"><?= $data['tank2_consumo'] !== null ? number_format($data['tank2_consumo'], 0) : 'N/A' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($chart_data['labels'])): ?>
    const ctx = document.getElementById('consumptionChart').getContext('2d');
    const consumptionChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_data['labels']) ?>,
            datasets: [{
                label: '<?= htmlspecialchars($tank1_name) ?>',
                data: <?= json_encode($chart_data['tank1_consumption']) ?>,
                borderColor: 'rgba(54, 162, 235, 1)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderWidth: 2,
                fill: false,
                tension: 0.1
            }, {
                label: '<?= htmlspecialchars($tank2_name) ?>',
                data: <?= json_encode($chart_data['tank2_consumption']) ?>,
                borderColor: 'rgba(255, 99, 132, 1)',
                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                borderWidth: 2,
                fill: false,
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Consumo (m³)'
                    }
                }
            }
        }
    });
    <?php endif; ?>
});
</script>

<?php
require_once '../footer.php';
?>