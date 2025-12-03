<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de busca e processamento de dados ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE uses_hypochlorite = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');
$first_day_of_month = "$year-$month-01";
$last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));
$sql = "SELECT tank_id, reading_datetime, consumption_liters FROM hypochlorite_readings WHERE (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? ORDER BY tank_id, reading_datetime ASC";
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Erro SQL: " . $conn->error); }
$stmt->bind_param("sss", $month, $year, $last_day_of_prev_month);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0);

// 1. Organiza todas as leituras num mapa para acesso fácil
$readings_by_day = [];
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    // Guarda a última leitura do dia para cada tanque
    $readings_by_day[$row['tank_id']][$date_key] = $row['consumption_liters'];
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

        $level_today = isset($readings_by_day[$tank_id][$current_date_str]) ? $readings_by_day[$tank_id][$current_date_str] : null;
        $level_yesterday = isset($readings_by_day[$tank_id][$previous_date_str]) ? $readings_by_day[$tank_id][$previous_date_str] : null;

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
        
        if ($level_today !== null) {
            $report_data[$day][$tank_id] = [
                'level' => $level_today,
                'consumed' => $consumed_today,
                'is_refill' => $is_refill
            ];
            $tank_totals[$tank_id] += $consumed_today;
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
        // ATENÇÃO: Verifique se o caminho para o seu logotipo está correto
        $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, utf8_decode('Relatório Mensal de Consumo de Hipoclorito'), 0, 1, 'R');
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

// Definição de larguras
$col_dia_width = 10;
$col_total_width = 15;
$usable_width = 277 - $col_dia_width - $col_total_width;
$num_tanks = count($tanks);
$tank_col_width = $num_tanks > 0 ? floor($usable_width / $num_tanks) : 0;
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
        $tank_id = $tank['id'];
        $data = isset($report_data[$day][$tank_id]) ? $report_data[$day][$tank_id] : null;
        $level = ($data && isset($data['level'])) ? number_format($data['level'], 0) : '';
        $consumed = ($data && isset($data['consumed'])) ? number_format($data['consumed'], 0) : '0';
        
        if ($data && isset($data['consumed'])) {
            $daily_total += $data['consumed'];
        }
        
        // ALTERAÇÃO: Verifica se é um dia de reabastecimento para mudar a cor
        $is_refill = ($data && isset($data['is_refill']) && $data['is_refill']);
        if ($is_refill) {
            $pdf->SetFillColor(209, 231, 221); // Tom de verde claro
        }
        
        // A flag 'true' no final da Cell indica que a cor de fundo deve ser pintada
        $pdf->Cell($sub_col_width1, $cell_height, $level, 1, 0, 'C', $is_refill);
        
        // Repõe a cor de fundo para branco para a célula seguinte
        $pdf->SetFillColor(255, 255, 255);
        
        $pdf->Cell($sub_col_width2, $cell_height, $consumed, 1, 0, 'C');
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
$pdf->Output('I', 'relatorio_mensal_hipoclorito_'. $current_month .'.pdf');
?>