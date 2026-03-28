<?php
require_once '../header.php';

// --- Lógica de busca e processamento de dados (sem alterações) ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'piscina' AND water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');
$first_day_of_month = "$year-$month-01";
$last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));
$sql = "
    SELECT 
        tank_id,
        reading_datetime,
        meter_value
    FROM water_readings
    WHERE 
        (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) 
        OR DATE(reading_datetime) = ?
    ORDER BY tank_id, reading_datetime ASC
";
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Erro ao preparar a consulta SQL: " . $conn->error); }
$stmt->bind_param("sss", $month, $year, $last_day_of_prev_month);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// --- NOVO BLOCO DE PROCESSAMENTO DE DADOS (MAIS SIMPLES E CORRETO) ---
// --- NOVO BLOCO DE PROCESSAMENTO DE DADOS (MAIS SIMPLES E CORRETO) ---
$report_data = [];
// CORREÇÃO 1: Inicializa os totais como float para evitar overflow
$tank_totals = array_fill_keys($tank_ids, 0.0); 
$readings_by_day = [];

// 1. Organiza todas as leituras por dia para cada tanque
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    // Converte para float logo na leitura da base de dados
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

    // Encontra a última leitura antes do início do mês
    if (isset($readings_by_day[$tank_id][$last_day_of_prev_month])) {
        $last_level = end($readings_by_day[$tank_id][$last_day_of_prev_month])['value'];
    }

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        
        if (isset($readings_by_day[$tank_id][$current_date_str])) {
            $today_readings = $readings_by_day[$tank_id][$current_date_str];
            
            foreach ($today_readings as $reading) {
                $current_level = $reading['value'];
                $consumption = 0.0; // Inicia como float

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
                
                // CORREÇÃO 2: A soma agora é feita com floats
                $tank_totals[$tank_id] += $consumption;
                
                $last_level = $current_level;
            }
        }
    }
}
?>

<style>

    /* Estilos (sem alterações) */
    .report-table { font-size: 0.8rem; }
    .report-table th, .report-table td { text-align: center; vertical-align: middle; padding: 4px; border: 1px solid #dee2e6; }
    .report-table thead th { background-color: #f8f9fa; }
    .col-gasto { background-color: #e9ecef; }
    .total-col, .total-row { font-weight: bold; background-color: #e9ecef; }

</style>
<link href="/work_log/css/sweetalert2.min.css" rel="stylesheet">
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório Mensal de Consumo de Água (Piscinas)</h1>
        <div>
            <a href="form_agua_manha.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Registo</a>
            <a href="registos.php" class="btn btn-secondary">Voltar</a>
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
                    <a href="gerar_pdf_agua.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover text-center table-sm">
                <thead class="text-center">
                    <tr>
                        <th rowspan="2">Dia</th>
                        <th rowspan="2">Período</th>
                        <?php foreach ($tanks as $tank): ?>
                            <th colspan="2"><?= htmlspecialchars($tank['name']) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total (m³)</th>
						<th rowspan="2">Ações</th> </tr>
                    </tr>
                    <tr>
                        <?php foreach ($tanks as $tank): ?>
                            <th>Leitura</th>
                            <th class="col-consumo">Consumo</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
               <tbody>
				    <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
				        <tr>
				            <td rowspan="2" class="day-cell total-col"><?= $day ?></td>
				            <td>Manhã</td>
				            <?php
				            $daily_total = 0;
				            $has_data_today = false; // Flag para saber se existe algum dado neste dia
				
				            foreach ($tanks as $tank):
				                $tank_id = $tank['id'];
				                $manha_data = isset($report_data[$day][$tank_id]['manha']) ? $report_data[$day][$tank_id]['manha'] : null;
				                $tarde_data = isset($report_data[$day][$tank_id]['tarde']) ? $report_data[$day][$tank_id]['tarde'] : null;
				                
				                if ($manha_data) { $has_data_today = true; }
				
				                // O total diário é a soma de todos os consumos do dia
				                if (isset($manha_data['consumo'])) {
				                    $daily_total += $manha_data['consumo'];
				                }
				                if (isset($tarde_data['consumo'])) {
				                    $daily_total += $tarde_data['consumo'];
				                }
				            ?>
				                <td><?= $manha_data ? number_format($manha_data['leitura']) : '' ?></td>
				                <td class="col-consumo"><?= $manha_data ? number_format($manha_data['consumo']) : '' ?></td>
				            <?php endforeach; ?>
				
				            <td rowspan="2" class="day-cell total-col"><?= number_format($daily_total) ?></td>
				            
				            <td rowspan="2" class="day-cell">
							    <?php
							    // Construir a string da data para a linha atual
							    $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
							    $today_date_str = date('Y-m-d');
							
							    // Mostra o botão de editar/adicionar para o dia atual e dias passados.
							    // Assim, pode adicionar registos para dias que se esqueceu.
							    if ($current_date_str <= $today_date_str) {
							    ?>
							        <a href="#" onclick="confirmEdit('form_editar_agua_piscinas.php?date=<?= $current_date_str ?>')" class="btn btn-sm btn-warning" title="Editar/Adicionar Registo do Dia <?= $day ?>"><i class="fas fa-edit"></i></a>
							    <?php
							    }
							    ?>
							</td>
				        </tr>
				        <tr class="row-tarde">
				            <td>Tarde</td>
				            <?php foreach ($tanks as $tank): ?>
				                <?php $data = isset($report_data[$day][$tank['id']]['tarde']) ? $report_data[$day][$tank['id']]['tarde'] : null; ?>
				                <td><?= $data ? number_format($data['leitura']) : '' ?></td>
				                <td class="col-consumo"><?= $data ? number_format($data['consumo']) : '' ?></td>
				            <?php endforeach; ?>
				        </tr>
				    <?php endfor; ?>
				</tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2">Total Mensal</td>
                        <?php
                        $grand_total = 0;
                        foreach ($tanks as $tank):
                            $tank_id = $tank['id'];
                            $total_gasto = isset($tank_totals[$tank_id]) ? $tank_totals[$tank_id] : 0;
                            $grand_total += $total_gasto;
                        ?>
                            <td></td> <td class="col-consumo"><?= $total_gasto > 0 ? number_format($total_gasto, 0, ',', '.') : '' ?></td>
                        <?php endforeach; ?>
                        <td><?= $grand_total > 0 ? number_format($grand_total, 0, ',', '.') : '' ?></td>
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