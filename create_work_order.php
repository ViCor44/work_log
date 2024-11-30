<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Conexão ao banco de dados
$user_id = $_SESSION['user_id'];

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $asset_id = $_POST['asset_id'];
    $description = $_POST['description'];
    $assigned_to = $_POST['assigned_to']; // O ID do usuário a quem a ordem de trabalho é atribuída
    $priority = $_POST['priority']; // Captura a prioridade da ordem de trabalho
    $work_type = $_POST['work_type']; // Captura o tipo da ordem de trabalho

    // Define a data e hora de vencimento (due_date) com base na prioridade
    $due_date = null;
    switch ($priority) {
        case 'Crítica':
            $due_date = date('Y-m-d 23:59:00'); // Hoje, com a hora atual
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

    // Prepara a consulta para inserir a nova ordem de trabalho
    $stmt = $conn->prepare("INSERT INTO work_orders (asset_id, description, status, assigned_user, created_at, priority, type, due_date) VALUES (?, ?, 'pendente', ?, NOW(), ?, ?, ?)");
    if (!$stmt) {
        die("Erro na preparação da consulta: " . $conn->error);
    }

    // Bind dos parâmetros
    $stmt->bind_param("isssss", $asset_id, $description, $assigned_to, $priority, $work_type, $due_date);

    if ($stmt->execute()) {
        // Redireciona após a inserção bem-sucedida
        header("Location: list_work_orders.php");
        exit;
    } else {
        echo "Erro ao criar a ordem de trabalho: " . $stmt->error;
    }

    $stmt->close();
}

// Consulta para obter todos os ativos
$assets = [];
$stmt = $conn->prepare("SELECT id, name FROM assets");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($asset_id, $asset_name);
    while ($stmt->fetch()) {
        $assets[] = ['id' => $asset_id, 'name' => $asset_name];
    }
    $stmt->close();
} else {
    die("Erro na consulta de ativos: " . $conn->error);
}

// Consulta para obter todos os usuários aceitos
$users = [];
$stmt = $conn->prepare("SELECT id, first_name FROM users WHERE accepted = 1");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($user_id, $user_name);
    while ($stmt->fetch()) {
        $users[] = ['id' => $user_id, 'name' => $user_name];
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .priority-label {
            display: inline-block;
            padding: 5px 10px;
            color: white;
            border-radius: 5px;
            margin-right: 5px;
        }
        .priority-critical { background-color: red; }
        .priority-high { background-color: orange; }
        .priority-medium { background-color: yellow; }
        .priority-low { background-color: green; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Criar Ordem de Trabalho</h1>
    <form method="post" action="create_work_order.php">
        <div class="mb-3">
            <label for="asset_id" class="form-label">Ativo</label>
            <select class="form-select" id="asset_id" name="asset_id" required>
                <option value="" disabled selected>Selecione um ativo</option>
                <?php foreach ($assets as $asset): ?>
                    <option value="<?= $asset['id']; ?>"><?= htmlspecialchars($asset['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Descrição</label>
            <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
        </div>
        <div class="mb-3">
            <label for="assigned_to" class="form-label">Atribuído a</label>
            <select class="form-select" id="assigned_to" name="assigned_to" required>
                <option value="" disabled selected>Selecione um usuário</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= $user['id']; ?>"><?= htmlspecialchars($user['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Prioridade</label><br>
            <div>
                <input type="radio" id="Crítica" name="priority" value="Crítica" required>
                <label for="critical" class="priority-label priority-critical">Crítica</label>
                <input type="radio" id="Alta" name="priority" value="Alta" required>
                <label for="high" class="priority-label priority-high">Alta</label>
                <input type="radio" id="Média" name="priority" value="Média" required checked>
                <label for="medium" class="priority-label priority-medium">Média</label>
                <input type="radio" id="Baixa" name="priority" value="Baixa" required>
                <label for="low" class="priority-label priority-low">Baixa</label>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Tipo de Ordem de Trabalho</label><br>
            <div>
                <input type="radio" id="preventiva" name="work_type" value="preventiva" required checked>
                <label for="preventiva">Preventiva</label>
                <input type="radio" id="corretiva" name="work_type" value="corretiva" required>
                <label for="corretiva">Corretiva</label>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Criar Ordem de Trabalho</button>
        <a href="list_work_orders.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
