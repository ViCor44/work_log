<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['edit_date'])) {
    header("Location: ../index.php");
    exit;
}

$edit_date = $_POST['edit_date'];
$user_id = $_SESSION['user_id'];
$updates_made = 0;
$inserts_made = 0;

$conn->begin_transaction();
try {
    // Prepara as queries uma vez para reutilização
    $stmt_update = $conn->prepare("UPDATE water_readings SET meter_value = ? WHERE id = ?");
    $stmt_insert = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");

    // Verifica se existem dados para processar
    if (isset($_POST['meter_value'])) {
        foreach ($_POST['meter_value'] as $tank_id => $periods) {
            foreach ($periods as $period => $value) {
                // Só processa se o utilizador inseriu um valor
                if (!empty($value) && is_numeric($value)) {
                    $record_id = $_POST['record_id'][$tank_id][$period];

                    // Se já existe um registo (o ID não está vazio), faz UPDATE
                    if (!empty($record_id)) {
                        $stmt_update->bind_param("di", $value, $record_id);
                        $stmt_update->execute();
                        if ($stmt_update->affected_rows > 0) {
                            $updates_made++;
                        }
                    } 
                    // Se não existe registo, cria um novo (INSERT)
                    else {
                        // Assume uma hora padrão para cada período
                        $time = ($period === 'manha') ? '09:00:00' : '17:00:00';
                        $datetime = $edit_date . ' ' . $time;
                        $stmt_insert->bind_param("iisd", $tank_id, $user_id, $datetime, $value);
                        $stmt_insert->execute();
                        $inserts_made++;
                    }
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
header("Location: list_agua_piscinas.php?month=" . $month_year);
exit;
?>