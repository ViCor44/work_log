<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de busca e processamento de dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);

// ALTERAÇÃO 1: Query SQL para buscar os tanques corretos
$tanks_stmt = $conn->query("
    SELECT id, name FROM tanks 
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
    
    // ALTERAÇÃO 2: A query busca dados da tabela 'water_readings'
    $sql = "
        SELECT tank_id, reading_datetime, meter_value 
        FROM water_readings 
        WHERE tank_id IN ($placeholders) 
        AND ( (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? )
        ORDER BY tank_id, reading_datetime ASC
    ";
    $stmt = $conn->prepare($sql);
    $params = array_merge($tank_ids, [$month, $year, $last_day_of_prev_month]);
    $stmt->bind_param($types . "sss", ...$params);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $readings_by_day = [];
    foreach ($results as $row) {
        $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
        if (!isset($readings_by_day[$row['tank_id']][$date_key])) {
            $readings_by_day[$row['tank_id']][$date_key] = (float)$row['meter_value'];
        }
    }
    
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($day = 1; $day <= $days_in_month; $day++) {
        foreach ($tanks as $tank) {
            $tank_id = $tank['id'];
            $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
            $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));
            $leitura_atual = isset($readings_by_day[$tank_id][$current_date_str]) ? $readings_by_day[$tank_id][$current_date_str] : null;
            $leitura_anterior = isset($readings_by_day[$tank_id][$previous_date_str]) ? $readings_by_day[$tank_id][$previous_date_str] : null;
            $consumo = null;
            if ($leitura_atual !== null && $leitura_anterior !== null) {
                $consumo = $leitura_atual - $leitura_anterior;
                if ($consumo < 0) $consumo = 0;
            }
            if ($leitura_atual !== null) {
                $report_data[$day][$tank_id] = ['leitura' => $leitura_atual, 'consumo' => $consumo];
                if ($consumo !== null) { $tank_totals[$tank_id] += $consumo; }
            }
        }
    }
}

// --- Fim da Lógica de Dados ---

// Classe para o PDF com cabeçalho e rodapé personalizados
class PDF extends FPDF {
    public $reportDate;
	public $userName;
    function Header() {
        setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
        $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode('Relatório de Consumo de Outros Tanques'), 0, 1, 'R');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 7, utf8_decode(ucfirst(strftime('%B de %Y', strtotime($this->reportDate)))), 0, 1, 'R');
        $this->Line(10, 28, 287, 28);
        $this->Ln(8);
    }
	function Footer() {
	    $this->SetY(-15);
	    $this->SetFont('Arial', 'I', 8);
	    
	    // A largura das células pode precisar de ajuste para os PDFs em modo Retrato
	    // Para 'L' (Paisagem), 95 está bom. Para 'P' (Retrato), use um valor menor, como 60.
	    $cellWidth = ($this->CurPageSize[0] > $this->CurPageSize[1]) ? 95 : 60;
	
	    $this->Cell($cellWidth, 10, 'WorkLog CMMS', 0, 0, 'L');
	    $this->Cell($cellWidth, 10, utf8_decode('Impresso por: ' . $this->userName), 0, 0, 'C');
	    $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
	}
}

// Inicia o PDF em modo Paisagem ('L')
$pdf = new PDF('L', 'mm', 'A4');
$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador Desconhecido';
$pdf->AliasNbPages();
$pdf->reportDate = $current_month;
$pdf->AddPage();

// --- Desenho da Tabela ---
$pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 7);
    $cell_height = 4.2; 
    $col_dia_width = 10;
    $col_total_width = 15;
    $usable_width = 277 - $col_dia_width - $col_total_width;
    $tank_col_width = floor($usable_width / count($tanks));
    $sub_col_width1 = floor($tank_col_width / 2);
    $sub_col_width2 = $tank_col_width - $sub_col_width1;

// --- Lógica de Desenho do Cabeçalho Corrigida ---
// PRIMEIRA LINHA DO CABEÇALHO
$pdf->Cell($col_dia_width, $cell_height, 'Dia', 'TLR', 0, 'C', true);
foreach ($tanks as $tank) {
    $pdf->Cell($tank_col_width, $cell_height, utf8_decode($tank['name']), 1, 0, 'C', true);
}
$pdf->Cell($col_total_width, $cell_height, 'Total Gasto', 'LTR', 1, 'C', true);

// SEGUNDA LINHA DO CABEÇALHO
$pdf->Cell($col_dia_width, $cell_height, '', 'LRB', 0, 'C', true); // Célula vazia debaixo de "Dia"
foreach ($tanks as $tank) {
    $pdf->Cell($sub_col_width1, $cell_height, 'Exist.', 1, 0, 'C', true);
    $pdf->Cell($sub_col_width2, $cell_height, 'Gasto', 1, 0, 'C', true);
}
$pdf->Cell($col_total_width, $cell_height, '(Litros)', 'LRB', 1, 'C', true);

// --- Corpo da Tabela ---
$pdf->SetFont('Arial', '', 7);
    for ($day = 1; $day <= $days_in_month; $day++) {
        $pdf->Cell($col_dia_width, $cell_height, $day, 1, 0, 'C');
        $daily_total = 0;
        foreach ($tanks as $tank) {
            $data = isset($report_data[$day][$tank['id']]) ? $report_data[$day][$tank['id']] : null;
            $leitura = ($data && isset($data['leitura'])) ? number_format($data['leitura'], 0) : '';
            $consumo = ($data && isset($data['consumo'])) ? number_format($data['consumo'], 0) : '0';
            if ($data && isset($data['consumo'])) { $daily_total += $data['consumo']; }
            $pdf->Cell($sub_col_width1, $cell_height, $leitura, 1, 0, 'C');
            $pdf->Cell($sub_col_width2, $cell_height, $consumo, 1, 0, 'C');
        }
        $pdf->Cell($col_total_width, $cell_height, $daily_total > 0 ? number_format($daily_total, 0) : '0', 1, 1, 'C');
    }

// --- Rodapé da Tabela (Totais do Mês) ---
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($col_dia_width, $cell_height, 'Total', 1, 0, 'C', true);
$grand_total = 0;
foreach ($tanks as $tank) {
    $tank_id = $tank['id'];
    $total_gasto = isset($tank_totals[$tank_id]) ? $tank_totals[$tank_id] : 0;
    $grand_total += $total_gasto;
    $pdf->Cell($sub_col_width1, $cell_height, '', 1, 0, 'C', true);
    $pdf->Cell($sub_col_width2, $cell_height, $total_gasto > 0 ? number_format($total_gasto, 0) : '', 1, 0, 'C', true);
}
$pdf->Cell($col_total_width, $cell_height, $grand_total > 0 ? number_format($grand_total, 0) : '', 1, 1, 'C', true);

// Envia o PDF para o browser
$pdf->Output('I', 'relatorio_outros_tanques_'. $current_month .'.pdf');
?>