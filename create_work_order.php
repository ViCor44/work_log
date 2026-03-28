<?php
session_start();
date_default_timezone_set('Europe/Lisbon');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Database connection
$user_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $asset_id = $_POST['asset_id'];
    $description = $_POST['description'];
    $assigned_to = $_POST['assigned_to']; // The ID of the user to whom the work order is assigned
    $priority = $_POST['priority'];       // Capture work order priority
    $work_type = $_POST['work_type'];     // Capture work order type

    // Define due_date based on priority
    $due_date = null;
    switch ($priority) {
        case 'Crítica':
            $due_date = date('Y-m-d 23:59:59'); // End of today
            break;
        case 'Alta':
            $due_date = date('Y-m-d 18:00:00', strtotime('+2 days'));
            break;
        case 'Média':
            $due_date = date('Y-m-d 18:00:00', strtotime('+5 days'));
            break;
        case 'Baixa':
            $due_date = date('Y-m-d 18:00:00', strtotime('+10 days'));
            break;
    }

    // Prepare the query to insert the new work order
    $stmt = $conn->prepare("INSERT INTO work_orders (asset_id, description, created_by_user_id, status, assigned_user, created_at, priority, type, due_date) VALUES (?, ?, 'pendente', ?, NOW(), ?, ?, ?)");
    if (!$stmt) {
        die("Erro na preparação da consulta: " . $conn->error);
    }

    // Bind parameters
    $stmt->bind_param("isissss", $asset_id, $description, $_SESSION['user_id'], $assigned_to, $priority, $work_type, $due_date);

   if ($stmt->execute()) {
    // Sucesso! Vamos obter o ID da ordem de trabalho que acabámos de criar.
    $work_order_id = $stmt->insert_id;

    // --- LÓGICA DE UPLOAD DAS FOTOS ---

    // Verifica se foram enviados ficheiros no campo 'photos'
    if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
        
        // Diretório onde as fotos serão guardadas. CRIE ESTA PASTA!
        $upload_dir = 'uploads/work_orders/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true); // Cria o diretório se não existir
        }

        // Prepara a consulta para inserir as fotos na nova tabela
        $photo_stmt = $conn->prepare("INSERT INTO work_order_photos (work_order_id, photo_path) VALUES (?, ?)");

        // Itera sobre cada ficheiro enviado
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            $file_name = $_FILES['photos']['name'][$key];
            $file_error = $_FILES['photos']['error'][$key];

            // Verifica se não houve erros no upload
            if ($file_error === UPLOAD_ERR_OK) {
                // Cria um nome de ficheiro único para evitar sobreposições
                $new_file_name = $work_order_id . '_' . time() . '_' . basename($file_name);
                $destination = $upload_dir . $new_file_name;

                // Move o ficheiro temporário para o destino final
                if (move_uploaded_file($tmp_name, $destination)) {
                    // Se o ficheiro foi movido com sucesso, insere o caminho na base de dados
                    $photo_stmt->bind_param("is", $work_order_id, $destination);
                    $photo_stmt->execute();
                }
            }
        }
        $photo_stmt->close();
    }
    
    // --- FIM DA LÓGICA DE UPLOAD ---

    // Agora, no final de tudo, redireciona o utilizador
    header("Location: list_work_orders.php");
    exit;

} else {
    echo "<div class='alert alert-danger' role='alert'>Erro ao criar a ordem de trabalho: " . htmlspecialchars($stmt->error) . "</div>";
}

    $stmt->close();
}

// --- Data Retrieval for Form ---

// Query to get all categories
$categories = [];
$stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY name");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();
} else {
    die("Erro na consulta de categorias: " . $conn->error);
}

// Query to get all assets (we'll load them all and filter with JavaScript)
$all_assets = [];
$stmt = $conn->prepare("SELECT a.id, a.name, a.category_id FROM assets a ORDER BY a.name");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_assets[] = $row;
    }
    $stmt->close();
} else {
    die("Erro na consulta de ativos: " . $conn->error);
}

// *** ALTERAÇÃO AQUI ***
// Consulta para obter todos os usuários aceitos, incluindo o last_name
$users = [];
$stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE accepted = 1 ORDER BY first_name, last_name");
if ($stmt) {
    $stmt->execute();
    // Adicionamos last_name ao bind_result
    $stmt->bind_result($user_id, $user_first_name, $user_last_name);
    while ($stmt->fetch()) {
        // Concatenamos first_name e last_name para exibir o nome completo
        $users[] = ['id' => $user_id, 'name' => $user_first_name . ' ' . $user_last_name];
    }
    $stmt->close();
} else {
    die("Erro na consulta de usuários: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Ordem de Trabalho</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .priority-label {
            display: inline-block;
            padding: 5px 10px;
            color: white;
            border-radius: 5px;
            margin-right: 5px;
        }
        .priority-critical { background-color: #dc3545; } /* Bootstrap danger color */
        .priority-high { background-color: #fd7e14; }    /* Bootstrap orange */
        .priority-medium { background-color: #ffc107; color: #343a40;} /* Bootstrap warning, added text color */
        .priority-low { background-color: #28a745; }     /* Bootstrap success color */
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Criar Ordem de Trabalho</h1>
    <form method="post" action="create_work_order.php" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="category_id" class="form-label">Categoria do Ativo</label>
            <select class="form-select" id="category_id" name="category_id" required>
                <option value="" disabled selected>Selecione uma categoria</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['id']; ?>"><?= htmlspecialchars($category['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="asset_id" class="form-label">Ativo</label>
            <select class="form-select" id="asset_id" name="asset_id" required disabled>
                <option value="">Selecione primeiro uma categoria</option>
            </select>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Descrição</label>
            <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
        </div>
		<div class="mb-3">
            <label for="photos" class="form-label">Anexar Fotos (Opcional)</label>
            <input class="form-control" type="file" id="photos" name="photos[]" multiple>
        </div>
        <div class="mb-3">
            <label for="assigned_to" class="form-label">Atribuído a</label>
            <select class="form-select" id="assigned_to" name="assigned_to" required>
                <option value="" disabled selected>Selecione um utilizador</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id']; ?>"><?= htmlspecialchars($user['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Prioridade</label><br>
            <div>
                <input type="radio" id="priorityCritical" name="priority" value="Crítica" required>
                <label for="priorityCritical" class="priority-label priority-critical">Crítica</label>
                <input type="radio" id="priorityHigh" name="priority" value="Alta" required>
                <label for="priorityHigh" class="priority-label priority-high">Alta</label>
                <input type="radio" id="priorityMedium" name="priority" value="Média" required checked>
                <label for="priorityMedium" class="priority-label priority-medium">Média</label>
                <input type="radio" id="priorityLow" name="priority" value="Baixa" required>
                <label for="priorityLow" class="priority-label priority-low">Baixa</label>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Tipo de Ordem de Trabalho</label><br>
            <div>
                <input type="radio" id="workTypePreventiva" name="work_type" value="preventiva" required checked>
                <label for="workTypePreventiva">Preventiva</label>
                <input type="radio" id="workTypeCorretiva" name="work_type" value="corretiva" required>
                <label for="workTypeCorretiva">Corretiva</label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Criar Ordem de Trabalho</button>
        <a href="list_work_orders.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>

<script>
    const allAssets = <?= json_encode($all_assets); ?>;
    const categorySelect = document.getElementById('category_id');
    const assetSelect = document.getElementById('asset_id');

    function filterAssets() {
        const selectedCategoryId = categorySelect.value;
        assetSelect.innerHTML = '<option value="">Selecione um ativo</option>';
        assetSelect.disabled = true;

        if (selectedCategoryId) {
            const filteredAssets = allAssets.filter(asset => asset.category_id == selectedCategoryId);

            if (filteredAssets.length > 0) {
                filteredAssets.forEach(asset => {
                    const option = document.createElement('option');
                    option.value = asset.id;
                    option.textContent = asset.name;
                    assetSelect.appendChild(option);
                });
                assetSelect.disabled = false;
            } else {
                assetSelect.innerHTML = '<option value="">Nenhum ativo encontrado para esta categoria</option>';
            }
        }
    }

    categorySelect.addEventListener('change', filterAssets);

    filterAssets(); // Call it once on page load to set initial state correctly
</script>

</body>
</html>

<?php
$conn->close();
?>