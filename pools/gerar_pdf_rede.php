<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php';

// --- Lógica de busca e processamento de dados (idêntica à da página web) ---
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
    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        $previous_date_str = date('Y-m-d', strtotime($current_date_str . ' -1 day'));
        $leitura_manha = null; $leitura_tarde = null; $consumo_24h = null; $consumo_intraday = null;
        if (isset($readings_by_day[$current_date_str])) {
            $today_readings = $readings_by_day[$current_date_str];
            $leitura_manha = $today_readings[0];
            if (count($today_readings) > 1) {
                $leitura_tarde = end($today_readings);
                $consumo_intraday = $leitura_tarde - $leitura_manha;
                if ($consumo_intraday < 0) $consumo_intraday = 0;
            }
        }
        $leitura_anterior_manha = isset($readings_by_day[$previous_date_str]) ? $readings_by_day[$previous_date_str][0] : null;
        if ($leitura_manha !== null && $leitura_anterior_manha !== null) {
            $consumo_24h = $leitura_manha - $leitura_anterior_manha;
            if ($consumo_24h < 0) $consumo_24h = 0;
        }
        if ($leitura_manha !== null || $leitura_tarde !== null) {
            $report_data[$day] = ['anterior' => $leitura_anterior_manha, 'manha' => $leitura_manha, 'tarde' => $leitura_tarde, 'consumo_24h' => $consumo_24h, 'consumo_dia' => $consumo_intraday];
            $total_consumption_24h += $consumo_24h;
            $total_consumption_intraday += $consumo_intraday;
        }
    }
}

class PDF extends FPDF {
    public $reportDate;
	public $userName;
    function Header() {
        setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
        $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode('Relatório Mensal de Consumo da Rede'), 0, 1, 'R');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 7, utf8_decode(ucfirst(strftime('%B de %Y', strtotime($this->reportDate)))), 0, 1, 'R');
        $this->Line(10, 28, 200, 28);
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

// Inicia o PDF em modo Retrato ('P')
$pdf = new PDF('P', 'mm', 'A4');
$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador Desconhecido';
$pdf->AliasNbPages();
$pdf->reportDate = $current_month;
$pdf->AddPage();

// --- Desenho da Tabela ---
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 10);
$cell_height = 7;
$col_width = (190) / 5;

// Cabeçalho
$pdf->Cell($col_width, $cell_height, 'Dia', 1, 0, 'C', true);
$pdf->Cell($col_width, $cell_height, utf8_decode('Leitura Manhã'), 1, 0, 'C', true);
$pdf->Cell($col_width, $cell_height, 'Leitura Tarde', 1, 0, 'C', true);
$pdf->Cell($col_width, $cell_height, 'Gasto Dia', 1, 0, 'C', true);
$pdf->Cell($col_width, $cell_height, 'Gasto 24h', 1, 1, 'C', true);

// Corpo
$pdf->SetFont('Arial', '', 9);
for ($day = 1; $day <= $days_in_month; $day++) {
    $data = isset($report_data[$day]) ? $report_data[$day] : null;
    $pdf->Cell($col_width, $cell_height, $day, 1, 0, 'C');
    $pdf->Cell($col_width, $cell_height, ($data && $data['manha'] !== null) ? number_format($data['manha'], 0) : '', 1, 0, 'C');
    $pdf->Cell($col_width, $cell_height, ($data && $data['tarde'] !== null) ? number_format($data['tarde'], 0) : '', 1, 0, 'C');
    $pdf->Cell($col_width, $cell_height, ($data && $data['consumo_dia'] !== null) ? number_format($data['consumo_dia'], 0) : '0', 1, 0, 'C');
    $pdf->Cell($col_width, $cell_height, ($data && $data['consumo_24h'] !== null) ? number_format($data['consumo_24h'], 0) : '0', 1, 1, 'C');
}

// Rodapé
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($col_width * 3, $cell_height, 'Totais do Mes:', 1, 0, 'R', true);
$pdf->Cell($col_width, $cell_height, number_format($total_consumption_intraday, 0), 1, 0, 'C', true);
$pdf->Cell($col_width, $cell_height, number_format($total_consumption_24h, 0), 1, 1, 'C', true);

// Envia o PDF para o browser
$pdf->Output('I', 'relatorio_rede_'. $current_month .'.pdf');
?>