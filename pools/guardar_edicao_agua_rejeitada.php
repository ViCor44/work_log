<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['edit_date'])) {
    header("Location: ../index.php");
    exit;
}

$edit_date = $_POST['edit_date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $edit_date)) {
    header("Location: list_agua_rejeitada.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$updates_made = 0;
$inserts_made = 0;

$conn->begin_transaction();
try {
    $stmt_update = $conn->prepare("UPDATE rejected_water_readings SET meter_value = ? WHERE id = ?");
    $stmt_insert = $conn->prepare("INSERT INTO rejected_water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");

    if (isset($_POST['meter_value'])) {
        foreach ($_POST['meter_value'] as $tank_id => $value) {
            $tank_id = (int)$tank_id;
            if ($value !== '' && is_numeric($value)) {
                $value = (float)$value;
                $record_id = isset($_POST['record_id'][$tank_id]) ? (int)$_POST['record_id'][$tank_id] : 0;

                if ($record_id > 0) {
                    $stmt_update->bind_param("di", $value, $record_id);
                    $stmt_update->execute();
                    if ($stmt_update->affected_rows > 0) {
                        $updates_made++;
                    }
                } else {
                    $datetime = $edit_date . ' 09:00:00';
                    $stmt_insert->bind_param("iisd", $tank_id, $user_id, $datetime, $value);
                    $stmt_insert->execute();
                    $inserts_made++;
                }
            }
        }
    }

    $stmt_update->close();
    $stmt_insert->close();
    $conn->commit();
    $_SESSION['success_message'] = "$updates_made registo(s) atualizado(s) e $inserts_made registo(s) criado(s) com sucesso!";

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao guardar as alterações: " . $e->getMessage();
}

$month_year = date('Y-m', strtotime($edit_date));
header("Location: list_agua_rejeitada.php?month=" . $month_year);
exit;
?>
