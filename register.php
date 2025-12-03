<?php
session_start();
include 'db.php'; // Inclui a conexão com o banco de dados

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $username = $_POST['new_username'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $security_question = $_POST['security_question'];
    $security_answer = $_POST['security_answer'];

    // Verifica se o username já existe
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $error_message = "Nome de usuário já existe.";
    } elseif ($password !== $confirm_password) {
        $error_message = "As senhas não coincidem.";
    } else {
        // Hasheia a senha antes de armazenar
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insere o novo usuário na tabela
        $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, username, email, phone, password, security_question, security_answer, user_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'user', NOW(), NOW())");
        $stmt->bind_param("ssssssss", $first_name, $last_name, $username, $email, $phone, $hashed_password, $security_question, $security_answer);
        
        if ($stmt->execute()) {
            header("Location: login.php");
            exit;
        } else {
            $error_message = "Erro ao registrar. Tente novamente.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - WorkLog CMMS</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: url('images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp') no-repeat center center fixed;
            background-size: cover;
            color: #000000;
        }
    </style>
</head>
<body>

<div class="container mt-5">
    <div class="text-center mb-4">
        
        <h1>WorkLog CMMS</h1>
    </div>
    <div class="card mx-auto" style="width: 25rem;">
        <div class="card-body">
            <h5 class="card-title text-center">Registro</h5>
            <form action="register.php" method="POST">
                <div class="mb-3">
                    <label for="first_name" class="form-label">Primeiro Nome</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                </div>
                <div class="mb-3">
                    <label for="last_name" class="form-label">Último Nome</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                </div>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input autocomplete="new_username" type="text" class="form-control" id="new_username" name="new_username" required >
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="mb-3">
                    <label for="phone" class="form-label">Telefone</label>
                    <input type="text" class="form-control" id="phone" name="phone" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirmação de Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="mb-3">
                    <label for="security_question" class="form-label">Pergunta de Segurança</label>
                    <input type="text" class="form-control" id="security_question" name="security_question" required>
                </div>
                <div class="mb-3">
                    <label for="security_answer" class="form-label">Resposta de Segurança</label>
                    <input type="text" class="form-control" id="security_answer" name="security_answer" required>
                </div>
                <button type="submit" class="btn btn-primary">Registrar</button>
            </form>
            <p class="mt-3 text-center">Já tem conta? <a href="login.php">Login</a></p>
        </div>
    </div>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>

