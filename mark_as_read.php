<?php
session_start();
include 'db.php'; // Conectar ao banco de dados

// Verificar se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Verificar se o ID da mensagem foi passado
if (isset($_GET['id'])) {
    $message_id = $_GET['id'];
    $user_id = $_SESSION['user_id'];

    // Atualizar o status da mensagem para 'lida'
    $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND receiver_id = ?");
    $stmt->bind_param("ii", $message_id, $user_id);

    if ($stmt->execute()) {
        // Redirecionar para a página de inbox após marcar como lida
        header("Location: inbox.php?msg=Mensagem marcada como lida");
        exit;
    } else {
        echo "Erro ao marcar a mensagem como lida: " . $stmt->error;
    }

    $stmt->close();
} else {
    echo "ID da mensagem não foi fornecido.";
}

$conn->close();
?>
