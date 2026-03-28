<?php
require_once '../header.php';

// --- Lógica de Filtragem e Busca de Dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE uses_hypochlorite = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');

// Busca todos os registos do mês selecionado E do último dia do mês anterior
$first_day_of_month = "$year-$month-01";
$last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));

$sql = "
    SELECT id, tank_id, reading_datetime, consumption_liters 
    FROM hypochlorite_readings 
    WHERE (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? 
    ORDER BY tank_id, reading_datetime ASC
";
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Erro SQL: " . $conn->error); }
$stmt->bind_param("sss", $month, $year, $last_day_of_prev_month);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ====================================================================
// == LÓGICA DE PROCESSAMENTO DE DADOS FINAL E CORRIGIDA ==
// ====================================================================
$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0.0);

// 1. Organiza todas as leituras num mapa para acesso fácil
$readings_by_day = [];
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    // Guarda a última leitura do dia para cada tanque
    $readings_by_day[$row['tank_id']][$date_key] = [
        'id' => $row['id'],
        'level' => (float)$row['consumption_liters']
    ];
}

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// 2. Loop principal para calcular o consumo diário
for ($day = 1; $day <= $days_in_month; $day++) {
    $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
    $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));

    foreach ($tanks as $tank) {
        $tank_id = $tank['id'];
        $consumed_today = 0;
        $is_refill = false;

        $level_today = isset($readings_by_day[$tank_id][$current_date_str]['level']) ? $readings_by_day[$tank_id][$current_date_str]['level'] : null;
        $id_today = isset($readings_by_day[$tank_id][$current_date_str]['id']) ? $readings_by_day[$tank_id][$current_date_str]['id'] : null;
        
        // A leitura de referência é a do dia anterior, seja de que mês for
        $level_yesterday = isset($readings_by_day[$tank_id][$previous_date_str]['level']) ? $readings_by_day[$tank_id][$previous_date_str]['level'] : null;

        if ($level_today !== null && $level_yesterday !== null) {
            if ($level_today > $level_yesterday) {
                // É um reabastecimento
                $is_refill = true;
                // Procura o consumo do dia anterior na matriz já processada
                $consumed_today = isset($report_data[$day - 1][$tank_id]['consumed']) ? $report_data[$day - 1][$tank_id]['consumed'] : 0;
            } else {
                // Consumo normal
                $consumed_today = $level_yesterday - $level_today;
            }
        }
        
        // Guarda os dados apenas se houver uma leitura no dia de hoje
        if ($level_today !== null) {
            $report_data[$day][$tank_id] = [
                'id' => $id_today,
                'level' => $level_today,
                'consumed' => $consumed_today,
                'is_refill' => $is_refill
            ];
            $tank_totals[$tank_id] += $consumed_today;
        }
    }
}
// --- Fim da Lógica de Dados ---
?>
<style>
    .report-table { font-size: 0.8rem; }
    .report-table th, .report-table td { 
        text-align: center; 
        vertical-align: middle; 
        padding: 4px; 
        border: 1px solid #ccc; /* Linhas da grelha um pouco mais escuras */
    }
    /* Cor de fundo do cabeçalho */
    .report-table thead th { 
        background-color: #e9ecef; /* Cinzento claro standard */
    }
    /* Estilos para as colunas de 'Gasto' e 'Total' */
    .col-gasto, .total-row, .total-col { 
        font-weight: bold; 
        background-color: #e9ecef; 
    }
	.col-exist {
		font-size: 1.0rem;
	}
    /* Estilo para destacar os reabastecimentos a verde */
    .cell-refill {
        background-color: #d1e7dd !important; 
        font-weight: bold;
        color: #0f5132;
    }
</style>
<link href="/work_log/css/sweetalert2.min.css" rel="stylesheet">
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório Mensal de Consumo de Hipoclorito</h1>
        <div>
            <a href="form_hipoclorito.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Registo</a>
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
                    <a href="gerar_pdf_hipoclorito.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover text-center table-sm report-table">
                <thead class="text-center">
                    <tr>
                        <th rowspan="2">Dia</th>
                        <?php foreach ($tanks as $tank): ?>
                            <th colspan="2"><?= htmlspecialchars($tank['name']) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total Gasto</th>
                        <th rowspan="2">Ações</th>
                    </tr>
                    <tr>
                        <?php foreach ($tanks as $tank): ?>
                            <th>Exist.</th>
                            <th class="col-gasto">Gasto</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
               <tbody>
				    <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
				        <tr>
				            <td class="total-col"><?= $day ?></td>
				            <?php
				            $daily_total = 0;
				            $has_data_today = false; // Flag para saber se há dados para mostrar o botão
				
				            foreach ($tanks as $tank):
				                $tank_id = $tank['id'];
				                $data = isset($report_data[$day][$tank_id]) ? $report_data[$day][$tank_id] : null;
				                
				                // Se houver qualquer dado para este dia, marcamos como verdadeiro
				                if ($data) { $has_data_today = true; }
				
				                $level = ($data && isset($data['level'])) ? number_format($data['level'], 0) : '';
				                $consumed = ($data && isset($data['consumed'])) ? number_format($data['consumed'], 0) : '0';
				                
				                if ($data && isset($data['consumed'])) { 
				                    $daily_total += $data['consumed']; 
				                }
				                
				                $cell_class = ($data && isset($data['is_refill']) && $data['is_refill']) ? 'cell-refill' : '';
				            ?>
				                <td class="<?= $cell_class ?> col-exist"><?= $level ?></td>
				                <td class="col-gasto"><?= $consumed ?></td>
				            <?php endforeach; ?>
				            
				            <td class="total-col"><?= $daily_total > 0 ? number_format($daily_total, 0) : '0' ?></td>
				            <td>
							    <?php 
							    // Monta a data completa no formato AAAA-MM-DD para o link
							    $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
							    $button_class = $has_data_today ? 'btn-warning' : 'btn-primary';
							    $button_icon = $has_data_today ? 'fa-edit' : 'fa-plus';
							    $confirm_text = $has_data_today ? 'editar um registo' : 'criar um novo registo';
							    $confirm_button_text = $has_data_today ? 'Sim, continuar para edição' : 'Sim, continuar para criação';
							    ?>
							    <a href="#" onclick="confirmAction('form_hipoclorito.php?date=<?= $current_date_str ?>', '<?= $confirm_text ?>', '<?= $confirm_button_text ?>')" class="btn btn-sm <?= $button_class ?>">
							        <i class="fas <?= $button_icon ?>"></i>
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
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<script src="/work_log/js/sweetalert2.all.min.js"></script>
<script>
	function confirmAction(editUrl, actionText, confirmBtnText) {
	    Swal.fire({
	        title: 'Atenção: ' + (actionText.includes('editar') ? 'Área de Edição' : 'Área de Criação'),
	        text: "Está prestes a " + actionText + ". Todas as alterações serão guardadas e registadas no log do sistema para auditoria. Deseja continuar?",
	        icon: 'warning',
	        showCancelButton: true,
	        confirmButtonColor: '#ffc107', // Cor de aviso do Bootstrap
	        cancelButtonColor: '#6c757d',  // Cor secundária
	        confirmButtonText: confirmBtnText,
	        cancelButtonText: 'Cancelar'
	    }).then((result) => {
	        // Se o utilizador confirmar, redireciona para a página de edição/criação
	        if (result.isConfirmed) {
	            window.location.href = editUrl;
	        }
	    });
	}
</script>
<?php require_once '../footer.php'; ?>