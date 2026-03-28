<?php

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "cmms";
$dbnameSuper = "super_login";


/* ============================
   LIGAÇÃO MYSQLI - CMMS
============================ */

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Conexão worklog falhou: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET collation_connection = utf8mb4_unicode_ci");


/* ============================
   LIGAÇÃO PDO - CMMS
============================ */

$pdo = new PDO(
    "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdo->exec("SET NAMES utf8mb4");


/* ============================
   LIGAÇÃO MYSQLI - SUPER LOGIN
============================ */

$connSuper = new mysqli($servername, $username, $password, $dbnameSuper);

if ($connSuper->connect_error) {
    die("Conexão super_login falhou: " . $connSuper->connect_error);
}

$connSuper->set_charset("utf8mb4");
$connSuper->query("SET collation_connection = utf8mb4_unicode_ci");


/* ============================
   LIGAÇÃO PDO - SUPER LOGIN
============================ */

$pdoSuper = new PDO(
    "mysql:host=$servername;dbname=$dbnameSuper;charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$pdoSuper->exec("SET NAMES utf8mb4");
