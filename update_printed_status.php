<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    echo 'Acesso negado';
    exit;
}

include 'db.php';

// Verificar se o ID do relatório e o novo status foram recebidos
if (isset($_POST['report_id']) && isset($_POST['printed'])) {
    $report_id = $_POST['report_id'];
    $printed = $_POST['printed'];

    // Atualizar o status de impressão na base de dados
    $stmt = $conn->prepare("UPDATE reports SET printed = ? WHERE id = ?");
    $stmt->bind_param("ii", $printed, $report_id);

    if ($stmt->execute()) {
        echo 'Status de impressão atualizado.';
    } else {
        echo 'Erro ao atualizar o status.';
    }

    $stmt->close();
}
?>
