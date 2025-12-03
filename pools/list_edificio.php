<?php
require_once '../header.php';

// --- Lógica de Filtragem e Busca de Dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

// 1. Encontrar o ID do tanque 'Agua Quente Edificio'
$stmt_tank = $conn->prepare("SELECT id FROM tanks WHERE name = 'Edificio' LIMIT 1");
$stmt_tank->execute();
$result_tank = $stmt_tank->get_result();
$edificio_tank_id = null;
if ($result_tank->num_rows > 0) {
    $edificio_tank_id = $result_tank->fetch_assoc()['id'];
}
$stmt_tank->close();

$report_data = [];
$total_consumption = 0;
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

if ($edificio_tank_id) {
    // 2. Buscar as leituras para o mês e o último dia do mês anterior
    $first_day_of_month = "$year-$month-01";
    $last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));

    $sql = "
        SELECT reading_datetime, meter_value
        FROM water_readings
        WHERE 
            tank_id = ? 
            AND ( (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? )
        ORDER BY reading_datetime ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $edificio_tank_id, $month, $year, $last_day_of_prev_month);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 3. Processar os dados para o relatório (uma leitura por dia)
    $readings_by_day = [];
    foreach ($results as $row) {
        $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
        // Guarda a primeira (e única) leitura do dia
        if (!isset($readings_by_day[$date_key])) {
            $readings_by_day[$date_key] = $row['meter_value'];
        }
    }

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));

        $leitura_atual = isset($readings_by_day[$current_date_str]) ? $readings_by_day[$current_date_str] : null;
        $leitura_anterior = isset($readings_by_day[$previous_date_str]) ? $readings_by_day[$previous_date_str] : null;
        
        $consumo = null;
        if ($leitura_atual !== null && $leitura_anterior !== null) {
            $consumo = $leitura_atual - $leitura_anterior;
            if ($consumo < 0) $consumo = 0;
            $total_consumption += $consumo;
        }

        if ($leitura_atual !== null || $leitura_anterior !== null) {
            $report_data[$day] = [
                'anterior' => $leitura_anterior,
                'atual' => $leitura_atual,
                'consumo' => $consumo
            ];
        }
    }
}
?>

<style>
    .report-table { font-size: 0.9rem; }
    .report-table thead th { background-color: #e9ecef; }
    .total-row td { font-weight: bold; background-color: #e9ecef; }
</style>
<link href="/work_log/css/sweetalert2.min.css" rel="stylesheet">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório Mensal de Consumo de Água Quente do Edifício</h1>
        <div>
            <a href="menu_relatorios.php" class="btn btn-secondary">Voltar ao Menu</a>
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
	            <div class="col-md-auto">
	                <a href="gerar_pdf_edificio.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger">
	                    <i class="fas fa-file-pdf"></i> Exportar PDF
	                </a>
	            </div>
	        </form>
	    </div>
	</div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover text-center report-table">
                <thead class="table-light">
				    <tr>
				        <th>Dia</th>
				        <th>Leitura Anterior (m³)</th>
				        <th>Leitura Atual (m³)</th>
				        <th>Consumo 24h (m³)</th>
				        <th>Ações</th> </tr>
				</thead>
				<tbody>
				    <?php if ($edificio_tank_id && count($report_data) > 0): ?>
				        <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
				            <?php if (isset($report_data[$day])): 
				                $data = $report_data[$day];
				                $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
				            ?>
				            <tr>
				                <td><?= $day ?></td>
				                <td><?= $data['anterior'] !== null ? number_format($data['anterior'], 0, ',', '.') : 'N/A' ?></td>
				                <td><?= $data['atual'] !== null ? number_format($data['atual'], 0, ',', '.') : 'N/A' ?></td>
				                <td><?= $data['consumo'] !== null ? number_format($data['consumo'], 0, ',', '.') : 'N/A' ?></td>
				                <td>
				                     <a href="#" onclick="confirmEdit('form_editar_edificio.php?date=<?= $current_date_str ?>')" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>

				                </td>
				            </tr>
				            <?php endif; ?>
				        <?php endfor; ?>
				    <?php else: ?>
				        <?php endif; ?>
				</tbody>
                <tfoot class="table-light fw-bold">
                    <tr class="total-row">
                        <td colspan="3" class="text-end">Total Consumido no Mês:</td>
                        <td><?= number_format($total_consumption, 0, ',', '.') ?> m³</td>
                    </tr>
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

<?php
require_once '../footer.php';
?>