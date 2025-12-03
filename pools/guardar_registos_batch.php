<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['tipo_registo'])) {
    header("Location: registos.php");
    exit;
}

// --- Variáveis Iniciais ---
$user_id = $_SESSION['user_id'];
$tipo_registo = $_POST['tipo_registo'];
$now = date('Y-m-d H:i:s');
$registos_inseridos = 0;
$nome_do_registo = '';
$redirect_url = 'registos.php'; // URL Padrão

$conn->begin_transaction();

try {
    switch ($tipo_registo) {
        
case 'analise':
            $nome_do_registo = "análise";
            $stmt_insert = $conn->prepare("INSERT INTO analyses (tank_id, user_id, analysis_datetime, period, ph_level, chlorine_level, temperature, conductivity, dissolved_solids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (isset($_POST['ph_level'])) { 
                $periodo = $_POST['periodo'];
                foreach ($_POST['ph_level'] as $tank_id => $ph_value) {
                    
                    $cloro_val = !empty($_POST['chlorine_level'][$tank_id]) ? $_POST['chlorine_level'][$tank_id] : null;
                    $temp_val = !empty($_POST['temperature'][$tank_id]) ? $_POST['temperature'][$tank_id] : null;
                    $cond_val = !empty($_POST['conductivity'][$tank_id]) ? $_POST['conductivity'][$tank_id] : null;
                    
                    // ======================================================
                    // == ALTERAÇÃO: O valor dos sólidos é calculado aqui ==
                    // ======================================================
                    $solidos_val = null;
                    if ($cond_val !== null) {
                        // O valor é sempre metade da condutividade, calculado no servidor
                        $solidos_val = $cond_val / 2;
                    }
                    // ======================================================
                    
                    if (!empty($ph_value) || $cloro_val || $temp_val || $cond_val) { // O $solidos_val já não precisa de estar na condição
                        
                        $stmt_insert->bind_param("isssddddd", $tank_id, $user_id, $now, $periodo, $ph_value, $cloro_val, $temp_val, $cond_val, $solidos_val);
                        $stmt_insert->execute();
                        $registos_inseridos++;
                    }
                }
            }
            $stmt_insert->close();
            $redirect_url = 'relatorio_analises.php';
            break;

        case 'agua_manha':
        case 'agua_tarde':
            $nome_do_registo = ($tipo_registo === 'agua_manha') ? "leitura da manhã" : "leitura da tarde";
            if (isset($_POST['agua'])) {
                // Query simples que guarda apenas a leitura do contador
                $stmt_insert = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
                foreach ($_POST['agua'] as $tank_id => $agua_value) {
                    if (!empty($agua_value)) {
                        $stmt_insert->bind_param("iisd", $tank_id, $user_id, $now, $agua_value);
                        $stmt_insert->execute();
                        $registos_inseridos++;
                    }
                }
                $stmt_insert->close();
            }
            break;

        case 'hipoclorito':
            $nome_do_registo = "consumo de hipoclorito";
            if (isset($_POST['hipo'])) {
                $target_date = (isset($_POST['target_date'])) ? $_POST['target_date'] : null;
                $datetime = $target_date ? $target_date . ' ' . date('H:i:s') : $now;

                foreach ($_POST['hipo'] as $tank_id => $hipo_value_str) {
                    if (empty($hipo_value_str)) continue;

                    $hipo_value = (float) $hipo_value_str;
                    $existing_id = (isset($_POST['existing_id'][$tank_id])) ? $_POST['existing_id'][$tank_id] : null;

                    if ($existing_id) {
                        // Atualizar registo existente
                        $stmt = $conn->prepare("UPDATE hypochlorite_readings SET consumption_liters = ?, reading_datetime = ?, user_id = ? WHERE id = ?");
                        $stmt->bind_param("dsii", $hipo_value, $datetime, $user_id, $existing_id);
                        $result = $stmt->execute();
                        if ($result) $registos_inseridos++; // Conta como atualizado
                        $stmt->close();
                    } else {
                        // Inserir novo registo
                        $stmt = $conn->prepare("INSERT INTO hypochlorite_readings (tank_id, user_id, reading_datetime, consumption_liters) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("iisd", $tank_id, $user_id, $datetime, $hipo_value);
                        $result = $stmt->execute();
                        if ($result) $registos_inseridos++;
                        $stmt->close();
                    }
                }
            }
            // Define o redirecionamento para a lista de histórico, com mês se aplicável
            if ($target_date) {
                $month = substr($target_date, 0, 7);
                $redirect_url = "list_hipoclorito.php?month={$month}";
            } else {
                $redirect_url = 'list_hipoclorito.php';
            }
            break;
            
        default:
            throw new Exception("Tipo de registo inválido.");
    }
    
    $conn->commit();
    if ($registos_inseridos > 0) {
        $_SESSION['success_message'] = "$registos_inseridos registos de $nome_do_registo guardados com sucesso!";
    } else {
        $_SESSION['info_message'] = "Nenhum novo registo foi submetido.";
    }

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Ocorreu um erro ao guardar os registos: " . $e->getMessage();
}

header("Location: " . $redirect_url);
exit;