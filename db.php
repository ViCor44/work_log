<?php
$servername = "localhost"; // ou o endereço do seu servidor de banco de dados
$username = "root"; // substitua pelo seu usuário do MySQL
$password = ""; // substitua pela sua senha do MySQL
$dbname = "cmms"; // substitua pelo nome do seu banco de dados
$dbnameSuper = "super_login";

// Criando a conexão
$conn = new mysqli($servername, $username, $password, $dbname);
$pdo = new PDO("mysql:host=localhost;dbname=cmms;charset=utf8mb4", "root", "");
$pdo->exec("SET NAMES utf8mb4");

// Verificando a conexão
if ($conn->connect_error) {
    die("Conexão worklog falhou: " . $conn->connect_error);
}

$connSuper = new mysqli($servername, $username, $password, $dbname);
$pdoSuper = new PDO("mysql:host=localhost;dbname=super_login;charset=utf8mb4", "root", "");
$pdoSuper->exec("SET NAMES utf8mb4");

// Verificando a conexão
if ($connSuper->connect_error) {
    die("Conexão super_login falhou: " . $connSuper->connect_error);
}

?>
