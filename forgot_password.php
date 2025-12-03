<?php
session_start();
include 'db.php';

$error = '';
$success = '';
$security_question = ''; // Inicializa a variável

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Parte para obter a pergunta de segurança
    if (isset($_POST['username']) && empty($_POST['security_answer']) && empty($_POST['new_password'])) {
        $username = $_POST['username'];

        $stmt = $conn->prepare("SELECT security_question FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($security_question);
            $stmt->fetch();
        } else {
            $error = "Utilizador não encontrado.";
        }
    }

    // Parte para verificar a resposta de segurança e redefinir a senha
    if (!empty($_POST['security_answer']) && !empty($_POST['new_password']) && !empty($_POST['confirm_password'])) {
        $username = $_POST['username'];
        $security_answer = $_POST['security_answer'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Verifica se a nova senha e a confirmação são iguais
        if ($new_password !== $confirm_password) {
            $error = "As senhas não coincidem.";
        } else {
            $stmt = $conn->prepare("SELECT security_answer FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $stmt->bind_result($stored_answer);
                $stmt->fetch();

                if ($security_answer == $stored_answer) {
                    $passwordHash = password_hash($new_password, PASSWORD_BCRYPT);

                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $update_stmt->bind_param("ss", $passwordHash, $username);

                    if ($update_stmt->execute()) {
                        $success = "Senha redefinida com sucesso!";
                    } else {
                        $error = "Erro ao redefinir a senha.";
                    }
                } else {
                    $error = "Resposta de segurança incorreta.";
                }
            } else {
                $error = "Utilizador não encontrado.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            margin-top: 50px;
            max-width: 500px; /* Define uma largura máxima */
        }
        h2 {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="container">
    <h2 class="text-center">Redefinir Senha</h2>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success; ?></div>
        <script>
            // Redireciona após 2 segundos
            setTimeout(function() {
                window.location.href = 'login.php';
            }, 2000);
        </script>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label for="username" class="form-label">Nome de Utilizador:</label>
            <input type="text" class="form-control" name="username" required>
        </div>
        <button type="submit" class="btn btn-primary">Ver Pergunta de Segurança</button>
        <a href="login.php" class="btn btn-secondary" role="button">Cancelar</a> <!-- Botão Cancelar -->
    </form>

    <?php if ($security_question): ?>
        <form method="POST">
            <div class="mb-3">
                <label for="security_question" class="form-label">Pergunta de Segurança:</label>
                <input type="text" class="form-control" name="security_question" value="<?= htmlspecialchars($security_question); ?>" readonly>
            </div>
            <div class="mb-3">
                <label for="security_answer" class="form-label">Resposta de Segurança:</label>
                <input type="text" class="form-control" name="security_answer" required autocomplete="security_answer">
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label">Nova Senha:</label>
                <input type="password" class="form-control" name="new_password" required autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirmar Nova Senha:</label>
                <input type="password" class="form-control" name="confirm_password" required autocomplete="new-password">
            </div>
            <input type="hidden" name="username" value="<?= htmlspecialchars($username); ?>">
            <button type="submit" class="btn btn-primary">Redefinir Senha</button>
        </form>
    <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>
