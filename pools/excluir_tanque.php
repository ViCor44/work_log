<?php
// Inclui o core para ter acesso à sessão e à base de dados
require_once '../core.php';

// Verifica se o ID foi passado pela URL e se é um número
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $tank_id = $_GET['id'];

    // Prepara a query de exclusão para evitar injeção de SQL
    $stmt = $conn->prepare("DELETE FROM tanks WHERE id = ?");
    if ($stmt === false) {
        die("Erro ao preparar a consulta: " . $conn->error);
    }

    $stmt->bind_param("i", $tank_id);
    
    // Executa a query
    if ($stmt->execute()) {
        // Se a exclusão teve sucesso, define uma mensagem de sucesso
        $_SESSION['success_message'] = "Tanque excluído com sucesso!";
    } else {
        // Se falhou, define uma mensagem de erro
        $_SESSION['error_message'] = "Erro ao excluir o tanque: " . $stmt->error;
    }
    $stmt->close();

} else {
    // Se o ID for inválido ou não for fornecido, define uma mensagem de erro
    $_SESSION['error_message'] = "ID de tanque inválido.";
}

// Redireciona de volta para a lista de tanques
header("Location: gerir_tanques.php");
exit;
?>