<?php
// Inclui o nosso novo ficheiro principal. Ele já trata da sessão e da BD.
require_once __DIR__ . '/core.php';

// Se chegarmos aqui e o utilizador não estiver logado, redirecionamos.
// (Uma dupla verificação de segurança)
if (!isset($_SESSION['user_id'])) {
	header("Location:/work_log/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkLog CMMS</title>
    
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/work_log/css/all.min.css">

    <link rel="stylesheet" href="/work_log/css/style.css"> 
</head>
<body>

<?php
// DEPOIS DE CARREGAR TUDO, INCLUÍMOS A NAVBAR COM O CAMINHO CORRETO E ABSOLUTO
include __DIR__ . '/navbar.php'; 
?>