<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de busca e processamento de dados (sem alterações) ---
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE requires_analysis = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
$sql = "SELECT a.*, u.first_name, u.last_name FROM analyses a JOIN users u ON a.user_id = u.id WHERE DATE(a.analysis_datetime) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $report_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$analysis_data = ['manha' => [], 'tarde' => []];
$morning_techs = [];
$afternoon_techs = [];
foreach ($results as $row) {
    $analysis_data[$row['period']][$row['tank_id']] = $row;
    if ($row['period'] == 'manha') {
        $morning_techs[$row['user_id']] = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
    } else {
        $afternoon_techs[$row['user_id']] = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
    }
}
function print_value($value, $decimals = 2) {
    if ($value !== null && $value != 0) {
        return number_format($value, $decimals, ',', '.');
    } return '';
}
$rede_data = [
    'current_reading' => null, 'previous_reading' => null, 'consumption' => null
];
$stmt_rede_id = $conn->prepare("SELECT id FROM tanks WHERE name = 'Rede' LIMIT 1");
$stmt_rede_id->execute();
$result_rede_id = $stmt_rede_id->get_result()->fetch_assoc();
$rede_tank_id = isset($result_rede_id['id']) ? $result_rede_id['id'] : null;
$stmt_rede_id->close();
if ($rede_tank_id) {
    $stmt_current = $conn->prepare("SELECT meter_value FROM water_readings WHERE tank_id = ? AND DATE(reading_datetime) = ? ORDER BY reading_datetime ASC LIMIT 1");
    $stmt_current->bind_param("is", $rede_tank_id, $report_date);
    $stmt_current->execute();
    $res_current = $stmt_current->get_result()->fetch_assoc();
    $rede_data['current_reading'] = isset($res_current['meter_value']) ? $res_current['meter_value'] : null;
    $stmt_current->close();
    $previous_date = date('Y-m-d', strtotime($report_date . ' -1 day'));
    $stmt_prev = $conn->prepare("SELECT meter_value FROM water_readings WHERE tank_id = ? AND DATE(reading_datetime) = ? ORDER BY reading_datetime ASC LIMIT 1");
    $stmt_prev->bind_param("is", $rede_tank_id, $previous_date);
    $stmt_prev->execute();
    $res_prev = $stmt_prev->get_result()->fetch_assoc();
    $rede_data['previous_reading'] = isset($res_prev['meter_value']) ? $res_prev['meter_value'] : null;
    $stmt_prev->close();
    if ($rede_data['current_reading'] !== null && $rede_data['previous_reading'] !== null) {
        $rede_data['consumption'] = $rede_data['current_reading'] - $rede_data['previous_reading'];
    }
}
function formatar_data_pt($date_string) {
    $dias_semana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $timestamp = strtotime($date_string);
    $dia_semana_num = date('w', $timestamp);
    $data_formatada = sprintf('%s, %s', $dias_semana[$dia_semana_num], date('d-m-Y', $timestamp));
    return $data_formatada;
}
// --- Fim da lógica de dados ---

class PDF extends FPDF {
    public $reportDate;
    public $technicians;
	public $userName;
    function Header() {
        if ($this->PageNo() == 1) {
            setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
            $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, utf8_decode('Boletim de Análises Diárias'), 0, 1, 'R');
            $this->Line(10, 25, 200, 25);
            $this->Ln(5);
            $formatted_date = formatar_data_pt($this->reportDate);


            $this->SetX(143);
            $this->SetFont('Arial', '', 10);
            $this->Cell(12, 7, 'Data:', 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(45, 7, utf8_decode($formatted_date), 1, 1, 'C');
            
        }
    }
	function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        
        // "WorkLog CMMS" alinhado à esquerda
        $this->Cell(95, 10, 'WorkLog CMMS', 0, 0, 'L');
        
        // "Impresso por" alinhado ao centro
        $this->Cell(95, 10, utf8_decode('Impresso por: ' . $this->userName), 0, 0, 'C');
        
        // Número da página alinhado à direita
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

// ALTERAÇÃO 1: Remover '$pdf' dos argumentos da função
function draw_grid_report($period_name, $tanks, $data, $techs, $report_date) {
    global $pdf; // ALTERAÇÃO 2: Declarar que vamos usar o $pdf global
    
    $pdf->reportDate = $report_date;
    $pdf->technicians = count($techs) > 0 ? implode(', ', $techs) : 'Nenhum registo';
    $pdf->AddPage();
    $left_margin = $pdf->GetX();

   
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Periodo: ' . $period_name, 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 8, 'Tecnico: ' . utf8_decode($pdf->technicians), 0, 1, 'C');
    
    

    $total_tanks = count($tanks);
    if ($total_tanks === 0) { $pdf->Cell(0, 10, 'Nao ha tanques para exibir.', 0, 1); return; }
    if ($total_tanks <= 4) { $num_columns = $total_tanks; } else { $num_columns = 4; }
    
    $page_width = $pdf->GetPageWidth() - ($left_margin * 2);
    $card_margin = 3;
    $card_width = ($page_width / $num_columns) - $card_margin;
    $params = [
        'Temperatura (C)' => ['key' => 'temperature', 'decimals' => 1],
        'pH' => ['key' => 'ph_level', 'decimals' => 2],
        'Cloro livre(mg/l)' => ['key' => 'chlorine_level', 'decimals' => 2],
        'Condutividade (mS/cm)' => ['key' => 'conductivity', 'decimals' => 2],
        'Solidos Dissolv. (mg/l)' => ['key' => 'dissolved_solids', 'decimals' => 2]
    ];
    $param_cell_height = 6;
    $tank_chunks = array_chunk($tanks, $num_columns);
    foreach ($tank_chunks as $row_of_tanks) {
        $initial_y = $pdf->GetY();
        $max_y = $initial_y;
        foreach ($row_of_tanks as $index => $tank) {
            $x_pos = $left_margin + ($index * ($card_width + $card_margin));
            $pdf->SetXY($x_pos, $initial_y);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($card_width, 7, utf8_decode($tank['name']), 1, 1, 'C', true);
            $pdf->SetX($x_pos);
            foreach($params as $param_name => $param_info) {
                $param_name_width = $card_width * 0.7;
                $param_value_width = $card_width * 0.3;
                $pdf->SetFont('Arial', '', 8);
                $pdf->Cell($param_name_width, $param_cell_height, utf8_decode($param_name), 1, 0, 'L');
                $value = isset($data[$tank['id']][$param_info['key']]) ? $data[$tank['id']][$param_info['key']] : null;
                $formatted_value = print_value($value, $param_info['decimals']);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->Cell($param_value_width, $param_cell_height, $formatted_value, 1, 1, 'C');
                $pdf->SetX($x_pos);
            }
            if ($pdf->GetY() > $max_y) { $max_y = $pdf->GetY(); }
        }
        $pdf->SetY($max_y + $card_margin);
    }
}

// ALTERAÇÃO 1: Remover '$pdf' dos argumentos da função
function draw_rede_summary_card($data) {
    global $pdf; // ALTERAÇÃO 2: Declarar que vamos usar o $pdf global
    
    $pdf->Ln(15); 
    $page_width = $pdf->GetPageWidth();
    $card_width = $page_width / 2; 
    $start_x = ($page_width - $card_width) / 2; 
    $pdf->SetX($start_x);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(50, 50, 50);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($card_width, 7, utf8_decode('Resumo de Consumo de Água da Rede'), 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $label_width = $card_width / 2;
    $value_width = $card_width / 2;
    $pdf->SetX($start_x);
    $pdf->Cell($label_width, 7, utf8_decode('Leitura Manhã (Dia Anterior)'), 1, 0, 'L');
    $pdf->Cell($value_width, 7, $data['previous_reading'] !== null ? round($data['previous_reading']) : 'N/A', 1, 1, 'C');
    $pdf->SetX($start_x);
    $pdf->Cell($label_width, 7, utf8_decode('Leitura Manhã (Atual)'), 1, 0, 'L');
    $pdf->Cell($value_width, 7, $data['current_reading'] !== null ? round($data['current_reading']) : 'N/A', 1, 1, 'C');
    $pdf->SetX($start_x);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($label_width, 7, utf8_decode('Consumo 24h (m³)'), 1, 0, 'L');
    $pdf->Cell($value_width, 7, $data['consumption'] !== null ? round($data['consumption']) : 'N/A', 1, 1, 'C');
}

// --- Lógica Principal de Geração do PDF ---
$pdf = new PDF('P', 'mm', 'A4');
$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador Desconhecido';
$pdf->AliasNbPages();

// ALTERAÇÃO 3: Chamar as funções sem passar o $pdf como argumento
draw_grid_report('Manha', $tanks, $analysis_data['manha'], $morning_techs, $report_date);
draw_grid_report('Tarde', $tanks, $analysis_data['tarde'], $afternoon_techs, $report_date);
if ($rede_tank_id) {
    draw_rede_summary_card($rede_data);
}

// Envia o PDF final para o browser
$pdf->Output('I', 'boletim_analises_'. $report_date .'.pdf');
?>