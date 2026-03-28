<?php
session_start();
require 'db.php';
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Verifique se o usuário está logado e é um admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo "Acesso negado!";
    exit;
}

// Inclua as funções para estatísticas
include 'statistics_functions.php';  // Crie um arquivo com as funções de consulta ao BD

// Obtenha os dados para a página de estatísticas
$work_orders_by_technician = getWorkOrdersByTechnician($conn);
$reports_by_technician = getReportsByTechnician($conn);
$work_orders_by_status = getWorkOrdersByStatus($conn);
$work_orders_by_priority = getWorkOrdersByPriority($conn);
$total_open_closed_work_orders = getTotalOpenClosedWorkOrders($conn);
$average_completion_time = getAverageCompletionTime($conn);
$min_ot = getMinExecutionTimeWorkOrder($conn);
$max_ot = getMaxExecutionTimeWorkOrder($conn);

// Configurações do dompdf
$options = new Options();
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);

// HTML para o PDF
$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Estatísticas</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; }
        .container { margin: 0; padding: 0; }
        .card { margin-bottom: 10px; padding: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Estatísticas</h1>
        <h3>Relatórios por Técnico</h3>
        <table class="table table-striped">
            <thead><tr><th>Técnico</th><th>Total de Relatórios</th></tr></thead>
            <tbody>';
foreach ($reports_by_technician as $row) {
    $html .= "<tr><td>{$row['first_name']} {$row['last_name']}</td><td>{$row['total_reports']}</td></tr>";
}
$html .= '
            </tbody>
        </table>
        <h3>Ordens de Trabalho por Técnico</h3>
        <table class="table table-striped">
            <thead><tr><th>Técnico</th><th>Total de OTs</th></tr></thead>
            <tbody>';
foreach ($work_orders_by_technician as $row) {
    $html .= "<tr><td>{$row['first_name']} {$row['last_name']}</td><td>{$row['total_work_orders']}</td></tr>";
}
$html .= '
            </tbody>
        </table>
        <h3>Total de OTs Abertas e Fechadas</h3>
        <p>Abertas: ' . $total_open_closed_work_orders['total_open'] . '</p>
        <p>Fechadas: ' . $total_open_closed_work_orders['total_closed'] . '</p>
        <h3>Tempo Médio de Conclusão</h3>
        <p>' . round($average_completion_time['avg_completion_time'], 2) . ' horas</p>
    </div>
</body>
</html>';

// Carregue o HTML e renderize o PDF
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("Estatísticas.pdf", ["Attachment" => 1]);
?>
