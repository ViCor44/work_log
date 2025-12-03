<?php
require_once '../header.php';

// --- Lógica de busca e processamento de dados (sem alterações) ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$tanks_stmt = $conn->query("
    SELECT id, name 
    FROM tanks 
    WHERE type = 'outro' 
    AND name NOT IN ('Rede', 'Agua Quente Edificio') 
    AND water_reading_frequency > 0
    ORDER BY name ASC
");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');
$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0.0);

if (!empty($tank_ids)) {
    $first_day_of_month = "$year-$month-01";
    $last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));
    $placeholders = implode(',', array_fill(0, count($tank_ids), '?'));
    $types = str_repeat('i', count($tank_ids));
    $sql = "
        SELECT id, tank_id, reading_datetime, meter_value 
        FROM water_readings 
        WHERE tank_id IN ($placeholders) 
        AND ( (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? )
        ORDER BY tank_id, reading_datetime ASC
    ";
    $stmt = $conn->prepare($sql);
    $params = array_merge($tank_ids, [$month, $year, $last_day_of_prev_month]);
    $bind_params = [];
    $bind_params[] = $types . "sss";
    for ($i = 0; $i < count($params); $i++) {
        $bind_params[] = &$params[$i];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_params);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $readings_by_day = [];
    foreach ($results as $row) {
        $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
        if (!isset($readings_by_day[$row['tank_id']][$date_key])) {
            $readings_by_day[$row['tank_id']][$date_key] = ['value' => (float)$row['meter_value'], 'id' => $row['id']];
        }
    }
    
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($day = 1; $day <= $days_in_month; $day++) {
        foreach ($tanks as $tank) {
            $tank_id = $tank['id'];
            $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
            $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));
            $leitura_atual_data = isset($readings_by_day[$tank_id][$current_date_str]) ? $readings_by_day[$tank_id][$current_date_str] : null;
            $leitura_anterior_data = isset($readings_by_day[$tank_id][$previous_date_str]) ? $readings_by_day[$tank_id][$previous_date_str] : null;
            $consumo = null;
            if ($leitura_atual_data && $leitura_anterior_data) {
                $consumo = $leitura_atual_data['value'] - $leitura_anterior_data['value'];
                if ($consumo < 0) $consumo = 0;
            }
            if ($leitura_atual_data) {
                $report_data[$day][$tank_id] = ['id' => $leitura_atual_data['id'], 'leitura' => $leitura_atual_data['value'], 'consumo' => $consumo];
                if ($consumo !== null) { $tank_totals[$tank_id] += $consumo; }
            }
        }
    }
}
?>
<style>
    .report-table { font-size: 0.8rem; }
    .report-table th, .report-table td { text-align: center; vertical-align: middle; padding: 4px; border: 1px solid #dee2e6; }
    .report-table thead th { background-color: #dee2e6; }
    .col-gasto, .total-row { font-weight: bold; background-color: #e9ecef; }
</style>
<link href="/work_log/css/sweetalert2.min.css" rel="stylesheet">
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório de Consumo de Outros Tanques</h1>
        <div><a href="menu_relatorios.php" class="btn btn-secondary">Voltar ao Menu</a></div>
    </div>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3"><label for="month" class="form-label">Selecionar Mês/Ano</label><input type="month" id="month" name="month" class="form-control" value="<?= htmlspecialchars($current_month) ?>"></div>
                <div class="col-md-auto"><button type="submit" class="btn btn-primary">Pesquisar</button></div>
                <div class="col-md-auto"><a href="gerar_pdf_agua_outros.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger"><i class="fas fa-file-pdf"></i> Exportar PDF</a></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover text-center table-sm">
                <thead class="text-center">
                    <tr>
                        <th rowspan="2">Dia</th>
                        <?php foreach ($tanks as $tank): ?>
                            <th colspan="2"><?= htmlspecialchars($tank['name']) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total Consumo (m³)</th>
                        <th rowspan="2">Ações</th> </tr>
                    <tr>
                        <?php foreach ($tanks as $tank): ?>
                            <th>Leitura</th>
                            <th class="col-gasto">Consumo</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
				    <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
				        <tr>
				            <td class="total-col"><?= $day ?></td>
				            <?php
				            $daily_total = 0;
				            foreach ($tanks as $tank):
				                $data = isset($report_data[$day][$tank['id']]) ? $report_data[$day][$tank['id']] : null;
				                $leitura = $data ? number_format($data['leitura'], 0) : '';
				                $consumo = ($data && $data['consumo'] !== null) ? number_format($data['consumo'], 0) : '';
				                if ($data && $data['consumo'] !== null) { $daily_total += $data['consumo']; }
				            ?>
				                <td><?= $leitura ?></td>
				                <td class="col-gasto"><?= $consumo ?></td>
				            <?php endforeach; ?>
				            <td class="total-col"><?= $daily_total > 0 ? number_format($daily_total, 0) : '0' ?></td>
				            <td>
				                <?php 
				                // Prepara a data para o link, independentemente de haver dados
				                $date_for_link = sprintf('%s-%s-%02d', $year, $month, $day);
				                ?>
				                <a href="#" onclick="confirmEdit('form_editar_agua_outros.php?date=<?= $date_for_link ?>')" class="btn btn-sm btn-warning">
				                    <i class="fas fa-edit"></i>
				                </a>
				            </td>
				        </tr>
				    <?php endfor; ?>
				</tbody>
                <tfoot class="total-row">
                    <tr>
                        <td>Total</td>
                        <?php
                        $grand_total = 0;
                        foreach ($tanks as $tank):
                            $total_gasto = isset($tank_totals[$tank['id']]) ? $tank_totals[$tank['id']] : 0;
                            $grand_total += $total_gasto;
                        ?>
                            <td></td>
                            <td class="col-gasto"><?= number_format($total_gasto, 0) ?></td>
                        <?php endforeach; ?>
                        <td><?= number_format($grand_total, 0) ?></td>
                        <td></td> </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<script src="/work_log/js/sweetalert2.all.min.js"></script>
<script>
	function confirmEdit(editUrl) {
	    Swal.fire({
	        title: 'Atenção: Área de Edição',
	        text: "Está prestes a editar um registo. Todas as alterações serão guardadas e registadas no log do sistema para auditoria. Deseja continuar?",
	        icon: 'warning',
	        showCancelButton: true,
	        confirmButtonColor: '#ffc107', // Cor de aviso do Bootstrap
	        cancelButtonColor: '#6c757d',  // Cor secundária
	        confirmButtonText: 'Sim, continuar para edição',
	        cancelButtonText: 'Cancelar'
	    }).then((result) => {
	        // Se o utilizador confirmar, redireciona para a página de edição
	        if (result.isConfirmed) {
	            window.location.href = editUrl;
	        }
	    });
	}
</script>
<?php require_once '../footer.php'; ?>