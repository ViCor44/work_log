<?php
session_start();

// Verifica se o usuário está logado e se é admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Conexão ao banco de dados

// Consulta para obter todos os usuários
$users = [];
$stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone, user_type, accepted FROM users");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result($user_id, $first_name, $last_name, $email, $phone, $user_type, $accepted);
    while ($stmt->fetch()) {
        $users[] = ['id' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone' => $phone, 'user_type' => $user_type, 'accepted' => $accepted];
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
    <title>Gerenciar Utilizadores</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Gerir Utilizadores</h1>
    <div class="mb-3">
        <a href="create_user.php" class="btn btn-primary">Criar Novo Utilizador</a>
        <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
    </div>
    
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Email</th>
                <th>Telefone</th>
                <th>Tipo</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['id']); ?></td>
                <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                <td><?= htmlspecialchars($user['email']); ?></td>
                <td><?= htmlspecialchars($user['phone']); ?></td>
                <td><?= htmlspecialchars($user['user_type']); ?></td>
                <td>
                    <a href="edit_user.php?id=<?= $user['id']; ?>" class="btn btn-warning btn-sm">Editar</a>
                    <?php if ($_SESSION['user_id'] !== $user['id']): // Verifica se não é o próprio usuário ?>
                        <a href="delete_user.php?id=<?= $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir este utilizador?');">Excluir</a>
                    <?php endif; ?>
                    <?php if ($user['accepted'] == 0): ?>
                        <a href="accept_user.php?id=<?= $user['id']; ?>" class="btn btn-success btn-sm">Aceitar</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
