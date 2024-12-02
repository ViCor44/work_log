<?php
session_start();
include 'db.php'; // Inclua seu arquivo de conexão ao banco de dados

// Verifique se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Receba os dados do formulário
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Prepare a consulta para buscar o usuário
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    // Verifique se o utilizador existe
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Verifique a senha usando password_verify
        if (password_verify($password, $user['password'])) {
            // Verifique se o usuário foi aceito
            if ($user['accepted'] == 1) {
                // Crie variáveis de sessão
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['user_type'] = $user['user_type']; // Campo que você vai usar para determinar o tipo de usuário

                // Redirecione com base no tipo de usuário
                if ($user['user_type'] == 'admin') {
                    header("Location: admin_dashboard.php"); // Redirecione para o painel do administrador
                } else {
                    header("Location: user_dashboard.php"); // Redirecione para o painel do usuário comum
                }
                exit();
            } else {
                // Utilizador não aceite
                echo "<script>alert('Aguarde que um administrador aceite o seu registo!'); window.location.href='login.php';</script>";
            }
        } else {
            // Senha incorreta
            echo "<script>alert('Senha incorreta!'); window.location.href='login.php';</script>";
        }
    } else {
        // Utilizador não encontrado
        echo "<script>alert('Utilizador não encontrado!'); window.location.href='login.php';</script>";
    }

    $stmt->close();
}
?>
