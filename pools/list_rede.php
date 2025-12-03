<?php
require_once '../header.php';

// --- Lógica de Filtragem e Busca de Dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

$stmt_tank = $conn->prepare("SELECT id FROM tanks WHERE name = 'Rede' LIMIT 1");
$stmt_tank->execute();
$result_tank = $stmt_tank->get_result();
$rede_tank_id = null;
if ($result_tank->num_rows > 0) {
    $rede_tank_id = $result_tank->fetch_assoc()['id'];
}
$stmt_tank->close();

$report_data = [];
$total_consumption_24h = 0;
$total_consumption_intraday = 0;
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

if ($rede_tank_id) {
    $first_day_of_month = "$year-$month-01";
    $last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));
    $sql = "SELECT reading_datetime, meter_value FROM water_readings WHERE tank_id = ? AND ( (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? ) ORDER BY reading_datetime ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $rede_tank_id, $month, $year, $last_day_of_prev_month);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $readings_by_day = [];
    foreach ($results as $row) {
        $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
        if (!isset($readings_by_day[$date_key])) {
            $readings_by_day[$date_key] = [];
        }
        $readings_by_day[$date_key][] = $row['meter_value'];
    }

    // Inicializar todos os dias do mês no report_data com valores padrão
    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        $report_data[$day] = [
            'anterior' => null,
            'manha' => null,
            'tarde' => null,
            'consumo_24h' => 0,
            'consumo_dia' => 0
        ];
    }

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));

        $leitura_manha = null;
        $leitura_tarde = null;
        $consumo_24h = null;
        $consumo_intraday = null;

        if (isset($readings_by_day[$current_date_str])) {
            $today_readings = $readings_by_day[$current_date_str];
            $leitura_manha = $today_readings[0];
            if (count($today_readings) > 1) {
                $leitura_tarde = end($today_readings);
                // Calcular o consumo intra-diário
                $consumo_intraday = $leitura_tarde - $leitura_manha;
                if ($consumo_intraday < 0) $consumo_intraday = 0;
            }
        }
        
        $leitura_anterior_manha = isset($readings_by_day[$previous_date_str]) ? $readings_by_day[$previous_date_str][0] : null;
        
        if ($leitura_manha !== null && $leitura_anterior_manha !== null) {
            $consumo_24h = $leitura_manha - $leitura_anterior_manha;
            if ($consumo_24h < 0) $consumo_24h = 0;
            $total_consumption_24h += $consumo_24h;
        }
        
        if ($consumo_intraday !== null) {
            $total_consumption_intraday += $consumo_intraday;
        }

        // Atualizar os dados no report_data apenas se houver leituras
        if ($leitura_manha !== null || $leitura_tarde !== null) {
            $report_data[$day] = [
                'anterior' => $leitura_anterior_manha,
                'manha' => $leitura_manha,
                'tarde' => $leitura_tarde,
                'consumo_24h' => isset($consumo_24h) ? $consumo_24h : 0,
                'consumo_dia' => isset($consumo_intraday) ? $consumo_intraday : 0,
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
        <h1 class="h3 mb-0">Relatório Mensal de Consumo de Água da Rede</h1>
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
                    <a href="gerar_pdf_rede.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Exportar PDF
                    </a>
                </div>
            </form>
        </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover text-center report-table">
                <thead class="table-light">
				    <tr>
				        <th>Dia</th>
				        <th>Leitura Manhã (m³)</th>
				        <th>Leitura Tarde (m³)</th>
				        <th>Consumo Intra-diário (m³)</th>
				        <th>Consumo 24h (m³)</th>
				        <th>Ações</th>
                    </tr>
				</thead>
				<tbody>
				    <?php if ($rede_tank_id): ?>
				        <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
				            <?php
				            $data = $report_data[$day];
				            $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
				            $has_data = ($data['manha'] !== null || $data['tarde'] !== null);
				            ?>
				            <tr>
				                <td><?= $day ?></td>
				                <td><?= $data['manha'] !== null ? number_format($data['manha'], 0) : '' ?></td>
				                <td><?= $data['tarde'] !== null ? number_format($data['tarde'], 0) : '' ?></td>
				                <td><?= $data['consumo_dia'] !== null ? number_format($data['consumo_dia'], 0) : '0' ?></td>
				                <td><?= $data['consumo_24h'] !== null ? number_format($data['consumo_24h'], 0) : '0' ?></td>
				                <td>
				                    <?php
				                    $button_class = $has_data ? 'btn-warning' : 'btn-primary';
				                    $button_icon = $has_data ? 'fa-edit' : 'fa-plus';
				                    $confirm_text = $has_data ? 'editar um registo' : 'criar um novo registo';
				                    $confirm_button_text = $has_data ? 'Sim, continuar para edição' : 'Sim, continuar para criação';
				                    ?>
				                    <a href="#" onclick="confirmAction('form_editar_rede.php?date=<?= $current_date_str ?>', '<?= $confirm_text ?>', '<?= $confirm_button_text ?>')" class="btn btn-sm <?= $button_class ?>">
				                        <i class="fas <?= $button_icon ?>"></i>
				                    </a>
				                </td>
				            </tr>
				        <?php endfor; ?>
				    <?php else: ?>
				        <tr>
				            <td colspan="6" class="text-muted">Nenhum tanque 'Rede' encontrado...</td>
				        </tr>
				    <?php endif; ?>
				</tbody>
                <tfoot class="table-light fw-bold">
                    <tr class="total-row">
                        <td colspan="3" class="text-end">Totais do Mês:</td>
                        <td><?= number_format($total_consumption_intraday, 0, ',', '.') ?> m³</td>
                        <td><?= number_format($total_consumption_24h, 0, ',', '.') ?> m³</td>
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
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmBtnText,
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = editUrl;
            }
        });
    }
</script>
<?php
require_once '../footer.php';
?>