<?php
session_start();
include 'db.php'; // Inclui a conexão com o banco de dados

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Verifica se o usuário existe e se a senha está correta
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Verifica se a senha está correta
        if (password_verify($password, $user['password'])) {
            // Inicia a sessão e armazena informações do usuário
            // Crie variáveis de sessão
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            $_SESSION['user_type'] = $user['user_type'];
            // Redireciona para a página inicial após o login bem-sucedido
            header("Location: index.php");
            exit;
        } else {
            $error_message = "Senha incorreta.";
        }
    } else {
        $error_message = "Utilizador não encontrado.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema CMMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="container mt-5">
    <div class="text-center mb-4">
        <img src="images/logo1.png" alt="Logotipo" style="height: 80px;">
        <h1>Sistema CMMS</h1>
    </div>
    <div class="card mx-auto" style="width: 25rem;">
        <div class="card-body">
            <h5 class="card-title text-center">Login</h5>
            <form action="login_process.php" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            <p class="mt-3 text-center">Ainda não tem conta? <a href="register.php">Registre-se</a></p>
            <!-- Link para a página de recuperação de senha -->
            <div class="mt-3">
                <a href="forgot_password.php">Esqueceu a senha?</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>
</html>
