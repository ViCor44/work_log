<?php

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $user_id = $_SESSION['user_id']; // ID do usuário logado
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $security_question = $_POST['security_question'];
    $security_answer = $_POST['security_answer'];

    // Atualiza os dados do usuário no banco de dados
    $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, security_question = ?, security_answer = ? WHERE id = ?");
    if (!$stmt) {
        die("Erro na consulta: " . $conn->error);
    }
    $stmt->bind_param("ssssssi", $first_name, $last_name, $email, $phone, $security_question, $security_answer, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Perfil atualizado com sucesso!";
    } else {
        $_SESSION['error_message'] = "Erro ao atualizar o perfil: " . $stmt->error;
    }
    $stmt->close();
    
    // Redireciona de volta para a página principal após atualização
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Recupera o nome do utilizador logado e outros dados
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone, username, user_type, security_question, security_answer FROM users WHERE id = ?");
if (!$stmt) {
    die("Erro na consulta: " . $conn->error);
}
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($first_name, $last_name, $email, $phone, $username, $user_type, $security_question, $security_answer);
$stmt->fetch();
$stmt->close();
?>

<nav class="navbar navbar-expand-lg navbar-light bg-light">
    <div class="container">
        <!-- Logotipo -->
        <a class="navbar-brand" href="redirect_page.php">
            <img src="images/logo1.png" alt="Logotipo" style="height: 40px;"> Sistema CMMS
        </a>        
        <!-- Nome do usuário clicável para abrir o modal -->
        <span class="navbar-text user-name">
            <a href="#" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                Olá, <?= htmlspecialchars($first_name . ' ' . $last_name); ?>
            </a>
        </span>
        <a href="logout.php" class="btn btn-outline-danger">Sair</a>
    </div>
</nav>

<!-- Modal de Edição de Perfil -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileLabel">Editar Perfil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Formulário para editar perfil -->
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="first_name" class="form-label">Primeiro Nome</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="last_name" class="form-label">Último Nome</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Telefone</label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="security_question" class="form-label">Pergunta de Segurança</label>
                        <input type="text" class="form-control" id="security_question" name="security_question" value="<?= htmlspecialchars($security_question); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="security_answer" class="form-label">Resposta de Segurança</label>
                        <input type="text" class="form-control" id="security_answer" name="security_answer" value="<?= htmlspecialchars($security_answer); ?>" required>
                    </div>
                    <!-- Campos não editáveis (nome de utilizador e tipo de utilizador) -->
                    <div class="mb-3">
                        <label for="username" class="form-label">Nome de Utilizador</label>
                        <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($username); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="user_type" class="form-label">Tipo de Utilizador</label>
                        <input type="text" class="form-control" id="user_type" value="<?= htmlspecialchars($user_type); ?>" disabled>
                    </div>
                    <button type="submit" name="update_profile" class="btn btn-primary">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Mensagens de Sucesso ou Erro -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <?= $_SESSION['success_message']; ?>
        <?php unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger">
        <?= $_SESSION['error_message']; ?>
        <?php unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<style>
    .user-name {
        margin-right: -700px; /* Ajuste o valor conforme necessário */
    }
</style>
