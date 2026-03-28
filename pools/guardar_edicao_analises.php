<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['edit_date'])) {
    header("Location: ../index.php");
    exit;
}

$edit_date = $_POST['edit_date'];
$user_id = $_SESSION['user_id'];
$updates_made = 0;
$log_details = []; // Array para guardar os detalhes para o log

$conn->begin_transaction();

try {
    // Prepara as queries uma vez para reutilização
    $stmt_update = $conn->prepare("UPDATE analyses SET ph_level=?, chlorine_level=?, temperature=?, conductivity=?, dissolved_solids=? WHERE id=?");
    $stmt_fetch = $conn->prepare("SELECT * FROM analyses WHERE id = ?");
    
    // Busca os nomes de todos os tanques uma vez e guarda num mapa para fácil acesso
    $tanks_info_query = $conn->query("SELECT id, name FROM tanks");
    $tanks_map = array_column($tanks_info_query->fetch_all(MYSQLI_ASSOC), 'name', 'id');

    // Processa os dados da MANHÃ e da TARDE
    foreach (['manha', 'tarde'] as $periodo) {
        if (isset($_POST['analysis_id'][$periodo])) {
            foreach ($_POST['analysis_id'][$periodo] as $tank_id => $analysis_id) {
                
                if (!empty($analysis_id)) {
                    // 1. Buscar valor antigo para o log
                    $stmt_fetch->bind_param("i", $analysis_id);
                    $stmt_fetch->execute();
                    $old_data = $stmt_fetch->get_result()->fetch_assoc();
                    
                    // Vai buscar os novos valores do formulário
                    $ph_new = $_POST['ph_level'][$periodo][$tank_id];
                    $cl_new = $_POST['chlorine_level'][$periodo][$tank_id];
                    $temp_new = $_POST['temperature'][$periodo][$tank_id];
                    $cond_new = $_POST['conductivity'][$periodo][$tank_id];
                    $solids_new = !empty($cond_new) ? $cond_new / 2 : null;
                    
                    // Compara os valores e regista as diferenças
                    $current_log_details = [];
                    if ($old_data['ph_level'] != $ph_new) $current_log_details[] = "pH alterado de ".$old_data['ph_level']." para ".$ph_new;
                    if ($old_data['chlorine_level'] != $cl_new) $current_log_details[] = "Cloro alterado de ".$old_data['chlorine_level']." para ".$cl_new;
                    // (Pode adicionar a mesma lógica para os outros campos)

                    // Se houve alterações para este tanque, regista no log
                    if (!empty($current_log_details)) {
                         // ALTERAÇÃO: Adiciona o nome do tanque aos detalhes do log
                        $tank_name = isset($tanks_map[$tank_id]) ? $tanks_map[$tank_id] : "ID #$tank_id";
                        $log_details[] = "Tanque '$tank_name' (Periodo ".ucfirst($periodo)."): " . implode(', ', $current_log_details) . ".";
                    }

                    // 2. Fazer o UPDATE
                    $stmt_update->bind_param("dddddi", $ph_new, $cl_new, $temp_new, $cond_new, $solids_new, $analysis_id);
                    $stmt_update->execute();
                    if ($stmt_update->affected_rows > 0) {
                        $updates_made++;
                    }
                }
            }
        }
    }
    
    $stmt_update->close();
    $stmt_fetch->close();

    // 3. Se houve alguma alteração, cria um único registo de log
    if ($updates_made > 0) {
        $user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilizador desconhecido';
        $description = "O utilizador '$user_name' editou as análises do dia ".date('d/m/Y', strtotime($edit_date)).". Detalhes: " . implode(" | ", $log_details);
        log_action($conn, $user_id, 'UPDATE_ANALISES', $description);
        $_SESSION['success_message'] = "Análises atualizadas com sucesso!";
    } else {
        $_SESSION['info_message'] = "Nenhuma alteração foi detetada.";
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erro ao guardar as alterações: " . $e->getMessage();
}
// Redireciona de volta para o relatório
header("Location: relatorio_analises.php?report_date=" . $edit_date);
exit;
?>