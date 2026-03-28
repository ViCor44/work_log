<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'db.php';

// Buscar o último relatório do técnico logado
$last_report = null;
$technician_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT signature_path FROM users WHERE id = ?");
$stmt->bind_param("i", $technician_id);
$stmt->execute();
$stmt->bind_result($signature_path);
$stmt->fetch();
$stmt->close();
$user_has_signature = !empty($signature_path);

$stmt = $conn->prepare("SELECT report_date, report_details FROM reports WHERE technician_id = ? ORDER BY report_date DESC LIMIT 1");
$stmt->bind_param("i", $technician_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $last_report = $result->fetch_assoc();
}

$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $report_date = $_POST['report_date'];
    $report_type = isset($_POST['report_type']) ? $_POST['report_type'] : 'diario';
    $report_details = $_POST['report_details'];
    $technician_id = $_SESSION['user_id'];
    $include_signature = isset($_POST['include_signature']) ? 1 : 0;
    $error = null;

    // Verificar duplicatas apenas para relatório diário
    if ($report_type === 'diario') {
        $stmt = $conn->prepare("SELECT * FROM reports WHERE technician_id = ? AND report_date = ? AND report_type = ?");
        $stmt->bind_param("iss", $technician_id, $report_date, $report_type);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Já existe um relatório diário submetido para essa data. Por favor, escolha outra data ou edite o existente.";
        }
    }

    // Se não houver erro (ou para tipos não diários), procede com a inserção
    if (!isset($error)) {
        $stmt = $conn->prepare("INSERT INTO reports (technician_id, report_date, execution_date, report_details, report_type, include_signature) 
                               VALUES (?, ?, NOW(), ?, ?, ?)");
        $stmt->bind_param("isssi", $technician_id, $report_date, $report_details, $report_type, $include_signature);

        if ($stmt->execute()) {
            $report_id = $stmt->insert_id;
            $stmt->close();

            // Upload das fotos (se houver)
            if (!empty($_FILES['photos']['name'][0])) {
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if (!empty($tmp_name)) {
                        $file_name = $_FILES['photos']['name'][$key];
                        $file_tmp = $_FILES['photos']['tmp_name'][$key];
                        $photo_path = 'uploads/' . basename($file_name);

                        if (move_uploaded_file($file_tmp, $photo_path)) {
                            $stmt = $conn->prepare("INSERT INTO report_photos (report_id, photo_path) VALUES (?, ?)");
                            $stmt->bind_param("is", $report_id, $photo_path);
                            $stmt->execute();
                            $stmt->close();
                        } else {
                            $error = "Erro ao fazer upload da foto.";
                        }
                    }
                }
            }

            if (!isset($error)) {
                header("Location: list_reports.php");
                exit;
            }
        } else {
            $error = "Erro ao criar o relatório.";
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Relatório</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .container { margin-top: 50px; }
        .card { margin-top: 20px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); }
        .card-body { background-color: #f9f9f9; }
        .alert { margin-top: 20px; }
        .date-input { width: 130px !important; }
        .form-control, .form-select { border-radius: 10px; transition: border-color 0.3s ease; }
        .form-control:focus, .form-select:focus { border-color: #007bff; box-shadow: 0 0 5px rgba(0, 123, 255, 0.5); }
        .btn-primary { background-color: #007bff; border: none; border-radius: 10px; }
        .btn-primary:hover { background-color: #0056b3; }
        .form-label { font-weight: bold; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container">
    <?php if ($last_report): ?>
        <h3 class="text-start">Último Relatório</h3>
        <div class="alert alert-info">
            <strong>Data:</strong> <?= htmlspecialchars($last_report['report_date']) ?><br>
            <strong>Conteúdo:</strong><br>
            <textarea class="form-control mt-1" rows="3" readonly><?= htmlspecialchars($last_report['report_details']) ?></textarea>
        </div>
    <?php endif; ?>

    <h3 class="text-start">Criar Relatório</h3>
    <div class="card">
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form action="create_report.php" method="post" enctype="multipart/form-data" id="report-form">
                <div class="mb-3">
                    <label for="report_date" class="form-label">Data do Relatório:</label>
                    <input type="date" class="form-control date-input" id="report_date" name="report_date" required value="<?= isset($report_date) ? htmlspecialchars($report_date) : ''; ?>">
                </div>
                <div class="mb-3">
                    <label for="report_type" class="form-label">Tipo de Relatório:</label>
                    <select class="form-select" id="report_type" name="report_type" required>
                        <option value="diario" <?= (isset($_POST['report_type']) && $_POST['report_type'] == 'diario') ? 'selected' : ''; ?>>Diário</option>
                        <option value="manutencao" <?= (isset($_POST['report_type']) && $_POST['report_type'] == 'manutencao') ? 'selected' : ''; ?>>Manutenção</option>
                        <option value="avaria" <?= (isset($_POST['report_type']) && $_POST['report_type'] == 'avaria') ? 'selected' : ''; ?>>Avaria</option>
                        <option value="medidas de autoprotecao" <?= (isset($_POST['report_type']) && $_POST['report_type'] == 'medidas_de_autoprotecao') ? 'selected' : ''; ?>>Medidas de Autoproteção</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="report_details" class="form-label">Detalhes:</label>
                    <textarea class="form-control" id="report_details" name="report_details" rows="4" placeholder="Descreva os detalhes aqui..." required><?= isset($report_details) ? htmlspecialchars($report_details) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="photos" class="form-label">Anexar fotos:</label>
                    <input type="file" name="photos[]" id="photos" class="form-control" multiple>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="include_signature" id="include_signature" <?= isset($_POST['include_signature']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="include_signature">Incluir assinatura no relatório</label>
                </div>
                <button type="submit" class="btn btn-primary">Submeter Relatório</button>
                <a href="list_reports.php" class="btn btn-secondary">Cancelar</a>
            </form>
        </div>
    </div>
</div>

<script src="/work_log/js/sweetalert2@11.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const reportForm = document.getElementById('report-form');
        const userHasSignature = <?= json_encode($user_has_signature); ?>;

        if (reportForm) {
            reportForm.addEventListener('submit', function(event) {
                const includeSignatureCheckbox = document.getElementById('include_signature');

                if (userHasSignature && !includeSignatureCheckbox.checked) {
                    event.preventDefault();
                    Swal.fire({
                        title: 'Tem a certeza?',
                        text: "Detetámos que tem uma assinatura guardada mas não marcou a opção para a incluir. Deseja submeter o relatório mesmo assim?",
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#3085d6',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Sim, submeter sem assinatura',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            reportForm.submit();
                        }
                    });
                }
            });
        }
    });
</script>
</body>
</html>