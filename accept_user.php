<?php
session_start();

// Verifica se o usuário está logado e se é admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Conexão ao banco de dados

// Verifica se o ID do usuário foi passado pela URL
if (isset($_GET['id'])) {
    $user_id = $_GET['id'];

    // Prepara a consulta para aceitar o usuário
    $stmt = $conn->prepare("UPDATE users SET accepted = 1 WHERE id = ?");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {
        // Redireciona de volta para a página de gerenciamento de usuários com uma mensagem de sucesso
        $_SESSION['message'] = "Usuário aceito com sucesso!";
        header("Location: manage_users.php");
    } else {
        // Se ocorrer um erro, você pode definir uma mensagem de erro
        $_SESSION['message'] = "Erro ao aceitar o usuário: " . $conn->error;
        header("Location: manage_users.php");
    }

    $stmt->close();
} else {
    // Se o ID não for fornecido, redirecione de volta com uma mensagem de erro
    $_SESSION['message'] = "ID de usuário não fornecido.";
    header("Location: manage_users.php");
}

$conn->close();
?>
