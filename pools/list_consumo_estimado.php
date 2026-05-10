<?php
require_once '../header.php';

// Garantir tabela
$conn->query("CREATE TABLE IF NOT EXISTS `hipoclorito_diario` (
    `id`                 int(11) NOT NULL AUTO_INCREMENT,
    `tank_id`            int(11) NOT NULL,
    `data_referencia`    date NOT NULL,
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

$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$days_in_month = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
$first_day = "$year-$month-01";
$last_day  = "$year-$month-$days_in_month";

// Tanques que têm Qmax configurado
$tanks_stmt = $conn->query("
    SELECT t.id, t.name
    FROM tanks t
    INNER JOIN settings s ON s.setting_key = CONCAT('qmax_tank_', t.id)
    WHERE s.setting_value IS NOT NULL AND CAST(s.setting_value AS DECIMAL) > 0
    ORDER BY t.name ASC
");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');

// Consumos do mês
$consumos_by_tank_day = [];
$totais_tank = array_fill_keys($tank_ids, 0.0);
$total_mes = 0.0;

if (count($tank_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($tank_ids), '?'));
    $types = str_repeat('i', count($tank_ids));
    $stmt = $conn->prepare("
        SELECT tank_id, data_referencia, consumo_estimado_l, integral_dosagem, qmax_lh, n_registos
        FROM hipoclorito_diario
        WHERE tank_id IN ($placeholders)
          AND data_referencia BETWEEN ? AND ?
        ORDER BY data_referencia ASC
    ");
    $params = array_merge($tank_ids, [$first_day, $last_day]);
    $types .= 'ss';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $consumos_by_tank_day[$row['tank_id']][$row['data_referencia']] = $row;
        $totais_tank[$row['tank_id']] += (float)$row['consumo_estimado_l'];
        $total_mes += (float)$row['consumo_estimado_l'];
    }
}
?>
<style>
    .report-table { font-size: 0.8rem; }
    .report-table th, .report-table td {
        text-align: center; vertical-align: middle; padding: 4px;
        border: 1px solid #ccc;
    }
    .report-table thead th { background-color: #e9ecef; }
    .col-total { font-weight: bold; background-color: #e9ecef; }
    .cell-high { background-color: #f8d7da; font-weight: bold; color: #842029; }
    .cell-mid  { background-color: #fff3cd; }
    .cell-low  { background-color: #d1e7dd; color: #0f5132; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Consumo Estimado de Hipoclorito (Controlador)</h1>
        <div>
            <a href="menu_relatorios.php" class="btn btn-secondary">Voltar ao Menu</a>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-0 small">Mês</label>
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= htmlspecialchars($current_month) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Pesquisar</button>
                </div>
                <div class="col-auto">
                    <a href="gerar_pdf_consumo_estimado.php?month=<?= htmlspecialchars($current_month) ?>" target="_blank" class="btn btn-danger btn-sm">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                </div>
                <div class="col text-end text-muted small pt-2">
                    Período: 09:00 → 09:00 | cálculo automático diário
                </div>
            </form>
        </div>
    </div>

    <?php if (count($tanks) === 0): ?>
        <div class="alert alert-warning">
            Nenhum tanque com Qmax configurado. Configure o Qmax no modal "Integral / Consumo Hipoclorito" na página de monitorização de cada tanque.
        </div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body table-responsive p-0">
            <table class="table table-bordered table-hover text-center table-sm report-table mb-0">
                <thead>
                    <tr>
                        <th rowspan="2">Dia</th>
                        <?php foreach ($tanks as $tank): ?>
                            <th colspan="2"><?= htmlspecialchars($tank['name']) ?></th>
                        <?php endforeach; ?>
                        <th rowspan="2">Total<br>dia (L)</th>
                    </tr>
                    <tr>
                        <?php foreach ($tanks as $tank): ?>
                            <th>Int. (%-h)</th>
                            <th class="col-total">Est. (L)</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($day = 1; $day <= $days_in_month; $day++):
                        $date_str = sprintf('%s-%s-%02d', $year, $month, $day);
                        $total_dia = 0;
                    ?>
                    <tr>
                        <td class="col-total"><?= $day ?></td>
                        <?php foreach ($tanks as $tank):
                            $tid = $tank['id'];
                            $d = isset($consumos_by_tank_day[$tid][$date_str]) ? $consumos_by_tank_day[$tid][$date_str] : null;
                            $integral = $d ? number_format((float)$d['integral_dosagem'], 1) : '';
                            $consumo  = $d ? (float)$d['consumo_estimado_l'] : null;
                            $total_dia += $consumo ?? 0;
                            $css = '';
                            if ($consumo !== null) {
                                if ($consumo > 50)     $css = 'cell-high';
                                elseif ($consumo > 20) $css = 'cell-mid';
                                else                   $css = 'cell-low';
                            }
                        ?>
                            <td><?= $integral ?></td>
                            <td class="<?= $css ?>"><?= $consumo !== null ? number_format($consumo, 1) : '' ?></td>
                        <?php endforeach; ?>
                        <td class="col-total"><?= $total_dia > 0 ? number_format($total_dia, 1) : '' ?></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr class="table-dark fw-bold">
                        <td>TOTAL</td>
                        <?php foreach ($tanks as $tank): ?>
                            <td></td>
                            <td><?= number_format($totais_tank[$tank['id']], 1) ?> L</td>
                        <?php endforeach; ?>
                        <td><?= number_format($total_mes, 1) ?> L</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-3 small text-muted">
        <span class="badge" style="background:#d1e7dd;color:#0f5132">&nbsp;&nbsp;</span> ≤ 20 L &nbsp;
        <span class="badge" style="background:#fff3cd;color:#664d03">&nbsp;&nbsp;</span> 20–50 L &nbsp;
        <span class="badge" style="background:#f8d7da;color:#842029">&nbsp;&nbsp;</span> &gt; 50 L
    </div>
    <?php endif; ?>
</div>

<?php require_once '../footer.php'; ?>
