<?php
require_once '../core.php'; // core.php já inclui a db.php e a função log_action

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['edit_date'])) {
    header("Location: ../index.php");
    exit;
}

$edit_date = $_POST['edit_date'];
$user_id = $_SESSION['user_id'];
$updates_made = 0;
$inserts_made = 0;
$log_details = []; // Array para guardar os detalhes para o log

$conn->begin_transaction();

try {
    // Prepara as queries uma vez para reutilização
    $stmt_update = $conn->prepare("UPDATE water_readings SET meter_value = ? WHERE id = ?");
    $stmt_insert = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
    $stmt_fetch = $conn->prepare("SELECT w.meter_value, t.name as tank_name FROM water_readings w JOIN tanks t ON w.tank_id = t.id WHERE w.id = ?");

    // Verifica se existem dados para processar
    if (isset($_POST['meter_value'])) {
        foreach ($_POST['meter_value'] as $tank_id => $value) {
            // Só processa se o utilizador inseriu um valor
            if (!empty($value) && is_numeric($value)) {
                $record_id = $_POST['record_id'][$tank_id];

                // Se já existe um registo, faz UPDATE
                if (!empty($record_id)) {
                    // 1. Buscar valor antigo e nome do tanque para o log
                    $stmt_fetch->bind_param("i", $record_id);
                    $stmt_fetch->execute();
                    $result_old = $stmt_fetch->get_result()->fetch_assoc();
                    $old_value = $result_old['meter_value'];
                    $tank_name = $result_old['tank_name'];

                    // Só faz o update e o log se o valor realmente mudou
                    if ($old_value != $value) {
                        // 2. Fazer o UPDATE
                        $stmt_update->bind_param("di", $value, $record_id);
                        $stmt_update->execute();
                        $updates_made++;

                        // 3. Prepara a mensagem de log para esta alteração
                        $log_details[] = "Leitura do tanque '$tank_name' alterada de '$old_value' para '$value'.";
                    }
                } 
                // Se não existe registo (era um campo vazio que foi preenchido), cria um novo (INSERT)
                else {
                    $datetime = $edit_date . ' 09:00:00'; // Assume uma hora padrão
                    $stmt_insert->bind_param("iisd", $tank_id, $user_id, $datetime, $value);
                    $stmt_insert->execute();
                    $inserts_made++;
                }
            }
        }
    }
    
    $stmt_update->close();
    $stmt_insert->close();
    $stmt_fetch->close();

    // 4. Se houve alguma alteração, cria um único registo de log
    if ($updates_made > 0) {
        $user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador desconhecido';
        $description = "O utilizador '$user_name' editou as leituras de 'Outros Tanques'. Detalhes: " . implode(" | ", $log_details);
        log_action($conn, $user_id, 'UPDATE_AGUA_OUTROS', $description);
    }

    // Define a mensagem de sucesso com base no que aconteceu
 if ($updates_made > 0 || $inserts_made > 0) {
        $user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador desconhecido';
        // ALTERAÇÃO: Adicionar a data à descrição do log
        $record_date = date('d/m/Y', strtotime($edit_date));
        $description = "O utilizador '$user_name' editou as leituras de 'Outros Tanques' para o dia $record_date. Detalhes: " . implode(" | ", $log_details);
        log_action($conn, $user_id, 'UPDATE_AGUA_OUTROS', $description);
        $_SESSION['success_message'] = "$updates_made registo(s) atualizado(s) e $inserts_made registo(s) criado(s) com sucesso!";
    } else {
        $_SESSION['info_message'] = "Nenhuma alteração foi detetada.";
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao guardar as alterações: " . $e->getMessage();
}

// Redireciona de volta para o relatório, para o mês correto
$month_year = date('Y-m', strtotime($edit_date));
header("Location: list_agua_outros.php?month=" . $month_year);
exit;
?>