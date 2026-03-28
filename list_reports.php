<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Recuperar o ID do utilizador logado
$user_id = $_SESSION['user_id'];
$is_admin = $_SESSION['user_type'];

// Verifica a opção do user para receber os relatórios em email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reports_toggle'])) {
    $send = $_POST['send_reports_by_email'] === '1' ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET send_reports_by_email = ? WHERE id = ?");
    $stmt->bind_param("ii", $send, $user_id);
    $stmt->execute();
}

// Obter preferência atual do user
$stmt = $conn->prepare("SELECT send_reports_by_email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($send_reports_by_email);
$stmt->fetch();
$stmt->close();

// Inicializa a variável de pesquisa
$search_query = '';

// Verifica se há uma pesquisa
if (isset($_POST['search'])) {
    $search_query = $_POST['search'];
}

// Filtros da pesquisa
$search_param = '%' . $search_query . '%';
$type_filter = isset($_POST['report_type']) ? $_POST['report_type'] : '';// Verifica se foi selecionado um tipo específico
$type_clause = '';
$type_bind = '';

if (!empty($type_filter) && $type_filter !== 'Todos') {
    $type_clause = " AND r.report_type = ?";
    $type_bind = $type_filter;
}

if ($is_admin == 'admin') {
    $sql = "SELECT r.id, r.report_date, r.execution_date, r.report_details, r.report_type, r.pdf_generated, r.printed, r.technician_id, 
               CONCAT(u.first_name, ' ', u.last_name) AS technician_name
            FROM reports r 
            JOIN users u ON r.technician_id = u.id
            WHERE (r.report_details LIKE ? OR u.first_name LIKE ?)" . $type_clause . "
            ORDER BY r.report_date DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($type_clause)) {
        $stmt->bind_param("sss", $search_param, $search_param, $type_bind);
    } else {
        $stmt->bind_param("ss", $search_param, $search_param);
    }
} else {
    $sql = "SELECT r.id, r.report_date, r.execution_date, r.report_details, r.report_type, r.pdf_generated, r.printed, r.technician_id, 
               CONCAT(u.first_name, ' ', u.last_name) AS technician_name 
            FROM reports r 
            JOIN users u ON r.technician_id = u.id
            WHERE r.technician_id = ? AND r.report_details LIKE ?" . $type_clause . "
            ORDER BY r.report_date DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($type_clause)) {
        $stmt->bind_param("iss", $user_id, $search_param, $type_bind);
    } else {
        $stmt->bind_param("is", $user_id, $search_param);
    }
}

// Consultar fotos associadas aos relatórios
function getReportPhotos($conn, $report_id) {
    $stmt = $conn->prepare("SELECT photo_path FROM report_photos WHERE report_id = ?");
    if (!$stmt) {
        die("Erro na consulta SQL: " . $conn->error);
    }
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $photos = [];
    while ($row = $result->fetch_assoc()) {
        $photos[] = $row['photo_path'];
    }
    return $photos;
}

$stmt->execute();
$result = $stmt->get_result(); // O resultado da execução da query é atribuído à variável $result
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Relatórios</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
	<link href="/work_log/css/sweetalert2.min.css" rel="stylesheet">
    <style>
        .search-container {
            margin-bottom: 20px; /* Espaço abaixo do formulário de pesquisa */
        }

        .search-input {
            width: 250px; /* Largura aumentada do campo de pesquisa */
            padding: 5px; /* Espaçamento interno do campo */
            font-size: 16px; /* Tamanho da fonte do campo */
        }

        .search-button, .clear-button {
            padding: 5px 10px; /* Espaçamento interno dos botões */
            font-size: 16px; /* Tamanho da fonte dos botões */
            margin-left: 5px; /* Margem à esquerda entre os botões */
        }

        body {
            background-color: #f8f9fa;
        }

        .card {
            margin-top: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Estilo para manter o cabeçalho da tabela fixo e permitir a rolagem do corpo */
        .table-container {
            max-height: 600px; /* Aumenta a altura máxima para mostrar mais relatórios */
            overflow-y: auto; /* Permite rolagem vertical */
            border: 1px solid #ddd; /* Bordas ao redor da tabela */
        }

        

        .table tbody tr:hover {
            background-color: #f1f1f1; /* Cor de fundo ao passar o mouse sobre uma linha */
        }
        
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <h1>Lista de Relatórios</h1>

        <div class="d-flex mb-3">
            <a href="create_report.php" class="btn btn-primary me-3">Criar Novo Relatório</a>
            <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
        </div>
		
		<form method="post" style="margin-bottom: 15px;">
		  <input type="hidden" name="send_reports_toggle" value="1">
		  <label>
		    <input type="checkbox" name="send_reports_by_email" value="1" <?= $send_reports_by_email ? 'checked' : '' ?>>
		    Enviar automaticamente cópia dos relatórios gerados para o meu e-mail
		  </label>
		  <button type="submit" class="btn btn-sm btn-primary">Guardar preferência</button>
		</form>

        <!-- Formulário de pesquisa -->
        <div class="search-container">
            <form method="POST" action="">
                <input type="text" name="search" class="search-input mb-2" placeholder="Pesquisar detalhes do relatório" value="<?php echo htmlspecialchars($search_query); ?>">
                <select name="report_type" class="form-select mb-2">
                    <option value="" <?= $type_filter == '' ? 'selected' : ''; ?>>Todos os Tipos</option>
					<option value="manutencao" <?= $type_filter == 'manutencao' ? 'selected' : ''; ?>>Manutenção</option>
					<option value="avaria" <?= $type_filter == 'avaria' ? 'selected' : ''; ?>>Avaria</option>
					<option value="diario" <?= $type_filter == 'diario' ? 'selected' : ''; ?>>Diário</option>
					<option value="medidas de autoprotecao" <?= $type_filter == 'autoprotecao' ? 'selected' : ''; ?>>Medidas de Autoproteção</option>
                </select>
                <input type="submit" value="Pesquisar" class="search-button btn btn-primary">
                <a href="list_reports.php" class="clear-button btn btn-secondary">Limpar</a>
            </form>
        </div>

        <!-- Tabela com cabeçalho fixo e rolagem no corpo -->
        <div class="card">
            <div class="card-body table-container">
                <?php if ($result->num_rows > 0): ?>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Técnico</th>
                                <th>Tipo de Relatório</th>
                                <th>Data do Relatório</th>
                                <th>Detalhes</th>
                                <th>Ações</th>
                                <th>Impressão</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="center-text"><?= htmlspecialchars($row['id']); ?></td>
                                <td class="center-text"><?= htmlspecialchars($row['technician_name']); ?></td>
                                <td class="center-text"><?= htmlspecialchars($row['report_type']); ?></td>
                                <td class="center-text"><?= htmlspecialchars($row['report_date']); ?></td>
                                <td>
                                    <span data-bs-toggle="tooltip" title="<?= htmlspecialchars($row['report_details']); ?>">
                                        <?= htmlspecialchars(substr($row['report_details'], 0, 50)) . (strlen($row['report_details']) > 50 ? '...' : ''); ?>
                                    </span>
                                </td>
                                <td class="center-text">
                                    <?php if (count(getReportPhotos($conn, $row['id'])) > 0): ?>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#photoModal<?= $row['id']; ?>">Ver Fotos</button>
                                    <?php endif; ?>
                                    <!-- Verificar se o PDF já foi gerado -->
                                    <?php if ($row['pdf_generated'] == 1): ?>
                                        <button class="btn btn-secondary btn-sm" onclick="confirmPdfGeneration(<?= $row['id']; ?>)">Ver PDF</button>
                                    <?php else: ?>
                                        <a onclick="confirmA5impression(<?= $row['id']; ?>)" class="btn btn-secondary btn-sm">Ver PDF</a>
                                    <?php endif; ?>
                                    <!-- Verificar se o relatório pertence ao técnico logado e exibir o botão de edição -->
                                    <?php if ($row['technician_id'] == $user_id): ?>
                                        <a href="edit_report.php?report_id=<?= $row['id']; ?>" class="btn btn-warning btn-sm">Editar</a>
                                    <?php endif; ?>
                                </td>
                                <td class="center-text">
                                    <input type="checkbox" class="printed-checkbox" data-id="<?= $row['id']; ?>" <?= $row['printed'] ? 'checked' : ''; ?>>
                                </td>
                            </tr>
                            <!-- Modal para visualização das fotos -->
                            <div class="modal fade" id="photoModal<?= $row['id']; ?>" tabindex="-1" aria-labelledby="photoModalLabel<?= $row['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="photoModalLabel<?= $row['id']; ?>">Fotos do Relatório #<?= $row['id']; ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div id="photoCarousel<?= $row['id']; ?>" class="carousel slide" data-bs-ride="carousel">
                                                <div class="carousel-inner">
                                                    <?php
                                                    $photos = getReportPhotos($conn, $row['id']);
                                                    foreach ($photos as $index => $photo_path):
                                                    ?>
                                                        <div class="carousel-item <?= $index === 0 ? 'active' : ''; ?>">
                                                            <img src="<?= $photo_path; ?>" class="d-block w-100" alt="Foto do Relatório">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button class="carousel-control-prev" type="button" data-bs-target="#photoCarousel<?= $row['id']; ?>" data-bs-slide="prev">
                                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                    <span class="visually-hidden">Previous</span>
                                                </button>
                                                <button class="carousel-control-next" type="button" data-bs-target="#photoCarousel<?= $row['id']; ?>" data-bs-slide="next">
                                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                    <span class="visually-hidden">Next</span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-center">Nenhum relatório encontrado.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/work_log/js/bootstrap.bundle.min.js"></script>
	<script src="/work_log/js/sweetalert2.all.min.js"></script>
	
    <script>
        document.querySelectorAll('.printed-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const reportId = this.getAttribute('data-id');
                const printed = this.checked ? 1 : 0;

                // Enviar a atualização via AJAX para evitar recarregar a página
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'update_printed_status.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        console.log('Status de impressão atualizado com sucesso.');
                    }
                };
                xhr.send('report_id=' + reportId + '&printed=' + printed);
            });
        });

        function confirmPdfGeneration(reportId) {
		
			Swal.fire({
		        title: 'Visualizar Novamente PDF?',
		        text: "Ao imprimir certifique-se que está configurado para A5!",
		        icon: 'question',
		        showCancelButton: true,
		        confirmButtonColor: '#3085d6',
		        cancelButtonColor: '#d33',
		        confirmButtonText: 'Sim, abrir',
		        cancelButtonText: 'Cancelar'
		    }).then((result) => {
		        if (result.isConfirmed) {
		            // Redireciona para a página de visualização do PDF
		            window.open('generate_pdf.php?report_id=' + reportId + '&regenerate=true', '_blank');
		        }
		    });
		}
		
		function confirmA5impression(reportId) {
		
			Swal.fire({
		        title: 'Impressão',
		        text: "Ao imprimir certifique-se que está configurado para A5!",
		        icon: 'warning',
				showCancelButton: true,
		        confirmButtonColor: '#3085d6',
		        cancelButtonColor: '#d33',
		        confirmButtonText: 'Ok',
		        cancelButtonText: 'Cancelar'
		    }).then((result) => {
		        if (result.isConfirmed) {
		            // Redireciona para a página de visualização do PDF
		            window.open('generate_pdf.php?report_id=' + reportId + '&regenerate=true', '_blank');
		        }
		    });
		        
        }
    </script>
</body>
</html>