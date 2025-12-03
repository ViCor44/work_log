<?php
require_once '../header.php';

// Obter a data do filtro, ou usar a data de hoje como padrão
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

// Buscar os tanques que requerem análises
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE requires_analysis = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// A query SQL agora usa um JOIN para ir buscar os nomes dos utilizadores
$sql = "
    SELECT 
        a.*, 
        u.first_name, 
        u.last_name 
    FROM analyses a
    JOIN users u ON a.user_id = u.id
    WHERE DATE(a.analysis_datetime) = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $report_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Processar os resultados para a matriz e, ao mesmo tempo,
// criar listas de técnicos para cada período, sem duplicados.
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

// Função auxiliar para mostrar os valores formatados
function print_value($value, $decimals = 2) {
    if ($value !== null && $value != 0) {
        return number_format($value, $decimals, ',', '.');
    }
    return '';
}
?>
<style>
    /* Estilos para o ecrã (sem alterações) */
    .report-block { border: 2px solid #333; padding: 0; height: 100%; } 
    .report-block h5 { background-color: #333; color: white; padding: 0.5rem; margin: 0; font-size: 1rem; }
    .report-table { margin: 0; }
    .report-table td { padding: 0.3rem 0.5rem; border: 1px solid #ccc; font-size: 0.9rem; }
	    /* Alterado para 80% para dar mais espaço ao nome do parâmetro */
	.report-table td:first-child { 
	    font-weight: bold; 
	    color: #005A9C; 
	    background-color: #f8f9fa; 
	    width: 75%; 
	}
    
    /* Estilos para Impressão */
    @media print {
        /* Define as margens da página A4 */
        @page {
            size: A4;
            margin: 1.5cm;
        }

        body {
            -webkit-print-color-adjust: exact !important; /* Força cores de fundo no Chrome/Safari */
            print-color-adjust: exact !important; /* Força cores de fundo em outros browsers */
        }
        
        /* Esconde tudo o que não deve ser impresso */
        .no-print { display: none !important; }
        
        /* Garante que a área imprimível ocupa todo o espaço */
        .printable-area, .printable-area * { visibility: visible; }
        .printable-area { position: static; }
        
        /* Garante que a grelha de bootstrap funciona na impressão */
        .row { display: flex !important; flex-wrap: wrap !important; }
        .col-md-3 { width: 25% !important; flex: 0 0 25% !important; }

        /* Controlo de quebras de página */
        .page-break { page-break-after: always; }
        .report-block { page-break-inside: avoid; } /* Tenta não quebrar um bloco de tanque a meio */
    }
</style>

<div class="container mt-4">
	<div class="card shadow-sm mb-4 no-print">
	    <div class="card-body">
	        <form method="GET" action="" class="row g-3 align-items-end">
	            <div class="col-md-3">
	                <label for="report_date" class="form-label">Selecionar Data do Relatório</label>
	                <input type="date" id="report_date" name="report_date" class="form-control" value="<?= htmlspecialchars($report_date) ?>">
	            </div>
	            <div class="col-md-9">
	                <button type="submit" class="btn btn-primary">Gerar Relatório</button>
	                
	                <button type="button" class="btn btn-danger" onclick="confirmPdfGeneration()">
	                    <i class="fas fa-file-pdf"></i> Exportar PDF
	                </button>
	                
	                <a href="#" onclick="confirmEdit('form_editar_analises.php?date=<?= htmlspecialchars($report_date) ?>')" class="btn btn-warning">
	                    <i class="fas fa-edit"></i> Editar Análises do Dia
	                </a>
	
	                <a href="menu_relatorios.php" class="btn btn-secondary">Voltar</a>
	            </div>
	        </form>
	    </div>
	</div>
	<div class="container-fluid mt-4">
    <div class="card shadow-sm mb-4 no-print">
        </div>

    <div class="printable-area">
        <div class="p-2">
            <h3 class="text-center">Boletim de Análises Diárias</h3>
            <h5 class="text-center">Data: <?= date('d/m/Y', strtotime($report_date)) ?> - Período: Manhã</h5>
            <p class="text-center mb-4">
                <strong>Técnico(s):</strong> 
                <?= count($morning_techs) > 0 ? implode(', ', $morning_techs) : 'Nenhum registo' ?>
            </p>
            
            <div class="row">
                <?php foreach($tanks as $tank): ?>
                <div class="col-md-3 mb-4">
                    <div class="report-block">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <table class="table table-bordered report-table">
                            <tr><td>Temperatura (°C)</td><td><?= print_value(isset($analysis_data['manha'][$tank['id']]['temperature']) ? $analysis_data['manha'][$tank['id']]['temperature'] : null, 1) ?></td></tr>
                            <tr><td>pH</td><td><?= print_value(isset($analysis_data['manha'][$tank['id']]['ph_level']) ? $analysis_data['manha'][$tank['id']]['ph_level'] : null) ?></td></tr>
                            <tr><td>Cloro livre(mg/l)</td><td><?= print_value(isset($analysis_data['manha'][$tank['id']]['chlorine_level']) ? $analysis_data['manha'][$tank['id']]['chlorine_level'] : null) ?></td></tr>
                            <tr><td>Condutividade (mS/cm)</td><td><?= print_value(isset($analysis_data['manha'][$tank['id']]['conductivity']) ? $analysis_data['manha'][$tank['id']]['conductivity'] : null) ?></td></tr>
                            <tr><td>Sólidos Dissolv. (mg/l)</td><td><?= print_value(isset($analysis_data['manha'][$tank['id']]['dissolved_solids']) ? $analysis_data['manha'][$tank['id']]['dissolved_solids'] : null) ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="page-break"></div>

        <div class="p-2">
            <h3 class="text-center">Boletim de Análises Diárias</h3>
            <h5 class="text-center">Data: <?= date('d/m/Y', strtotime($report_date)) ?> - Período: Tarde</h5>
            <p class="text-center mb-4">
                <strong>Técnico(s):</strong> 
                <?= count($afternoon_techs) > 0 ? implode(', ', $afternoon_techs) : 'Nenhum registo' ?>
            </p>

            <div class="row">
                <?php foreach($tanks as $tank): ?>
                <div class="col-md-3 mb-4">
                    <div class="report-block">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <table class="table table-bordered report-table">
                            <tr><td>Temperatura (°C)</td><td><?= print_value(isset($analysis_data['tarde'][$tank['id']]['temperature']) ? $analysis_data['tarde'][$tank['id']]['temperature'] : null, 1) ?></td></tr>
                            <tr><td>pH</td><td><?= print_value(isset($analysis_data['tarde'][$tank['id']]['ph_level']) ? $analysis_data['tarde'][$tank['id']]['ph_level'] : null) ?></td></tr>
                            <tr><td>Cloro residual livre(mg/l)</td><td><?= print_value(isset($analysis_data['tarde'][$tank['id']]['chlorine_level']) ? $analysis_data['tarde'][$tank['id']]['chlorine_level'] : null) ?></td></tr>
                            <tr><td>Condutividade (mS/cm)</td><td><?= print_value(isset($analysis_data['tarde'][$tank['id']]['conductivity']) ? $analysis_data['tarde'][$tank['id']]['conductivity'] : null) ?></td></tr>
                            <tr><td>Sólidos Dissolv. (mg/l)</td><td><?= print_value(isset($analysis_data['tarde'][$tank['id']]['dissolved_solids']) ? $analysis_data['tarde'][$tank['id']]['dissolved_solids'] : null) ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        </div>
    </div>
</div>
<script src="/work_log/js/sweetalert2@11.js"></script>

<script>
    function confirmPdfGeneration() {
        // Pega na data selecionada no campo de input
        const reportDate = document.getElementById('report_date').value;
        // Monta o URL para o gerador de PDF
        const pdfUrl = `gerar_pdf_analises.php?report_date=${reportDate}`;

        Swal.fire({
            title: 'Gerar PDF',
            text: "O relatório será gerado em duas páginas A4. Certifique-se que a opção de impressão 'Frente e Verso' (ou 'Duplex') está ativa para uma impressão correta.",
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Compreendido, abrir PDF!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            // Se o utilizador clicar em 'Sim', abre o PDF num novo separador
            if (result.isConfirmed) {
                window.open(pdfUrl, '_blank');
            }
        });
    }
	function confirmEdit(editUrl) {
	    Swal.fire({
	        title: 'Atenção: Área de Edição',
	        text: "Está prestes a editar um registo. Todas as alterações serão guardadas e registadas no log do sistema para auditoria. Deseja continuar?",
	        icon: 'warning',
	        showCancelButton: true,
	        confirmButtonColor: '#ffc107', // Cor de aviso do Bootstrap
	        cancelButtonColor: '#6c757d',  // Cor secundária
	        confirmButtonText: 'Sim, continuar para edição',
	        cancelButtonText: 'Cancelar'
	    }).then((result) => {
	        // Se o utilizador confirmar, redireciona para a página de edição
	        if (result.isConfirmed) {
	            window.location.href = editUrl;
	        }
	    });
	}
</script>

<?php require_once '../footer.php'; ?>