<?php
session_start();
include 'db.php'; // Precisa da conexão à BD para o update

// Se o utilizador estiver logado, limpa os dados da sessão na BD
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET session_id = NULL WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

// Destrói a sessão
session_unset();
session_destroy();

// Redireciona para a página de login
header("Location: login.php");
exit;
?>