<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de Filtros ---
// Determina a semana atual se nenhuma for selecionada
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$week = isset($_GET['week']) ? $_GET['week'] : date('W');

// Encontra as datas de início e fim da semana selecionada
$start_of_week = new DateTime();
$start_of_week->setISODate($year, $week);
$start_date_str = $start_of_week->format('Y-m-d');

$end_of_week_obj = clone $start_of_week;
$end_of_week_obj->modify('+6 days');
$end_date_str = $end_of_week_obj->format('Y-m-d');

$day_before_start_obj = clone $start_of_week;
$day_before_start_obj->modify('-1 day');
$day_before_start = $day_before_start_obj->format('Y-m-d');

// --- Lógica de Busca e Processamento de Dados ---
// 1. Buscar TODOS os tanques com contagem de água
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// 2. Buscar as leituras da semana selecionada e do dia anterior ao início
$sql = "
    SELECT tank_id, reading_datetime, meter_value
    FROM water_readings
    WHERE DATE(reading_datetime) BETWEEN ? AND ?
    ORDER BY tank_id, reading_datetime ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $day_before_start, $end_date_str);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3. Processar os dados
$report_data = [];
$readings_by_day = [];
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    // Guarda a primeira leitura do dia
    if (!isset($readings_by_day[$row['tank_id']][$date_key])) {
        $readings_by_day[$row['tank_id']][$date_key] = $row['meter_value'];
    }
}

for ($i = 0; $i < 7; $i++) {
    $current_date_obj = clone $start_of_week;
    $current_date_obj->modify("+$i days");
    $current_date_str = $current_date_obj->format('Y-m-d');

    $prev_date_obj = clone $current_date_obj;
    $prev_date_obj->modify('-1 day');
    $prev_date_str = $prev_date_obj->format('Y-m-d');
    
    foreach ($tanks as $tank) {
        $tank_id = $tank['id'];
        $leitura = isset($readings_by_day[$tank_id][$current_date_str]) ? $readings_by_day[$tank_id][$current_date_str] : null;
        $leitura_ant = isset($readings_by_day[$tank_id][$prev_date_str]) ? $readings_by_day[$tank_id][$prev_date_str] : null;
        
        $consumo = null;
        if ($leitura !== null && $leitura_ant !== null) {
            $consumo = $leitura - $leitura_ant;
            if ($consumo < 0) $consumo = 0;
        }
        
        $report_data[$tank_id][$i] = [
            'leitura' => $leitura,
            'consumo' => $consumo
        ];
    }
}
// --- Fim da lógica de dados ---

class PDF extends FPDF {
    public $reportDateStr; // Vamos passar a string da data formatada
	public $userName;
    function Header() {
        setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
        $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode('Relatório Semanal de Contadores de Água'), 0, 1, 'R');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 7, utf8_decode($this->reportDateStr), 0, 1, 'R');
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
$pdf->reportDateStr = "Semana de " . $start_date_str . " a " . $end_date_str;
$pdf->AddPage();

// --- Desenho da Tabela ---
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);
$cell_height = 4;

// Larguras
$col_tanque_width = 25;
$col_total_width = 15;
$num_days = 7;
$usable_width = 281 - $col_tanque_width - $col_total_width;
$day_col_width = floor($usable_width / $num_days);
$sub_col_width = floor($day_col_width / 2);

// Cabeçalho da Tabela
$pdf->Cell($col_tanque_width, $cell_height * 2, 'Tanque', 1, 0, 'C', true);
$dias_semana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
for ($i = 0; $i < 7; $i++) {
    $header_date_obj = clone $start_of_week;
    $header_date_obj->modify("+$i days");
    $header_date_str = $header_date_obj->format('d/m');
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->MultiCell($day_col_width, $cell_height, utf8_decode($dias_semana[$i]) . "\n" . $header_date_str, 1, 'C', true);
    $pdf->SetXY($x + $day_col_width, $y);
}
$pdf->Cell($col_total_width, $cell_height * 2, 'Total', 1, 1, 'C', true);
$pdf->SetXY(10 + $col_tanque_width, $pdf->GetY());
for ($i = 0; $i < 7; $i++) {
    $pdf->Cell($sub_col_width, $cell_height, 'Leitura', 1, 0, 'C', true);
    $pdf->Cell($sub_col_width, $cell_height, 'Consumo', 1, 0, 'C', true);
}
$pdf->Ln();

// Corpo da Tabela
$pdf->SetFont('Arial', '', 7);
foreach ($tanks as $tank) {
    $pdf->Cell($col_tanque_width, $cell_height, utf8_decode($tank['name']), 1, 0, 'L');
    $weekly_total = 0;
    for ($i = 0; $i < 7; $i++) {
        $data = $report_data[$tank['id']][$i];
        $leitura = $data['leitura'] !== null ? number_format($data['leitura'], 0) : '';
        $consumo = $data['consumo'] !== null ? number_format($data['consumo'], 0) : '';
        if ($data['consumo'] !== null) $weekly_total += $data['consumo'];
        $pdf->Cell($sub_col_width, $cell_height, $leitura, 1, 0, 'C');
        $pdf->Cell($sub_col_width, $cell_height, $consumo, 1, 0, 'C');
    }
    $pdf->Cell($col_total_width, $cell_height, number_format($weekly_total, 0), 1, 1, 'C');
}

// Envia o PDF para o browser
$pdf->Output('I', 'relatorio_semanal_total_'. $year . '_W' . $week .'.pdf');
?>