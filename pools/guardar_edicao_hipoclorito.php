<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['record_id'])) {
    header("Location: ../index.php");
    exit;
}

$record_id = $_POST['record_id'];
$new_value = $_POST['consumption_liters'];
$user_id = $_SESSION['user_id'];

if (!is_numeric($new_value)) {
    $_SESSION['error_message'] = "Valor inválido.";
    header("Location: list_hipoclorito.php");
    exit;
}

$conn->begin_transaction();
try {
    // 1. Buscar o valor antigo, a data e o nome do tanque para o log
    $stmt_old = $conn->prepare("SELECT h.consumption_liters, h.reading_datetime, t.name as tank_name FROM hypochlorite_readings h JOIN tanks t ON h.tank_id = t.id WHERE h.id = ?");
    $stmt_old->bind_param("i", $record_id);
    $stmt_old->execute();
    $result_old = $stmt_old->get_result()->fetch_assoc();
    $old_value = $result_old['consumption_liters'];
    $tank_name = $result_old['tank_name'];
    $record_date = date('d/m/Y', strtotime($result_old['reading_datetime'])); // Guarda a data
    $stmt_old->close();

    // 2. Fazer o UPDATE
    $stmt = $conn->prepare("UPDATE hypochlorite_readings SET consumption_liters = ? WHERE id = ?");
    $stmt->bind_param("di", $new_value, $record_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Registo de hipoclorito atualizado com sucesso!";
            // ALTERAÇÃO: Adicionar a data à descrição do log
            $user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador desconhecido';
            $description = "O utilizador '$user_name' alterou o registo de hipoclorito do tanque '$tank_name' para o dia $record_date. Valor alterado de '$old_value' para '$new_value'.";
            log_action($conn, $user_id, 'UPDATE_HIPOCLORITO', $description);
        } else {
            $_SESSION['info_message'] = "Nenhuma alteração foi efetuada.";
        }
    } else {
        throw new Exception("Erro ao executar a atualização.");
    }
    $stmt->close();
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao atualizar o registo: " . $e->getMessage();
}

header("Location: list_hipoclorito.php");
exit;
?>