<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_type']; // Supondo que o papel do usuário esteja armazenado na sessão

$asset_id = null; // Inicializa a variável do ativo

// Verifica se o asset_id foi passado na URL
if (isset($_GET['asset_id'])) {
    $asset_id = $_GET['asset_id'];
    
    // Verifica se o asset_id é um número válido
    if (!is_numeric($asset_id)) {
        echo "ID de ativo inválido.";
        exit;
    }

    // Consulta para obter as ordens de trabalho associadas ao ativo, incluindo o nome do ativo
    $stmt = $conn->prepare("SELECT wo.id, wo.description, wo.status, a.name AS asset_name FROM work_orders wo JOIN assets a ON wo.asset_id = a.id WHERE wo.asset_id = ?");
    $stmt->bind_param("i", $asset_id);
} else {
    echo "Nenhum ativo foi selecionado.";
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Ordens de Trabalho</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h2 class="mb-4">Ordens de Trabalho do Ativo</h2>
    
    <?php if ($result->num_rows > 0): ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome do Ativo</th>
                    <th>Descrição</th>
                    <th>Status</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['id']); ?></td>
                        <td><?= htmlspecialchars($row['asset_name']); ?></td>
                        <td><?= htmlspecialchars($row['description']); ?></td>
                        <td><?= htmlspecialchars($row['status']); ?></td>
                        <td>
                            <a href="view_work_order.php?id=<?= $row['id']; ?>" class="btn btn-primary">Ver Detalhes</a>
                            <?php if ($user_role == 'admin'): ?> <!-- Exibe apenas para admins -->
                                <a href="edit_work_order.php?id=<?= $row['id']; ?>" class="btn btn-warning">Editar</a>
                                <a href="delete_work_order.php?id=<?= $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja excluir esta ordem de trabalho?');">Excluir</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-info">Nenhuma ordem de trabalho encontrada para este ativo.</div>
    <?php endif; ?>

    <a href="list_assets.php" class="btn btn-secondary mt-3">Voltar à Lista de Ativos</a>
</div>

<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>

