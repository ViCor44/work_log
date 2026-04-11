<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php';

// --- Lógica de busca e processamento de dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

// Busca apenas piscinas que têm contador de rejeitado
$tanks_stmt = $conn->query("SELECT id, name, volume_m3 FROM tanks WHERE type = 'piscina' AND has_reject_counter = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');

if (empty($tank_ids)) {
    die("Nenhuma piscina com contador de rejeitado configurada.");
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
if ($stmt === false) { die("Erro SQL: " . $conn->error); }

$types = str_repeat('i', count($tank_ids));
$bind_params = array_merge($tank_ids, [$month, $year, $last_day_of_prev_month]);
$stmt->bind_param($types . "sss", ...$bind_params);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- PROCESSAMENTO DE DADOS ---
$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0);
$readings_by_day = [];

foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    $readings_by_day[$row['tank_id']][$date_key][] = [
        'time' => $row['reading_datetime'],
        'value' => $row['meter_value']
    ];
}

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

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
                $consumption = 0;

                if ($last_level !== null && $current_level >= $last_level) {
                    $consumption = $current_level - $last_level;
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

// --- PDF CLASS ---
class PDF extends FPDF {
    public $reportDate;
    public $userName;
    
    function Header() {
        if ($this->PageNo() == 1) {
            setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
            $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, utf8_decode('Relatório de Água Rejeitada nas Piscinas'), 0, 1, 'R');
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 7, utf8_decode(ucfirst(strftime('%B de %Y', strtotime($this->reportDate)))), 0, 1, 'R');
            $this->Line(10, 28, 287, 28);
        }
        $this->Ln(8);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $cellWidth = ($this->CurPageSize[0] > $this->CurPageSize[1]) ? 95 : 60;
        $this->Cell($cellWidth, 10, 'WorkLog CMMS', 0, 0, 'L');
        $this->Cell($cellWidth, 10, utf8_decode('Impresso por: ' . $this->userName), 0, 0, 'C');
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

// --- GERAÇÃO DO PDF ---
$pdf = new PDF('L', 'mm', [297, 210]); // Paisagem A4
$pdf->reportDate = "$year-$month-01";
$pdf->userName = $_SESSION['first_name'] ?? 'Utilizador';
$pdf->AliasNbPages();
$pdf->AddPage();

// Cabeçalho da Tabela
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);
$cell_height = 4.2;

$col_dia_width = 10;
$col_total_width = 20;
$num_tanks = count($tanks);
$usable_width = 277 - $col_dia_width - $col_total_width;
$tank_col_width = $num_tanks > 0 ? floor($usable_width / $num_tanks) : 0;
$sub_col_leitura_width = floor($tank_col_width * 0.42);
$sub_col_consumo_width = floor($tank_col_width * 0.33);
$sub_col_percent_width = $tank_col_width - $sub_col_leitura_width - $sub_col_consumo_width;

$pdf->Cell($col_dia_width, $cell_height, 'Dia', 1, 0, 'C', true);

$y1 = $pdf->GetY();
$x_start_tanks = $pdf->GetX();

foreach ($tanks as $tank) {
    $x = $pdf->GetX();
    $pdf->MultiCell($tank_col_width, $cell_height, utf8_decode($tank['name']), 1, 'C', true);
    $pdf->SetXY($x + $tank_col_width, $y1);
}

$pdf->SetXY($x_start_tanks + ($tank_col_width * $num_tanks), $y1);
$pdf->Cell($col_total_width, $cell_height, utf8_decode('Total (m³)'), 1, 1, 'C', true);

$pdf->SetXY($x_start_tanks, $y1 + $cell_height);
foreach ($tanks as $tank) {
    $pdf->Cell($sub_col_leitura_width, $cell_height, 'Leitura', 1, 0, 'C', true);
    $pdf->Cell($sub_col_consumo_width, $cell_height, 'Rejeitado', 1, 0, 'C', true);
    $pdf->Cell($sub_col_percent_width, $cell_height, '% Vol.', 1, 0, 'C', true);
}
$pdf->Ln();

// Corpo da Tabela
$pdf->SetFont('Arial', '', 7);
for ($day = 1; $day <= $days_in_month; $day++) {
    $pdf->Cell($col_dia_width, $cell_height, $day, 1, 0, 'C');
    $day_total = 0;
    foreach ($tanks as $tank) {
        $tank_id = $tank['id'];
        $tank_volume = isset($tank['volume_m3']) ? (float)$tank['volume_m3'] : 0.0;
        if (isset($report_data[$day][$tank_id]['manha'])) {
            $data = $report_data[$day][$tank_id]['manha'];
            $percentage = $tank_volume > 0 ? (($data['consumo'] / $tank_volume) * 100) : null;
            $pdf->Cell($sub_col_leitura_width, $cell_height, number_format($data['leitura'], 0, ',', '.'), 1, 0, 'R');
            $pdf->Cell($sub_col_consumo_width, $cell_height, number_format($data['consumo'], 2, ',', '.'), 1, 0, 'R');
            $pdf->Cell($sub_col_percent_width, $cell_height, $percentage !== null ? number_format($percentage, 2, ',', '.') . '%' : '-', 1, 0, 'R');
            $day_total += $data['consumo'];
        } else {
            $pdf->Cell($sub_col_leitura_width, $cell_height, '-', 1, 0, 'C');
            $pdf->Cell($sub_col_consumo_width, $cell_height, '-', 1, 0, 'C');
            $pdf->Cell($sub_col_percent_width, $cell_height, '-', 1, 0, 'C');
        }
    }
    $pdf->Cell($col_total_width, $cell_height, number_format($day_total, 2, ',', '.'), 1, 1, 'R');
}

// Totais do Mês
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($col_dia_width, $cell_height, '', 1, 0, 'C', true);
$month_total = 0;
foreach ($tanks as $tank) {
    $tank_id = $tank['id'];
    $monthly_percentage = !empty($tank['volume_m3']) ? (($tank_totals[$tank_id] / (float)$tank['volume_m3']) * 100) : null;
    $pdf->Cell($sub_col_leitura_width, $cell_height, '', 1, 0, 'C', true);
    $pdf->Cell($sub_col_consumo_width, $cell_height, number_format($tank_totals[$tank_id], 2, ',', '.'), 1, 0, 'R', true);
    $pdf->Cell($sub_col_percent_width, $cell_height, $monthly_percentage !== null ? number_format($monthly_percentage, 2, ',', '.') . '%' : '-', 1, 0, 'R', true);
    $month_total += $tank_totals[$tank_id];
}
$pdf->Cell($col_total_width, $cell_height, number_format($month_total, 2, ',', '.'), 1, 1, 'R', true);

$pdf->Output('I', 'relatorio_agua_rejeitada_' . $current_month . '.pdf');
?>
