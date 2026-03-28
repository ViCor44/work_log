<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_type']; // Obtém o tipo do usuário da sessão

// Inicializa a variável de pesquisa
$search_query = '';

// Verifica se há uma pesquisa
if (isset($_POST['search'])) {
    $search_query = $_POST['search'];
}

// Consulta para obter o nome do usuário
$stmt = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
if (!$stmt) {
    die("Erro na consulta: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($user_name);
$stmt->fetch();
$stmt->close();

// Consulta para obter todos os ativos com base na pesquisa
$search_param = '%' . $search_query . '%';
$stmt = $conn->prepare("
    SELECT a.id, a.name, a.description, c.name AS category_name
    FROM assets a
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.name LIKE ? OR a.description LIKE ? OR c.name LIKE ?
");
$stmt->bind_param("sss", $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Ativos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h1 class="mb-4">Lista de Ativos</h1>

    <div class="d-flex mb-3">
        <a href="create_asset.php" class="btn btn-primary me-3">Criar Novo Ativo</a>
        <a href="create_category.php" class="btn btn-primary me-3">Criar Nova Categoria</a>
        <a href="statistics.php" class="btn btn-primary me-3">Estatísticas</a>
        <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
    </div>

    <!-- Formulário de pesquisa -->
    <form method="POST" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Pesquisar por nome, descrição ou categoria" value="<?= htmlspecialchars($search_query) ?>">
            <button class="btn btn-outline-primary" type="submit">Pesquisar</button>
            <a href="list_assets.php" class="clear-button btn btn-outline-secondary">Limpar</a> <!-- Botão para limpar -->
        </div>
    </form>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome do Ativo</th>
                <th>Descrição</th>
                <th>Categoria</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['id']); ?></td>
                    <td><?= htmlspecialchars($row['name']); ?></td>
                    <td><?= htmlspecialchars($row['description']); ?></td>
                    <td><?= htmlspecialchars($row['category_name']); ?></td>
                    <td>
                        <a href="view_asset.php?id=<?= $row['id']; ?>" class="btn btn-info">Ver Detalhes</a>
                        <?php if ($user_role == 'admin'): ?> <!-- Exibe apenas para admins -->
                            <a href="edit_asset.php?id=<?= $row['id']; ?>" class="btn btn-warning">Editar</a>
                            <a href="delete_asset.php?id=<?= $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja excluir este ativo?');">Excluir</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
