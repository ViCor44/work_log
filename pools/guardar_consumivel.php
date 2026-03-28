<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['tank_id']) || empty($_POST['chemical_id']) || !isset($_POST['package_volume'])) {
    header("Location: form_consumiveis.php");
    exit;
}

$tank_id = (int)$_POST['tank_id'];
$chemical_id = (int)$_POST['chemical_id'];
$package_volume = (float)$_POST['package_volume'];
$user_id = $_SESSION['user_id'];

if ($package_volume <= 0) {
    $_SESSION['error_message'] = "O volume da embalagem tem de ser um valor positivo.";
    header("Location: form_consumiveis.php");
    exit;
}

$conn->begin_transaction();
try {
    // 1. Insere o registo na tabela de logs de consumo
    $stmt_log = $conn->prepare("INSERT INTO chemical_logs (tank_id, user_id, chemical_id, package_volume) VALUES (?, ?, ?, ?)");
    $stmt_log->bind_param("iiid", $tank_id, $user_id, $chemical_id, $package_volume);
    $stmt_log->execute();
    $stmt_log->close();

    // 2. Subtrai a quantidade do stock na tabela principal de produtos
    $stmt_stock = $conn->prepare("UPDATE chemicals SET current_stock = current_stock - ? WHERE id = ?");
    $stmt_stock->bind_param("di", $package_volume, $chemical_id);
    $stmt_stock->execute();
    $stmt_stock->close();

    $conn->commit();
    $_SESSION['success_message'] = "Troca de consumível registada com sucesso!";

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao registar a troca: " . $e->getMessage();
}

header("Location: form_consumiveis.php");
exit;
?>