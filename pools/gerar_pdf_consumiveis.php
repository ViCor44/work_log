<?php
require_once '../core.php';
require_once '../fpdf/fpdf.php'; // Verifique se este caminho está correto

// --- Lógica de Filtros e Busca de Dados ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// A lógica de busca e cálculo é idêntica à da página web
$all_chemicals_stmt = $conn->query("SELECT id, name, unit, package_volume, current_stock FROM chemicals ORDER BY name ASC");
$all_chemicals = $all_chemicals_stmt->fetch_all(MYSQLI_ASSOC);
$report_summary = [];
foreach ($all_chemicals as $chemical) {
    $chemical_id = $chemical['id'];
    $package_volume = (float)$chemical['package_volume'];
    if ($package_volume == 0) $package_volume = 1;
    $stmt_spent = $conn->prepare("SELECT SUM(package_volume) as total FROM chemical_logs WHERE chemical_id = ? AND DATE(log_datetime) BETWEEN ? AND ?");
    $stmt_spent->bind_param("iss", $chemical_id, $start_date, $end_date);
    $stmt_spent->execute();
    $total_spent = (float)$stmt_spent->get_result()->fetch_assoc()['total'];
    $stmt_spent->close();
    $stmt_purchased = $conn->prepare("SELECT SUM(quantity) as total FROM chemical_purchases WHERE chemical_id = ? AND purchase_date BETWEEN ? AND ?");
    $stmt_purchased->bind_param("iss", $chemical_id, $start_date, $end_date);
    $stmt_purchased->execute();
    $total_purchased = (float)$stmt_purchased->get_result()->fetch_assoc()['total'];
    $stmt_purchased->close();
    $initial_stock_volume = (float)$chemical['current_stock'] - $total_purchased + $total_spent;
    $report_summary[$chemical_id] = [
        'name' => $chemical['name'], 'unit' => $chemical['unit'], 'initial_stock' => $initial_stock_volume,
        'purchased' => $total_purchased, 'spent' => $total_spent, 'final_stock' => (float)$chemical['current_stock']
    ];
}

// Busca histórico de trocas
$sql_logs = "SELECT cl.log_datetime, t.name as tank_name, chem.name as chemical_name, cl.package_volume, u.first_name, u.last_name FROM chemical_logs cl JOIN tanks t ON cl.tank_id = t.id JOIN users u ON cl.user_id = u.id JOIN chemicals chem ON cl.chemical_id = chem.id WHERE DATE(cl.log_datetime) BETWEEN ? AND ? ORDER BY cl.log_datetime DESC";
$stmt_logs = $conn->prepare($sql_logs);
$stmt_logs->bind_param("ss", $start_date, $end_date);
$stmt_logs->execute();
$logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_logs->close();

// Busca histórico de compras
$sql_purchases = "SELECT p.purchase_date, c.name as chemical_name, p.quantity, c.unit, p.notes, u.first_name, u.last_name FROM chemical_purchases p JOIN chemicals c ON p.chemical_id = c.id JOIN users u ON p.user_id = u.id WHERE p.purchase_date BETWEEN ? AND ? ORDER BY p.purchase_date DESC";
$stmt_purchases = $conn->prepare($sql_purchases);
$stmt_purchases->bind_param("ss", $start_date, $end_date);
$stmt_purchases->execute();
$purchases_log = $stmt_purchases->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_purchases->close();

// --- Geração do PDF ---
class PDF extends FPDF {
    public $reportDate;
    public $userName; // Nova propriedade para guardar o nome do utilizador

    function Header() {
        // Only include header on the first page
        if ($this->PageNo() == 1) {
            setlocale(LC_TIME, 'pt_PT.UTF-8', 'portuguese');
            $this->Image('../images/Logo Registado Red.png', 10, 8, 40);
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, utf8_decode('Relatório Mensal de Consumiveis de Piscinas'), 0, 1, 'R');
            $this->SetFont('Arial', '', 12);
            // Usa a data do período selecionado
            $this->Cell(0, 7, utf8_decode("Período de " . date('d/m/Y', strtotime($this->reportDate['start'])) . " a " . date('d/m/Y', strtotime($this->reportDate['end']))), 0, 1, 'R');

        }
		$this->Line(10, 28, 200, 28);
        $this->Ln(8);
		$this->SetTopMargin(28);
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

$pdf = new PDF('P', 'mm', 'A4');

$pdf->userName = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador Desconhecido';
$pdf->reportDate = ['start' => $start_date, 'end' => $end_date];

$pdf->AliasNbPages();
$pdf->AddPage();

// Tabela 1: Resumo de Stock
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, utf8_decode('Resumo de Stock e Consumo para o Período'), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(50, 7, 'Produto', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Stock Inicial', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Comprado', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Gasto', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Stock Final', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
foreach ($report_summary as $summary) {
    $pdf->Cell(50, 7, utf8_decode($summary['name']), 1, 0);
    $pdf->Cell(30, 7, number_format($summary['initial_stock'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell(30, 7, '+ ' . number_format($summary['purchased'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell(30, 7, '- ' . number_format($summary['spent'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell(30, 7, number_format($summary['final_stock'], 0, ',', '.'), 1, 1, 'C');
}

// Tabela 2: Histórico de Compras
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, utf8_decode('Histórico de Compras'), 0, 1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(25, 7, 'Data', 1, 0, 'C', true);
$pdf->Cell(55, 7, 'Produto', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'Quantidade', 1, 0, 'C', true);
$pdf->Cell(50, 7, 'Registado por', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
foreach ($purchases_log as $log) {
    $pdf->Cell(25, 7, date('d/m/Y', strtotime($log['purchase_date'])), 1, 0, 'C');
    $pdf->Cell(55, 7, utf8_decode($log['chemical_name']), 1, 0);
    $pdf->Cell(30, 7, number_format($log['quantity'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell(50, 7, utf8_decode($log['first_name'] . ' ' . $log['last_name']), 1, 1);
}

// Tabela 3: Histórico de Trocas (Consumo)
$pdf->Ln(10);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, utf8_decode('Histórico de Trocas (Consumo)'), 0, 1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(30, 7, 'Data e Hora', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Piscina', 1, 0, 'C', true);
$pdf->Cell(45, 7, 'Produto', 1, 0, 'C', true);
$pdf->Cell(20, 7, 'Volume', 1, 0, 'C', true);
$pdf->Cell(40, 7, 'Registado por', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 9);
foreach ($logs as $log) {
    $pdf->Cell(30, 7, date('d/m/Y H:i', strtotime($log['log_datetime'])), 1, 0, 'C');
    $pdf->Cell(45, 7, utf8_decode($log['tank_name']), 1, 0);
    $pdf->Cell(45, 7, utf8_decode($log['chemical_name']), 1, 0);
    $pdf->Cell(20, 7, number_format($log['package_volume'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell(40, 7, utf8_decode($log['first_name'] . ' ' . $log['last_name']), 1, 1);
}

$pdf->Output('I', 'relatorio_consumiveis.pdf');
?>