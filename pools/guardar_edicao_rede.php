<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_rede.php');
    exit();
}

// Recolha e validação dos dados do formulário
$edit_date = isset($_POST['edit_date']) ? $_POST['edit_date'] : null;
$tank_id = $_POST['tank_id'] ? $_POST['tank_id'] : null;

// Use 'is_numeric' para validar e (int) para converter, garantindo que o tipo é correto
$record_id_manha = isset($_POST['record_id_manha']) && is_numeric($_POST['record_id_manha']) ? (int)$_POST['record_id_manha'] : null;
$meter_value_manha = trim($_POST['meter_value_manha']);

$record_id_tarde = isset($_POST['record_id_tarde']) && is_numeric($_POST['record_id_tarde']) ? (int)$_POST['record_id_tarde'] : null;
$meter_value_tarde = trim($_POST['meter_value_tarde']);

if (!$edit_date || !$tank_id) {
    $_SESSION['error_message'] = "Erro: Dados essenciais em falta para guardar o registo.";
    header('Location: list_rede.php');
    exit();
}

$conn->begin_transaction();

try {
    // Preparar as statements uma única vez
    $stmt_insert = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
    $stmt_update = $conn->prepare("UPDATE water_readings SET meter_value = ? WHERE id = ?");
    $stmt_delete = $conn->prepare("DELETE FROM water_readings WHERE id = ?");

    // --- LÓGICA PARA O REGISTO DA MANHÃ ---
    $datetime_manha = $edit_date . ' 09:00:00';
    if ($record_id_manha === null && $meter_value_manha !== '') {
        $stmt_insert->bind_param("iisd", $tank_id, $user_id, $datetime_manha, $meter_value_manha);
        $stmt_insert->execute();
    } elseif ($record_id_manha !== null && $meter_value_manha !== '') {
        $stmt_update->bind_param("ii", $meter_value_manha, $record_id_manha);
        $stmt_update->execute();
    } elseif ($record_id_manha !== null && $meter_value_manha === '') {
        $stmt_delete->bind_param("i", $record_id_manha);
        $stmt_delete->execute();
    }

    // --- LÓGICA PARA O REGISTO DA TARDE ---
    $datetime_tarde = $edit_date . ' 14:00:00';
    if ($record_id_tarde === null && $meter_value_tarde !== '') {
        $stmt_insert->bind_param("iisd", $tank_id, $user_id, $datetime_tarde, $meter_value_tarde);
        $stmt_insert->execute();
    } elseif ($record_id_tarde !== null && $meter_value_tarde !== '') {
        $stmt_update->bind_param("ii", $meter_value_tarde, $record_id_tarde);
        $stmt_update->execute();
    } elseif ($record_id_tarde !== null && $meter_value_tarde === '') {
        $stmt_delete->bind_param("i", $record_id_tarde);
        $stmt_delete->execute();
    }

    // Fechar as statements após a utilização
    $stmt_insert->close();
    $stmt_update->close();
    $stmt_delete->close();

    $conn->commit();
    $_SESSION['success_message'] = "Alterações guardadas com sucesso para o dia " . date('d/m/Y', strtotime($edit_date)) . ".";

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Ocorreu um erro ao guardar as alterações: " . $e->getMessage();
}

$redirect_month = date('Y-m', strtotime($edit_date));
header('Location: list_rede.php?month=' . $redirect_month);
exit();
?>