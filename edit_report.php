<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'db.php';

// Verificar se um ID de relatório foi fornecido na URL
if (isset($_GET['report_id'])) {
    $report_id = $_GET['report_id'];

    // Verificar se o relatório pertence ao técnico logado
    $stmt = $conn->prepare("SELECT * FROM reports WHERE id = ? AND technician_id = ?");
    if (!$stmt) {
        die("Erro na preparação da consulta: " . $conn->error);
    }
    $stmt->bind_param("ii", $report_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Verificar se o relatório foi encontrado e pertence ao usuário logado
    if ($result->num_rows == 1) {
        $report = $result->fetch_assoc();
    } else {
        die("Relatório não encontrado ou não pertence a você.");
    }
    $stmt->close();
} else {
    die("ID do relatório não fornecido.");
}

// Atualizar o relatório se o formulário for enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recuperar os dados atualizados do relatório
    $report_date = $_POST['report_date'];
	$execution_date = isset($_POST['execution_date']) ? $_POST['execution_date'] : null;    $report_details = $_POST['report_details'];    
    $edit_date = new DateTime();
    $edit_date->setTimezone(new DateTimeZone('Europe/Lisbon')); // Ajuste para o fuso horário correto
    $edit_date_str = $edit_date->format('Y-m-d H:i:s');
        
    // Atualizar os dados do relatório no banco de dados
    $stmt = $conn->prepare("UPDATE reports SET report_date = ?, report_details = ?, edit_date = ? WHERE id = ? AND technician_id = ?");
   
    if (!$stmt) {
        die("Erro na preparação da consulta de atualização: " . $conn->error);
    }
    
    // Vincular os parâmetros corretos
    $stmt->bind_param("sssii", $report_date, $report_details, $edit_date_str, $report_id, $_SESSION['user_id']);
    
    // Executar a atualização
    if ($stmt->execute()) {
        echo "Relatório atualizado com sucesso.";
    } else {
        echo "Erro ao atualizar o relatório: " . $stmt->error;
    }
    $stmt->close();

    // Verificar se as fotos foram enviadas
    if (!empty($_FILES['photos']['name'][0])) {
        // Iterar sobre as fotos enviadas
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if (!empty($tmp_name)) {
                $file_name = $_FILES['photos']['name'][$key];
                $file_tmp = $_FILES['photos']['tmp_name'][$key];

                // Definir o caminho para salvar as fotos
                $photo_path = 'uploads/' . basename($file_name);
                
                // Mover o arquivo para o diretório de uploads
                if (move_uploaded_file($file_tmp, $photo_path)) {
                    // Inserir o caminho da foto no banco de dados
                    $stmt = $conn->prepare("INSERT INTO report_photos (report_id, photo_path) VALUES (?, ?)");
                    if (!$stmt) {
                        die("Erro na preparação da consulta de fotos: " . $conn->error);
                    }
                    $stmt->bind_param("is", $report_id, $photo_path);
                    if (!$stmt->execute()) {
                        echo "Erro ao salvar a foto no banco de dados: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    echo "Erro ao fazer upload da foto.";
                }
            }
        }
    }

    // Redirecionar para a página de lista de relatórios
    header("Location: list_reports.php");
    exit;
}
?>




<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Relatório #<?= $report_id; ?></title> <!-- Aqui é onde o número do relatório é adicionado ao título -->
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container {
            margin-top: 50px;
        }
        .card {
            margin-top: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .card-body {
            background-color: #f9f9f9;
        }
        .alert {
            margin-top: 20px;
        }
        .form-control, .form-select {
            border-radius: 10px;
            transition: border-color 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
        }
        .btn-primary {
            background-color: #007bff;
            border: none;
            border-radius: 10px;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .form-label {
            font-weight: bold;
        }
        .date-input {
            width: 150px !important; /* Largura ajustada para datas */
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?> <!-- Inclusão do menu de navegação -->

<div class="container">
    <h3 class="text-start">Editar Relatório Nº <?= $report_id; ?></h3> <!-- Número do relatório adicionado ao título na página -->
    <div class="card">
        <div class="card-body">
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success); ?>
                    <br>
                    <a href="generate_pdf.php?report_id=<?= $report_id; ?>" class="btn btn-secondary mt-2">Gerar PDF</a>
                    <a href="list_reports.php" class="btn btn-primary mt-2">Ver Todos os Relatórios</a>
                </div>
            <?php endif; ?>
            <form action="edit_report.php?report_id=<?= $report_id; ?>" method="post" enctype="multipart/form-data">
                <!-- Campo de data do relatório -->
                <div class="mb-3">
                    <label for="report_date" class="form-label">Data do Relatório:</label>
                    <input type="date" class="form-control date-input" id="report_date" name="report_date" value="<?= htmlspecialchars($report['report_date']); ?>" required>
                </div>
                <!-- Campo para o conteúdo do relatório -->
                <div class="mb-3">
                    <label for="report" class="form-label">Detalhes:</label>
                    <textarea class="form-control" id="report" name="report_details" rows="4" required><?= htmlspecialchars($report['report_details']); ?></textarea>
                </div>
                <!-- Campo de upload de fotos com suporte a múltiplos arquivos -->
                <div class="mb-3">
                    <label for="photos" class="form-label">Anexar fotos:</label>
                    <input type="file" name="photos[]" id="photos" class="form-control" multiple>
                </div>
                <div class="d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary">Atualizar Relatório</button>
                    <a href="list_reports.php" class="btn btn-secondary">Cancelar</a> <!-- Botão de Cancelar -->
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
