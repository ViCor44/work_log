<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = $_SESSION['user_id'];
require 'db.php';
require 'fpdf/fpdf.php'; // Certifique-se de que o caminho para a biblioteca FPDF está correto

require_once('PHPMailer/PHPMailerAutoload.php');


header('Content-Type: text/html; charset=utf-8');
setlocale(LC_TIME, 'pt_PT.utf8', 'pt_PT.UTF-8', 'Portuguese_Portugal.1252');
// Função para desenhar caixas arredondadas
class PDF extends FPDF {
	public $add_signature = false;
	public $signature_path = '';
	public $include_signature = false;

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if ($style == 'F') {
            $op = 'f';
        } elseif ($style == 'FD' || $style == 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }
        $MyArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', ($x) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k, $x3 * $this->k, ($h - $y3) * $this->k));
    }
	
	// Método Footer atualizado com paginação à esquerda
	function Footer() {
	    // --- NÚMERO DA PÁGINA ---
	    // Posiciona a 1.2 cm do fundo
	    $this->SetY(-12);
	    // Assumindo que está a usar a fonte normal, como na solução anterior
	    $this->SetFont('DejaVu', '', 8); 
	
	    // A única alteração é no último parâmetro: de 'C' para 'L'
	    $this->Cell(0, 10, utf8_decode('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'L');
	
	
	    // --- O SEU CÓDIGO DA ASSINATURA (sem alterações) ---
	    // Reposiciona o cursor e restaura a fonte para a assinatura
	    $this->SetY(-16);
	    $this->SetFont('DejaVu', '', 10);
	
	    if ($this->add_signature && file_exists($this->signature_path)) {
	        // Inserir a imagem da assinatura acima da linha
	        $this->Image($this->signature_path, 160, $this->GetY(), 40); // posição e tamanho ajustável
	    }
	
	    $this->SetY(-12);
	    $this->Cell(0, 10, utf8_decode("Assinatura do Técnico: __________________________"), 0, 0, 'R');
	}
	

}

if (isset($_GET['report_id'])) {
    $report_id = $_GET['report_id'];	



    // Recuperar informações do relatório
	$stmt = $conn->prepare("SELECT r.id, r.report_date, r.execution_date, r.report_details, r.report_type, u.first_name, u.last_name, r.edit_date, r.include_signature
	FROM reports r 
	JOIN users u ON r.technician_id = u.id 
	WHERE r.id = ?");
	$stmt->bind_param("i", $report_id);
	$stmt->execute();
	$stmt->bind_result($id, $report_date, $execution_date, $report_details, $report_type, $first_name, $last_name, $edit_date, $db_include_signature);
	$stmt->fetch();
	$stmt->close();

    // Recuperar as fotos do relatório
    $photos = [];
    $stmt = $conn->prepare("SELECT photo_path FROM report_photos WHERE report_id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row['photo_path'];
    }
    $stmt->close();
	
	// Caminho da assinatura
	$signature_path = "signatures/signature_user_" . $user_id . ".png";
	
	// Verifica se deve incluir assinatura e se a imagem existe
	$add_signature = ($db_include_signature == 1) && file_exists($signature_path);


	// Formatar a data do relatório em português com dia da semana
	
	$technician_name = $first_name . " " . $last_name; 
	$date = DateTime::createFromFormat('Y-m-d', $report_date);   	
	if (class_exists('IntlDateFormatter')) {
	    $formatter = new IntlDateFormatter('pt_PT', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
	    $formatter->setPattern('EEEE, dd-MM-yyyy');
	    $report_date = $formatter->format($date);
	} else {
	    // Fallback com setlocale e strftime
	    setlocale(LC_TIME, 'pt_PT.UTF-8', 'pt_PT', 'Portuguese_Portugal', 'portuguese');
	    $report_date = strftime('%A, %d-%m-%Y', $date->getTimestamp());
	}
	if ($report_type === 'diario') {
	    $titulo = 'Relatório Diário';
	}else if ($report_type === 'medidas de autoprotecao') {
	    $titulo = 'Relatório de Medidas de Autoproteção';
	} else {
	    $titulo = 'Relatório de ' . ucfirst($report_type);
	}

    // Criação do PDF no formato A5 (210mm x 148mm) horizontal
    $pdf = new PDF('L', 'mm', 'A5');
	$pdf->AliasNbPages();
	$pdf->include_signature = ($add_signature == 1);
	$pdf->add_signature = $add_signature;
	$pdf->signature_path = $signature_path;	
    $pdf->AddPage();
	$pdf->SetDisplayMode('fullpage', 'single');

    // Adiciona o logotipo
    $pdf->Image('images/Logo Registado Red.png', 10, 10, 30); // Ajuste o caminho do logotipo e o tamanho conforme necessário

    // Adiciona o título centralizado
    $pdf->AddFont('DejaVu','','DejaVuSans.php'); // Usar DejaVu para suporte UTF-8
    $pdf->SetFont('DejaVu', '', 20); // Define a fonte DejaVu
    $pdf->Cell(0, 10, utf8_decode($titulo), 0, 1, 'R');
    $pdf->Ln(1);

    // Adiciona uma linha logo abaixo do título
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY()); // Define a linha horizontal de X=10 a X=200 na posição Y atual
    $pdf->Ln(10); // Adiciona um espaço extra após a linha

    // Adicionar informações do técnico, data do relatório e data de execução numa única linha
    $pdf->SetFont('DejaVu', '', 10);

 	// Informações do relatório na mesma linha
	$pdf->SetXY(12, 27);	
	$pdf->Cell(25, 10, utf8_decode(htmlspecialchars("N°:")), 0, 0); // Label fora da caixa
	$pdf->RoundedRect(22, 27, 20, 10, 2, 'D'); // Caixa arredondada para o número do relatório
	$pdf->SetXY(25, 27);	
	$pdf->Cell(0, 10, utf8_decode($id), 0, 0);
	
	$pdf->SetXY(45, 27);	
	$pdf->Cell(20, 10, utf8_decode(htmlspecialchars("Técnico:")), 0, 0); // Label fora da caixa
	$pdf->RoundedRect(65, 27, 50, 10, 2, 'D'); // Caixa arredondada para o nome do técnico
	$pdf->SetXY(67, 27);	
	$pdf->Cell(0, 10, utf8_decode($technician_name), 0, 0);
	
	$pdf->SetXY(118, 27);	
	$pdf->Cell(35, 10, utf8_decode(htmlspecialchars("Data:")), 0, 0); // Label fora da caixa
	$pdf->RoundedRect(133, 27, 62, 10, 2, 'D'); // Caixa arredondada para a data do relatório
	$pdf->SetXY(138, 27);	
	$pdf->Cell(0, 10, utf8_decode($report_date), 0, 0);
	$pdf->Ln(2);

    // Adicionar a linha abaixo dessas informações
    $pdf->Ln(10); // Adiciona um pequeno espaço
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY()); // Linha horizontal de X=10 a X=200 na posição Y atual
    $pdf->Ln(10); // Adiciona um espaço extra após a linha

    // Detalhes do Relatório (com limite de 255 caracteres por página)
    $pdf->Ln(1);
    $pdf->SetXY(10, 43);   
    $pdf->Cell(40, 10, utf8_decode(htmlspecialchars("Detalhes:")), 0, 0); // Label fora da caixa
    $pdf->RoundedRect(15, 55, 180, 75, 5, 'D'); // Caixa arredondada para os detalhes do relatório
    
    
    // Limitar os detalhes a 750 caracteres por página
	 $pdf->SetFont('DejaVu', '', 12);
    $pdf->SetXY(18, 57);
    $details_text = utf8_decode($report_details);
    $details_page_1 = substr($details_text, 0, 750);
    $pdf->MultiCell(170, 5, $details_page_1);

    // Se houver mais de 750 caracteres, adicionar outra página para o restante
    if (strlen($details_text) > 750) {
        $pdf->AddPage();
        $details_page_2 = substr($details_text, 750);
        $pdf->RoundedRect(15, 10, 180, 120, 5, 'D');
        $pdf->SetXY(18, 15);
        $pdf->MultiCell(170, 5, $details_page_2);
    }
/*
    // Adiciona as fotos ao PDF
    foreach ($photos as $photo) {
        if (file_exists($photo)) { // Verifica se a foto existe
            $pdf->Image($photo, 150, null, 40); // Ajusta o tamanho da imagem e a posição
            $pdf->Ln(5); // Espaço entre as imagens
        }
    }
*/

    // Adicionar nota sobre as fotos se houver
    if (count($photos) > 0) {
        $pdf->Ln(5); // Adiciona espaço antes da nota
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell(0, 10, utf8_decode("Ver fotos anexadas no formato digital"), 0, 1, 'C'); // Nota centralizada
    }

    // No PDF, após os detalhes do relatório, adicionamos uma nota se foi editado
    if ($edit_date) {
        $pdf->Ln(5);
        $pdf->SetFont('DejaVu', '', 10);
        $pdf->Cell(0, 10, utf8_decode("Relatório editado em: " . date('d/m/Y H:i', strtotime($edit_date))), 0, 1, 'C');
    }

    // Gera o PDF e exibe no navegador
    //header('Content-Type: application/pdf');
    //header('Content-Disposition: inline; filename="relatorio_' . $report_id . '.pdf"');
	
	// Criar diretório se não existir
	$directory = 'report_pdfs';
	if (!is_dir($directory)) {
	    mkdir($directory, 0777, true);
	}
	
	// Caminho do ficheiro PDF
	$filename = $directory . '/report_' . $report_id . '.pdf';
	
	// Guardar o PDF no servidor
// PASSO 1: Criar diretório se não existir e definir o nome do ficheiro
$directory = 'report_pdfs';
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
$filename = $directory . '/report_' . $report_id . '.pdf';

// PASSO 2: Guardar o PDF no servidor
$pdf->Output('F', $filename);

// PASSO 3: Verificar se o email deve ser enviado e ENVIAR
// Obter preferência atual do user
$stmt = $conn->prepare("SELECT send_reports_by_email, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($send_reports_by_email, $email);
$stmt->fetch();
$stmt->close();

if ($send_reports_by_email) {
    // Coloque o seu código de depuração do email aqui se necessário
    // echo "A tentar enviar email para: " . htmlspecialchars($email) . "<br>";

    $mail = new PHPMailer();
    $mail->SMTPDebug = 0; // Desligue para produção, ou 2 para depurar
    $mail->Debugoutput = 'html';
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPSecure = 'tls';
    $mail->SMTPAuth = true;
    $mail->Username = 'slide.rocketchat@gmail.com';
    $mail->Password = 'jbbo gsys gvmq bise'; // Sua senha de aplicação
    $mail->setFrom('slide.rocketchat@gmail.com', 'WorkLog');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Relatorio em PDF - WorkLog CMMS';
    $mail->Body    = "Olá, " . $technician_name . "<br><br>Segue em anexo o relatório nº <b>" . $report_id . "</b> em formato PDF.<br><br>Cumprimentos,<br>Equipa WorkLog CMMS";

    if (file_exists($filename)) {
        $mail->addAttachment($filename);
    }

    // Apenas para depuração, pode verificar o resultado do envio
    if (!$mail->send()) {
        // Se a depuração estiver ativa, o erro já apareceu.
        // Se não, pode querer registar este erro num ficheiro de log.
        // echo 'Erro ao enviar o email: ' . $mail->ErrorInfo;
    }
	}
}

// PASSO 4: AGORA, no final de tudo, redirecionar o utilizador
header("Location: visualizar_pdf.php?file=" . urlencode(basename($filename)));
exit; // Termina o script

?>