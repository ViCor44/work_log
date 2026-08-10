<?php
require_once __DIR__ . '/session_bootstrap.php';
start_worklog_session();
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

// Remove também o cookie para o próximo login não tentar reutilizar um ID antigo.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

// Redireciona para a página de login
header("Location: login.php");
exit;
?>
