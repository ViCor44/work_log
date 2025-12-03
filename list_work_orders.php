<?php
require_once 'header.php';

// --- Lógica de Contadores ---
$priorityCounts = ['Crítica' => 0, 'Alta' => 0, 'Média' => 0, 'Baixa' => 0];
$openCount = 0;
$closedCount = 0;
$stmt_prio = $conn->query("SELECT priority, COUNT(*) as count FROM work_orders GROUP BY priority");
if ($stmt_prio) {
    while ($row = $stmt_prio->fetch_assoc()) {
        if (isset($priorityCounts[$row['priority']])) {
            $priorityCounts[$row['priority']] = $row['count'];
        }
    }
    $stmt_prio->close();
}
$stmt_status_count = $conn->query("SELECT status, COUNT(*) as count FROM work_orders GROUP BY status");
if ($stmt_status_count) {
    while ($row = $stmt_status_count->fetch_assoc()) {
        if (in_array($row['status'], ['Pendente', 'Aceite', 'Em Andamento'])) {
            $openCount += $row['count'];
        } elseif ($row['status'] === 'Fechada') {
            $closedCount = $row['count'];
        }
    }
    $stmt_status_count->close();
}

// --- Lógica de Busca e Filtros ---
$priorityFilter = isset($_GET['priority']) ? $_GET['priority'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';
$query = "
    SELECT 
        w.id, 
        a.name AS asset_name, 
        w.description, 
        w.status, 
        w.priority, 
        CONCAT(u.first_name, ' ', u.last_name) AS assigned_user,
        CONCAT(c.first_name, ' ', c.last_name) AS creator_name,
        w.created_at, 
        w.due_date
    FROM work_orders w
    JOIN assets a ON w.asset_id = a.id
    JOIN users u ON w.assigned_user = u.id
    LEFT JOIN users c ON w.created_by_user_id = c.id
    WHERE 1=1
";
$params = [];
$types = "";
if (!empty($search_query)) {
    $query .= " AND (w.description LIKE ? OR a.name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    array_push($params, $search_param, $search_param);
    $types .= "ss";
}
if ($statusFilter) {
    if ($statusFilter === 'open') {
        $query .= " AND w.status IN ('Pendente', 'Aceite', 'Em Andamento')";
    } else {
        $query .= " AND w.status = ?";
        $params[] = 'Fechada';
        $types .= "s";
    }
}
if ($priorityFilter) {
    $query .= " AND w.priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}
$query .= " ORDER BY w.created_at DESC";
$stmt = $conn->prepare($query);
if ($stmt && !empty($types)) {
    $bind_params = array();
    $bind_params[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_params[] = &$params[$i];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$work_orders = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$priority_map = ['Crítica' => 'bg-danger', 'Alta' => 'bg-warning text-dark', 'Média' => 'bg-info text-dark', 'Baixa' => 'bg-secondary'];
$status_map = ['Pendente' => 'bg-primary', 'Aceite' => 'bg-info text-dark', 'Em Andamento' => 'bg-success', 'Fechada' => 'bg-dark'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listar Ordens de Trabalho</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/work_log/css/all.min.css">
</head>
<body>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Ordens de Trabalho</h1>
        <div>
            <a href="create_work_order.php" class="btn btn-primary"><i class="fas fa-plus"></i> Nova Ordem de Trabalho</a>
            <a href="redirect_page.php" class="btn btn-secondary">Voltar ao Início</a>
        </div>
    </div>
    
    <div class="card shadow-sm mb-4">
        <div class="card-header"><h5 class="mb-0">Filtros e Pesquisa</h5></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <form method="GET" action="" class="input-group">
                        <input type="text" name="search" class="form-control" placeholder="Pesquisar por descrição ou ativo..." value="<?= htmlspecialchars($search_query) ?>">
                        <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                        <a href="list_work_orders.php" class="btn btn-outline-secondary">Limpar</a>
                    </form>
                </div>

            </div>
            <hr>
            <label class="form-label fw-bold">Filtrar por Prioridade:</label>
            <div class="d-flex flex-wrap gap-2">
                <a href="list_work_orders.php?priority=Crítica" class="btn btn-danger">Crítica <span class="badge bg-light text-danger ms-1"><?= $priorityCounts['Crítica'] ?></span></a>
                <a href="list_work_orders.php?priority=Alta" class="btn btn-warning">Alta <span class="badge bg-light text-warning ms-1"><?= $priorityCounts['Alta'] ?></span></a>
                <a href="list_work_orders.php?priority=Média" class="btn btn-info">Média <span class="badge bg-light text-info ms-1"><?= $priorityCounts['Média'] ?></span></a>
                <a href="list_work_orders.php?priority=Baixa" class="btn btn-secondary">Baixa <span class="badge bg-light text-secondary ms-1"><?= $priorityCounts['Baixa'] ?></span></a>
				<div class="col-lg-4">
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="list_work_orders.php?status=open" class="btn btn-primary position-relative">Abertas <span class="badge bg-light text-primary ms-1"><?= $openCount ?></span></a>
                        <a href="list_work_orders.php?status=closed" class="btn btn-dark position-relative">Fechadas <span class="badge bg-light text-dark ms-1"><?= $closedCount ?></span></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Ativo / Descrição</th>
                        <th>Prioridade</th>
                        <th>Status</th>
                        <th>Atribuído a</th>
                        <th>Criado por</th>
                        <th>Data Criação</th>
                        <th>Data Vencimento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($work_orders)): ?>
                        <?php foreach ($work_orders as $work_order): ?>
                            <tr onclick="location.href='view_work_order.php?id=<?= $work_order['id']; ?>'" style="cursor: pointer;">
                                <td>#<?= $work_order['id'] ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($work_order['asset_name']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars(substr($work_order['description'], 0, 70)) . (strlen($work_order['description']) > 70 ? '...' : '') ?></div>
                                </td>
                                <td>
                                    <?php 
                                    $p_class = isset($priority_map[$work_order['priority']]) ? $priority_map[$work_order['priority']] : 'bg-light text-dark';
                                    echo "<span class='badge {$p_class}'>" . htmlspecialchars($work_order['priority']) . "</span>";
                                    ?>
                                </td>
                                <td>
                                     <?php 
                                    $s_class = isset($status_map[$work_order['status']]) ? $status_map[$work_order['status']] : 'bg-light text-dark';
                                    echo "<span class='badge {$s_class}'>" . htmlspecialchars($work_order['status']) . "</span>";
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($work_order['assigned_user']) ?></td>
                                <td><?= htmlspecialchars($work_order['creator_name']) ?></td>
                                <td><?= date('d/m/Y', strtotime($work_order['created_at'])) ?></td>
                                <td><?= !empty($work_order['due_date']) ? date('d/m/Y', strtotime($work_order['due_date'])) : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted p-4">Nenhuma ordem de trabalho encontrada com os filtros atuais.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>