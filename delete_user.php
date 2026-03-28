<?php
// Inclui o header, que já inicia a sessão e a conexão à BD
require_once 'core.php';

// 1. VERIFICAÇÃO DE SEGURANÇA: Apenas administradores podem apagar utilizadores.
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    // Se não for admin, guarda uma mensagem de erro e redireciona.
    $_SESSION['error_message'] = "Acesso negado. Não tem permissão para apagar utilizadores.";
    header("Location: manage_users.php");
    exit;
}

// 2. VALIDAÇÃO DO INPUT: Verifica se o ID foi fornecido e é um número.
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "ID de utilizador inválido.";
    header("Location: manage_users.php");
    exit;
}

$user_to_delete_id = (int)$_GET['id'];
$current_user_id = (int)$_SESSION['user_id'];

// 3. VERIFICAÇÃO DE SEGURANÇA CRÍTICA: Impede que um admin se apague a si mesmo.
if ($user_to_delete_id === $current_user_id) {
    $_SESSION['error_message'] = "Não pode apagar a sua própria conta de administrador.";
    header("Location: manage_users.php");
    exit;
}

// 4. EXECUÇÃO DA QUERY DE ELIMINAÇÃO
$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $user_to_delete_id);

if ($stmt->execute()) {
    // Se a eliminação for bem-sucedida, define uma mensagem de sucesso.
    $_SESSION['success_message'] = "Utilizador apagado com sucesso!";
} else {
    // Se falhar, define uma mensagem de erro.
    $_SESSION['error_message'] = "Erro ao apagar o utilizador: " . $stmt->error;
}

$stmt->close();

// 5. REDIRECIONAMENTO: Volta sempre para a página de gestão de utilizadores.
header("Location: manage_users.php");
exit;
?>