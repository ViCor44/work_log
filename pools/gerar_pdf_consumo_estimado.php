<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php';

$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$days_in_month = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
$first_day = "$year-$month-01";
$last_day  = "$year-$month-$days_in_month";

// Tanques com Qmax
$tanks_stmt = $conn->query("
    SELECT t.id, t.name
    FROM tanks t
    INNER JOIN settings s ON s.setting_key = CONCAT('qmax_tank_', t.id)
    WHERE s.setting_value IS NOT NULL AND CAST(s.setting_value AS DECIMAL) > 0
    ORDER BY t.name ASC
");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');

$consumos_by_tank_day = [];
$totais_tank = array_fill_keys($tank_ids, 0.0);
$total_mes = 0.0;

if (count($tank_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($tank_ids), '?'));
    $types = str_repeat('i', count($tank_ids));
    $stmt = $conn->prepare("
        SELECT tank_id, data_referencia, consumo_estimado_l, integral_dosagem, qmax_lh
        FROM hipoclorito_diario
        WHERE tank_id IN ($placeholders) AND data_referencia BETWEEN ? AND ?
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

class PDF extends FPDF {
    public $reportDate;
    public $userName;
    function Header() {
        $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 10, utf8_decode('Consumo Estimado de Hipoclorito (Controlador)'), 0, 1, 'R');
        $this->SetFont('Arial', '', 11);
        $months_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        list($y, $m) = explode('-', $this->reportDate);
        $this->Cell(0, 6, utf8_decode($months_pt[(int)$m] . ' de ' . $y . ' | Período: 09:00 → 09:00'), 0, 1, 'R');
        $this->Line(10, 28, 287, 28);
        $this->Ln(6);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $w = ($this->CurPageSize[0] > $this->CurPageSize[1]) ? 95 : 60;
        $this->Cell($w, 10, 'WorkLog CMMS', 0, 0, 'L');
        $this->Cell($w, 10, utf8_decode('Impresso por: ' . $this->userName), 0, 0, 'C');
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

$pdf = new PDF('L', 'mm', 'A4');
$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador';
$pdf->AliasNbPages();
$pdf->reportDate = $current_month;
$pdf->AddPage();

$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);
$cell_h = 4.2;

$col_dia   = 10;
$col_total = 18;
$usable    = 277 - $col_dia - $col_total;
$n_tanks   = count($tanks);
$tank_w    = $n_tanks > 0 ? floor($usable / $n_tanks) : 0;
$sub_w1    = floor($tank_w * 0.45); // integral
$sub_w2    = $tank_w - $sub_w1;     // estimativa

// Cabeçalho linha 1
$pdf->Cell($col_dia, $cell_h, 'Dia', 'TLR', 0, 'C', true);
foreach ($tanks as $tank) {
    $pdf->Cell($tank_w, $cell_h, utf8_decode($tank['name']), 1, 0, 'C', true);
}
$pdf->Cell($col_total, $cell_h, 'Total (L)', 'LTR', 1, 'C', true);

// Cabeçalho linha 2
$pdf->Cell($col_dia, $cell_h, '', 'LRB', 0, 'C', true);
foreach ($tanks as $tank) {
    $pdf->Cell($sub_w1, $cell_h, 'Int.%-h', 1, 0, 'C', true);
    $pdf->Cell($sub_w2, $cell_h, 'Est.(L)', 1, 0, 'C', true);
}
$pdf->Cell($col_total, $cell_h, '', 'LRB', 1, 'C', true);

// Corpo
$pdf->SetFont('Arial', '', 7);
for ($day = 1; $day <= $days_in_month; $day++) {
    $date_str = sprintf('%s-%s-%02d', $year, $month, $day);
    $pdf->Cell($col_dia, $cell_h, $day, 1, 0, 'C');
    $total_dia = 0;
    foreach ($tanks as $tank) {
        $tid = $tank['id'];
        $d = isset($consumos_by_tank_day[$tid][$date_str]) ? $consumos_by_tank_day[$tid][$date_str] : null;
        $integral = $d ? number_format((float)$d['integral_dosagem'], 1) : '';
        $consumo  = $d ? (float)$d['consumo_estimado_l'] : null;
        $total_dia += $consumo ?? 0;

        // Cor consoante consumo
        $fill = false;
        if ($consumo !== null) {
            if ($consumo > 50)     { $pdf->SetFillColor(248, 215, 218); $fill = true; }
            elseif ($consumo > 20) { $pdf->SetFillColor(255, 243, 205); $fill = true; }
            else                   { $pdf->SetFillColor(209, 231, 221); $fill = true; }
        }
        $pdf->Cell($sub_w1, $cell_h, $integral, 1, 0, 'C');
        $pdf->SetFillColor(255,255,255);
        $pdf->Cell($sub_w2, $cell_h, $consumo !== null ? number_format($consumo, 1) : '', 1, 0, 'C', $fill);
        $pdf->SetFillColor(255,255,255);
    }
    $pdf->Cell($col_total, $cell_h, $total_dia > 0 ? number_format($total_dia, 1) : '', 1, 1, 'C');
}

// Rodapé totais
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell($col_dia, $cell_h, 'TOTAL', 1, 0, 'C', true);
foreach ($tanks as $tank) {
    $t = isset($totais_tank[$tank['id']]) ? $totais_tank[$tank['id']] : 0;
    $pdf->Cell($sub_w1, $cell_h, '', 1, 0, 'C', true);
    $pdf->Cell($sub_w2, $cell_h, number_format($t, 1) . ' L', 1, 0, 'C', true);
}
$pdf->Cell($col_total, $cell_h, number_format($total_mes, 1) . ' L', 1, 1, 'C', true);

$pdf->Output('I', 'consumo_estimado_' . $current_month . '.pdf');
?>
