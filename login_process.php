<?php
session_start();
include 'db.php'; // Inclua seu arquivo de conexão ao banco de dados
// Sessão já iniciada via SSO - apenas fazer routing por role
if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($_SESSION['user_type'] == 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit();
}
function logLoginAttempt($username, $success) {
    $timestamp = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $status = $success ? "SUCESSO" : "FALHA";
    $logLine = "[$timestamp] - IP: $ip - Username: $username - Resultado: $status" . PHP_EOL;
    $logPath = __DIR__ . "/logs/login_log.txt"; // Caminho absoluto
    $result = file_put_contents($logPath, $logLine, FILE_APPEND);
    if ($result === false) {
        echo("Erro ao escrever no ficheiro de log: $logPath");
        exit;
    }
}
// Verifique se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            if ($user['accepted'] == 1) {
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];
                $_SESSION['user_type']  = $user['user_type'];
                logLoginAttempt($username, true);
                if ($user['user_type'] == 'admin') {
                    header("Location: admin_dashboard.php");
                } else {
                    header("Location: user_dashboard.php");
                }
                exit();
            } else {
                echo "<script>alert('Aguarde que um administrador aceite o seu registo!'); window.location.href='login.php';</script>";
            }
        } else {
            logLoginAttempt($username, false);
            echo "<script>alert('Senha incorreta!'); window.location.href='login.php';</script>";
        }
    } else {
        logLoginAttempt($username, false);
        echo "<script>alert('Utilizador não encontrado!'); window.location.href='login.php';</script>";
    }
    $stmt->close();
}
?>