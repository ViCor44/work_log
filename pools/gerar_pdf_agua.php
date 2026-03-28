<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de busca e processamento de dados (sem alterações) ---
$current_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
list($year, $month) = explode('-', $current_month);
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'piscina' AND water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$tank_ids = array_column($tanks, 'id');
$first_day_of_month = "$year-$month-01";
$last_day_of_prev_month = date('Y-m-d', strtotime($first_day_of_month . ' -1 day'));
$sql = "SELECT tank_id, reading_datetime, meter_value FROM water_readings WHERE (MONTH(reading_datetime) = ? AND YEAR(reading_datetime) = ?) OR DATE(reading_datetime) = ? ORDER BY tank_id, reading_datetime ASC";
$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Erro SQL: " . $conn->error); }
$stmt->bind_param("sss", $month, $year, $last_day_of_prev_month);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// --- NOVO BLOCO DE PROCESSAMENTO DE DADOS (MAIS SIMPLES E CORRETO) ---
$report_data = [];
$tank_totals = array_fill_keys($tank_ids, 0); // Total de consumo do mês
$readings_by_day = [];

// 1. Organiza todas as leituras por dia para cada tanque
foreach ($results as $row) {
    $date_key = date('Y-m-d', strtotime($row['reading_datetime']));
    $readings_by_day[$row['tank_id']][$date_key][] = [
        'time' => $row['reading_datetime'],
        'value' => $row['meter_value']
    ];
}

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// 2. Constrói a matriz final para o relatório
foreach ($tanks as $tank) {
    $tank_id = $tank['id'];
    $last_level = null; // Guarda a última leitura conhecida

    // Encontra a última leitura antes do início do mês
    if (isset($readings_by_day[$tank_id][$last_day_of_prev_month])) {
        $last_level = end($readings_by_day[$tank_id][$last_day_of_prev_month])['value'];
    }

    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date_str = sprintf('%s-%s-%02d', $year, $month, $day);
        
        if (isset($readings_by_day[$tank_id][$current_date_str])) {
            $today_readings = $readings_by_day[$tank_id][$current_date_str];
            
            // Processa cada leitura do dia
            foreach ($today_readings as $reading) {
                $current_level = $reading['value'];
                $consumption = 0;

                // Calcula o consumo se houver uma leitura anterior
                if ($last_level !== null) {
                    if ($current_level >= $last_level) {
                        $consumption = $current_level - $last_level;
                    }
                }
                
                // Determina se é Manhã ou Tarde
                $period = (date('H', strtotime($reading['time'])) < 13) ? 'manha' : 'tarde';

                $report_data[$day][$tank_id][$period] = [
                    'leitura' => $current_level,
                    'consumo' => $consumption
                ];
                $tank_totals[$tank_id] += $consumption;
                
                // Atualiza a última leitura conhecida
                $last_level = $current_level;
            }
        }
    }
}
// --- Fim da lógica de dados ---

class PDF extends FPDF {
    public $reportDate;
	public $userName;
	function Header() {
        // ALTERAÇÃO: O cabeçalho só é desenhado na primeira página
        if ($this->PageNo() == 1) {
            setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
            $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, utf8_decode('Relatório Mensal de Consumo de Água nas Piscinas'), 0, 1, 'R');
            $this->SetFont('Arial', '', 12);
            $this->Cell(0, 7, utf8_decode(ucfirst(strftime('%B de %Y', strtotime($this->reportDate)))), 0, 1, 'R');
            $this->Line(10, 28, 287, 28);
        }
        // Adiciona um espaço no topo de todas as páginas
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

// Função para desenhar a tabela para um intervalo de dias
// SUBSTITUA A SUA FUNÇÃO ANTIGA POR ESTA
function draw_table_for_days($pdf, $start_day, $end_day, $tanks, $report_data, $days_in_month) {
    // Definições da Tabela
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'B', 7);
    $cell_height = 4.2;

    // Larguras
    $col_dia_width = 10;
    $col_periodo_width = 15;
    $col_total_width = 20;
    $num_tanks = count($tanks);
    $usable_width = 277 - $col_dia_width - $col_periodo_width - $col_total_width;
    $tank_col_width = $num_tanks > 0 ? floor($usable_width / $num_tanks) : 0;
    $sub_col_leitura_width = floor($tank_col_width * 0.6);
    $sub_col_consumo_width = $tank_col_width - $sub_col_leitura_width;

    // Cabeçalho da Tabela
    $pdf->Cell($col_dia_width, $cell_height * 2, 'Dia', 1, 0, 'C', true);
    $pdf->Cell($col_periodo_width, $cell_height * 2, 'Periodo', 1, 0, 'C', true);
    $y1 = $pdf->GetY();
    $x_start_tanks = $pdf->GetX();
    foreach ($tanks as $tank) {
        $x = $pdf->GetX();
        $pdf->MultiCell($tank_col_width, $cell_height, utf8_decode($tank['name']), 1, 'C', true);
        $pdf->SetXY($x + $tank_col_width, $y1);
    }
    $pdf->SetXY($x_start_tanks + ($tank_col_width * $num_tanks), $y1);
    $pdf->Cell($col_total_width, $cell_height * 2, utf8_decode('Total (m³)'), 1, 1, 'C', true);
    $pdf->SetXY($x_start_tanks, $y1 + $cell_height);
    foreach ($tanks as $tank) {
        $pdf->Cell($sub_col_leitura_width, $cell_height, 'Leitura', 1, 0, 'C', true);
        $pdf->Cell($sub_col_consumo_width, $cell_height, 'Cons.', 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Corpo da Tabela
    // Corpo da Tabela no PDF
$pdf->SetFont('Arial', '', 7);
for ($day = $start_day; $day <= $end_day; $day++) {
    if ($day > $days_in_month) break;

    $y_day_start = $pdf->GetY();
    $pdf->Cell($col_dia_width, $cell_height * 2, $day, 1, 0, 'C');
    
    $daily_total = 0;
    foreach ($tanks as $tank) {
        $manha_consumo = isset($report_data[$day][$tank['id']]['manha']['consumo']) ? $report_data[$day][$tank['id']]['manha']['consumo'] : 0;
        $tarde_consumo = isset($report_data[$day][$tank['id']]['tarde']['consumo']) ? $report_data[$day][$tank['id']]['tarde']['consumo'] : 0;
        $daily_total += $manha_consumo + $tarde_consumo;
    }
    
    $pdf->SetXY(283 - $col_total_width, $y_day_start);
    $pdf->Cell($col_total_width, $cell_height * 2, number_format($daily_total), 1, 0, 'C');
    
    $pdf->SetXY(10 + $col_dia_width, $y_day_start);
    
    $pdf->Cell($col_periodo_width, $cell_height, utf8_decode('Manhã'), 1, 0, 'C');
    foreach ($tanks as $tank) {
        $data = isset($report_data[$day][$tank['id']]['manha']) ? $report_data[$day][$tank['id']]['manha'] : null;
        $leitura = $data ? number_format($data['leitura'], 0) : '';
        $consumo = $data ? number_format($data['consumo'], 0) : '';
        $pdf->Cell($sub_col_leitura_width, $cell_height, $leitura, 1, 0, 'C');
        $pdf->Cell($sub_col_consumo_width, $cell_height, $consumo, 1, 0, 'C');
    }
    $pdf->Ln();

    $pdf->SetX(10 + $col_dia_width);
    $pdf->Cell($col_periodo_width, $cell_height, 'Tarde', 1, 0, 'C');
    foreach ($tanks as $tank) {
        $data = isset($report_data[$day][$tank['id']]['tarde']) ? $report_data[$day][$tank['id']]['tarde'] : null;
        $leitura = $data ? number_format($data['leitura'], 0) : '';
        $consumo = $data ? number_format($data['consumo'], 0) : '';
        $pdf->Cell($sub_col_leitura_width, $cell_height, $leitura, 1, 0, 'C');
        $pdf->Cell($sub_col_consumo_width, $cell_height, $consumo, 1, 0, 'C');
    }
    $pdf->Ln();
}
}

// --- LÓGICA PRINCIPAL DE GERAÇÃO DO PDF ---
$pdf = new PDF('L', 'mm', 'A4');
$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador Desconhecido';
$pdf->AliasNbPages();

// Página 1: Dias 1 a 15
$pdf->reportDate = $current_month;
$pdf->AddPage();
draw_table_for_days($pdf, 1, 16, $tanks, $report_data, $days_in_month);

// Página 2: Dias 16 até ao final do mês
if ($days_in_month > 16) {
    $pdf->AddPage();

    draw_table_for_days($pdf, 17, $days_in_month, $tanks, $report_data, $days_in_month);
}

// --- Tabela de Totais no final da última página ---
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(40, 7, utf8_decode('Totais de Consumo do Mês (m³)'), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);

// Largura da coluna de total por tanque
// Garante que a divisão não dá erro se não houver tanques
$num_tanks_for_total = count($tanks);
$total_col_width = $num_tanks_for_total > 0 ? (277 - 20) / $num_tanks_for_total : 0; 

// Cabeçalho da tabela de totais
foreach($tanks as $tank) {
    $pdf->Cell($total_col_width, 5, utf8_decode($tank['name']), 1, 0, 'C', true);
}
$pdf->Ln();

// Linha com os valores
$pdf->SetFont('Arial','',7);
$grand_total = 0; // Inicia o total geral
foreach($tanks as $tank) {
    $total_gasto = isset($tank_totals[$tank['id']]) ? $tank_totals[$tank['id']] : 0;
    $grand_total += $total_gasto; // Acumula o total geral
    $pdf->Cell($total_col_width, 5, number_format($total_gasto, 0), 1, 0, 'C');
}
$pdf->Ln(); // Quebra de linha após a tabela

// ===========================================
// == LINHA ADICIONADA PARA O TOTAL GERAL ==
// ===========================================
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 7, 'Total Geral Consumido: ' . number_format($grand_total, 0) . ' m3', 0, 1, 'R');

// Envia o PDF para o browser
$pdf->Output('I', 'relatorio_agua_'. $current_month .'.pdf');
?>