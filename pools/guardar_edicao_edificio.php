<?php
require_once '../core.php'; // core.php já inclui a db.php e a função log_action

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['record_id'])) {
    header("Location: ../index.php");
    exit;
}

$record_id = $_POST['record_id'];
$new_meter_value = $_POST['meter_value'];
$user_id = $_SESSION['user_id'];

if (!is_numeric($new_meter_value)) {
    $_SESSION['error_message'] = "Valor inválido.";
    header("Location: list_edificio.php");
    exit;
}

$conn->begin_transaction();

try {
    // 1. Buscar o valor antigo para o log
    $stmt_old = $conn->prepare("SELECT meter_value FROM water_readings WHERE id = ?");
    $stmt_old->bind_param("i", $record_id);
    $stmt_old->execute();
    $result_old = $stmt_old->get_result()->fetch_assoc();
    $old_meter_value = $result_old['meter_value'];
    $stmt_old->close();

    // 2. Fazer o UPDATE
    $stmt = $conn->prepare("UPDATE water_readings SET meter_value = ? WHERE id = ?");
    $stmt->bind_param("di", $new_meter_value, $record_id);
    
    if ($stmt->execute()) {
        // Se a atualização teve sucesso e o valor mudou
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Leitura de Água Quente do Edifício atualizada com sucesso!";

            // ===========================================
            // == ADIÇÃO: Registar a ação no log ==
            // ===========================================
            $user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador desconhecido';
            $description = "O utilizador '$user_name' alterou a leitura do tanque 'Agua Quente Edificio' de '$old_meter_value' para '$new_meter_value' (ID do registo: $record_id).";
            log_action($conn, $user_id, 'UPDATE_EDIFICIO', $description);

        } else {
            // Se nenhuma linha foi afetada, significa que o valor não mudou
            $_SESSION['info_message'] = "Nenhuma alteração foi efetuada.";
        }
    } else {
        throw new Exception("Erro ao executar a atualização: " . $stmt->error);
    }
    
    $stmt->close();
    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao atualizar o registo: " . $e->getMessage();
}

header("Location: list_edificio.php");
exit;
?>