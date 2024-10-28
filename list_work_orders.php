<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Conexão ao banco de dados
$user_id = $_SESSION['user_id'];

// Inicializa a variável de pesquisa
$search_query = '';

// Verifica se há uma pesquisa
if (isset($_POST['search'])) {
    $search_query = $_POST['search'];
}

// Consulta para contar o número de OTs por prioridade
$priorityCounts = [
    'Crítica' => 0,
    'Alta' => 0,
    'Média' => 0,
    'Baixa' => 0
];

// Consulta para contar OTs abertas e fechadas
$openCount = 0;
$closedCount = 0;

$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM work_orders GROUP BY status");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($status, $count);
    while ($stmt->fetch()) {
        // Contagem de OTs abertas
        if (in_array($status, ['Pendente', 'Aceite', 'Em Andamento'])) {
            $openCount += $count; // Usar += para somar todas as abertas
        } elseif ($status === 'Fechada') {
            $closedCount = $count; // Somente uma contagem para OTs fechadas
        }
    }
    $stmt->close();
} else {
    die("Erro na consulta de contagem de OTs: " . $conn->error);
}

// Atualiza o status para 'Fechada' e define o campo 'closed_at'
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $work_order_id = $_GET['id'];
    $stmt = $conn->prepare("UPDATE work_orders SET status = 'Fechada', closed_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $work_order_id);
    
    if ($stmt->execute()) {
        header("Location: list_work_orders.php?success=OT fechada com sucesso!");
    } else {
        die("Erro ao fechar a ordem de trabalho: " . $conn->error);
    }
    $stmt->close();
}

// Contagem das OTs por prioridade
$stmt = $conn->prepare("SELECT priority, COUNT(*) as count FROM work_orders GROUP BY priority");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($priority, $count);
    while ($stmt->fetch()) {
        if (isset($priorityCounts[$priority])) {
            $priorityCounts[$priority] = $count;
        }
    }
    $stmt->close();
} else {
    die("Erro na consulta de contagem de OTs: " . $conn->error);
}

// Verificar se um filtro de prioridade ou status foi aplicado
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : ''; // Para filtrar OTs abertas e fechadas

// Consulta para obter todas as ordens de trabalho com ativos, usuários e quem aceitou, com ou sem filtro
$work_orders = [];

$search_param = '%' . $search_query . '%'; // Para busca por palavras-chave
if ($statusFilter) {
    // Definir a condição do status baseado no filtro
    $statusCondition = $statusFilter === 'open' ? "'Pendente', 'Aceite', 'Em Andamento'" : "'Fechada'";
    
    // Consulta com filtro de status e pesquisa
    $stmt = $conn->prepare("
        SELECT w.id, a.name AS asset_name, w.description, w.status, w.priority, u.first_name AS assigned_user, w.created_at, w.accept_by, w.accept_at, w.closed_at, acceptor.first_name AS acceptor_name 
        FROM work_orders w 
        JOIN assets a ON w.asset_id = a.id 
        JOIN users u ON w.assigned_user = u.id
        LEFT JOIN users acceptor ON w.accept_by = acceptor.id
        WHERE w.status IN ($statusCondition) AND (w.description LIKE ? OR a.name LIKE ?)
    ");
    $stmt->bind_param("ss", $search_param, $search_param);
} elseif ($priorityFilter) {
    // Consulta com filtro de prioridade e pesquisa
    $stmt = $conn->prepare("
        SELECT w.id, a.name AS asset_name, w.description, w.status, w.priority, u.first_name AS assigned_user, w.created_at, w.accept_by, w.accept_at, w.closed_at, acceptor.first_name AS acceptor_name 
        FROM work_orders w 
        JOIN assets a ON w.asset_id = a.id 
        JOIN users u ON w.assigned_user = u.id
        LEFT JOIN users acceptor ON w.accept_by = acceptor.id
        WHERE w.priority = ? AND (w.description LIKE ? OR a.name LIKE ?)
    ");
    $stmt->bind_param("sss", $priorityFilter, $search_param, $search_param);
} else {
    // Consulta sem filtro e pesquisa
    $stmt = $conn->prepare("
        SELECT w.id, a.name AS asset_name, w.description, w.status, w.priority, u.first_name AS assigned_user, w.created_at, w.accept_by, w.accept_at, w.closed_at, acceptor.first_name AS acceptor_name 
        FROM work_orders w 
        JOIN assets a ON w.asset_id = a.id 
        JOIN users u ON w.assigned_user = u.id
        LEFT JOIN users acceptor ON w.accept_by = acceptor.id
        WHERE w.description LIKE ? OR a.name LIKE ?
    ");
    $stmt->bind_param("ss", $search_param, $search_param);
}

if ($stmt) {
    $stmt->execute();
    
    // Ajustar o bind_result para corresponder ao número correto de colunas
    $stmt->bind_result($work_order_id, $asset_name, $description, $status, $priority, $assigned_to, $created_at, $accept_by, $accept_at, $closed_at, $acceptor_name);
    
    while ($stmt->fetch()) {
        $work_orders[] = [
            'id' => $work_order_id,
            'asset_name' => $asset_name,
            'description' => $description,
            'status' => $status,
            'priority' => $priority,
            'assigned_to' => $assigned_to,
            'created_at' => $created_at,
            'accept_by' => $accept_by,
            'accept_at' => $accept_at,
            'closed_at' => $closed_at,
            'acceptor_name' => isset($acceptor_name) ? $acceptor_name : null
        ];
    }
    $stmt->close();
} else {
    die("Erro na consulta de ordens de trabalho: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listar Ordens de Trabalho</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .row-buttons {
            height: auto; /* Ajuste a altura para auto */
            margin-bottom: 20px; /* Adicione uma margem inferior para espaçamento */
        }

        .priority-btn {
            font-size: 1.2rem;
            padding: 1rem;
            display: flex; /* Adiciona um display flex */
            align-items: center; /* Centraliza verticalmente o conteúdo */
            justify-content: center; /* Centraliza horizontalmente o conteúdo */
            height: 100%; /* Garante que os botões ocupem toda a altura da coluna */
        }

        .not-accepted {
            font-weight: bold; /* Define a fonte em negrito */
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Listar Ordens de Trabalho</h1>

    <div class="d-flex mb-3">
        <a href="create_work_order.php" class="btn btn-primary me-3">Criar Nova Ordem de Trabalho</a>
        <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
    </div>

    <!-- Formulário de pesquisa -->
    <form method="POST" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Pesquisar por descrição ou ativo" value="<?= htmlspecialchars($search_query) ?>">
            <button class="btn btn-outline-primary" type="submit">Pesquisar</button>
            <a href="list_work_orders.php" class="clear-button btn btn-outline-secondary">Limpar</a> <!-- Botão para limpar -->
        </div>
    </form>    
    <!-- Botões de prioridade distribuídos igualmente em uma linha -->
    <div class="row mb-3 row-buttons">
        <div class="col-md-2">
            <a href="list_work_orders.php?priority=Crítica" class="btn btn-danger priority-btn w-100">
                Crítica (<?= $priorityCounts['Crítica'] ?> OTs)
            </a>
        </div>
        <div class="col-md-2">
            <a href="list_work_orders.php?priority=Alta" class="btn btn-warning priority-btn w-100">
                Alta (<?= $priorityCounts['Alta'] ?> OTs)
            </a>
        </div>
        <div class="col-md-2">
            <a href="list_work_orders.php?priority=Média" class="btn btn-info priority-btn w-100">
                Média (<?= $priorityCounts['Média'] ?> OTs)
            </a>
        </div>
        <div class="col-md-2">
            <a href="list_work_orders.php?priority=Baixa" class="btn btn-success priority-btn w-100">
                Baixa (<?= $priorityCounts['Baixa'] ?> OTs)
            </a>
        </div>        

        <div class="col-md-2 btn-status">
            <a href="list_work_orders.php?status=open" class="btn btn-primary priority-btn w-200 h-50">
                Abertas (<?= $openCount ?> OTs)
            </a>
            <a href="list_work_orders.php?status=closed" class="btn btn-secondary priority-btn w-200 h-50">
                Fechadas (<?= $closedCount ?> OTs)
            </a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($_GET['success']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($work_orders)): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ativo</th>
                        <th>Descrição</th>
                        <th>Status</th>
                        <th>Prioridade</th>
                        <th>Usuário</th>
                        <th>Data de Criação</th>
                        <th>Aceite Por</th>
                        <th>Aceite em</th>
                        <th>Fechado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($work_orders as $work_order): ?>
                        <tr class="<?= is_null($work_order['accept_by']) ? 'not-accepted' : '' ?>" onclick="location.href='view_work_order.php?id=<?= $work_order['id']; ?>'" style="cursor: pointer;">
                            <td><?= $work_order['id'] ?></td>
                            <td><?= $work_order['asset_name'] ?></td>
                            <td><?= $work_order['description'] ?></td>
                            <td><?= $work_order['status'] ?></td>
                            <td><?= $work_order['priority'] ?></td>
                            <td><?= $work_order['assigned_to'] ?></td>
                            <td><?= $work_order['created_at'] ?></td>
                            <td><?= $work_order['acceptor_name'] ?></td>
                            <td><?= $work_order['accept_at'] ?></td>
                            <td><?= $work_order['closed_at'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Nenhuma ordem de trabalho encontrada.</p>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
