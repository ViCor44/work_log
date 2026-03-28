<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['work_order_id'])) {
    $work_order_id = $_POST['work_order_id'];
    $current_user_id = $_SESSION['user_id'];
    $current_user_name = $_SESSION['user_name']; // Assumindo que o nome está na sessão

    // Função auxiliar para inserir no histórico
    function add_history($conn, $wo_id, $user_id, $action, $description) {
        $stmt = $conn->prepare("INSERT INTO work_order_history (work_order_id, user_id, action, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $wo_id, $user_id, $action, $description);
        $stmt->execute();
        $stmt->close();
    }

    // Ação: ACEITAR a OT
    if (isset($_POST['accept'])) {
        $accept_at = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("UPDATE work_orders SET status = 'Aceite', accept_by = ?, accept_at = ? WHERE id = ?");
        $stmt->bind_param('isi', $current_user_id, $accept_at, $work_order_id);
        $stmt->execute();
        $stmt->close();
        add_history($conn, $work_order_id, $current_user_id, 'ACEITE', "OT aceite por $current_user_name.");
    }

    // Ação: FECHAR ou REABRIR a OT
    if (isset($_POST['close'])) {
        $stmt_status = $conn->prepare("SELECT status FROM work_orders WHERE id = ?");
        $stmt_status->bind_param('i', $work_order_id);
        $stmt_status->execute();
        $stmt_status->bind_result($current_status);
        $stmt_status->fetch();
        $stmt_status->close();

        if ($current_status === 'Fechada') {
            // Reabrir OT
            $stmt = $conn->prepare("UPDATE work_orders SET status = 'Em Andamento', closed_at = NULL WHERE id = ?");
            $stmt->bind_param('i', $work_order_id);
            $stmt->execute();
            $stmt->close();
            add_history($conn, $work_order_id, $current_user_id, 'REABERTA', "OT reaberta por $current_user_name.");
        } else {
            // Fechar OT
            $closed_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE work_orders SET status = 'Fechada', closed_at = ? WHERE id = ?");
            $stmt->bind_param('si', $closed_at, $work_order_id);
            $stmt->execute();
            $stmt->close();
            add_history($conn, $work_order_id, $current_user_id, 'FECHADA', "OT fechada por $current_user_name.");
        }
    }

    // Ação: REATRIBUIR a OT
    if (!empty($_POST['assign_user'])) {
        $new_user_id = $_POST['assign_user'];
        
        // Vai buscar o nome do utilizador antigo e do novo para o log
        $stmt_names = $conn->prepare("
            SELECT 
                (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = w.assigned_user) as old_user,
                (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = ?) as new_user
            FROM work_orders w WHERE w.id = ?
        ");
        $stmt_names->bind_param("ii", $new_user_id, $work_order_id);
        $stmt_names->execute();
        $names = $stmt_names->get_result()->fetch_assoc();
        $stmt_names->close();

        // Faz o update
        $stmt = $conn->prepare("UPDATE work_orders SET assigned_user = ?, status = 'Pendente', accept_by = NULL, accept_at = NULL WHERE id = ?");
        $stmt->bind_param('ii', $new_user_id, $work_order_id);
        $stmt->execute();
        $stmt->close();
        
        $description = "OT reatribuída de " . $names['old_user'] . " para " . $names['new_user'] . " por $current_user_name.";
        add_history($conn, $work_order_id, $current_user_id, 'REATRIBUIDA', $description);
    }

    header("Location: view_work_order.php?id=$work_order_id");
    exit;
}
?>