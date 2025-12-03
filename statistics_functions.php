<?php
// Arquivo: statistics_functions.php

// Função para obter o total de relatórios por técnico
function getReportsByTechnician($conn) {
    $sql = "SELECT u.first_name, u.last_name, COUNT(r.id) AS total_reports
            FROM users u
            JOIN reports r ON u.id = r.technician_id
            GROUP BY u.id";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Função para obter o total de ordens de trabalho por técnico
function getWorkOrdersByTechnician($conn) {
    $sql = "SELECT u.first_name, u.last_name, COUNT(w.id) AS total_work_orders
            FROM users u
            JOIN work_orders w ON u.id = w.assigned_user
            GROUP BY u.id";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Função para obter o total de ordens de trabalho por status
function getWorkOrdersByStatus($conn) {
    $sql = "SELECT status, COUNT(id) AS total_by_status
            FROM work_orders
            GROUP BY status";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Função para obter o total de ordens de trabalho por prioridade
function getWorkOrdersByPriority($conn) {
    $sql = "SELECT priority, COUNT(id) AS total_by_priority
            FROM work_orders
            GROUP BY priority";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Função para obter o total de ordens de trabalho abertas e fechadas
function getTotalOpenClosedWorkOrders($conn) {
    $sql = "SELECT 
                SUM(CASE WHEN status = 'Aberta' THEN 1 ELSE 0 END) AS total_open,
                SUM(CASE WHEN status = 'Fechada' THEN 1 ELSE 0 END) AS total_closed
            FROM work_orders";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Função para calcular o tempo médio de conclusão das ordens de trabalho
function getAverageCompletionTime($conn) {
    $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) AS avg_completion_time
            FROM work_orders
            WHERE status = 'Fechada' AND closed_at IS NOT NULL";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Função para obter a OT com o menor tempo de execução
function getMinExecutionTimeWorkOrder($conn) {
    $sql = "SELECT id, TIMESTAMPDIFF(HOUR, created_at, closed_at) AS execution_time
            FROM work_orders
            WHERE status = 'Fechada' AND closed_at IS NOT NULL
            ORDER BY execution_time ASC LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Função para obter a OT com o maior tempo de execução
function getMaxExecutionTimeWorkOrder($conn) {
    $sql = "SELECT id, TIMESTAMPDIFF(HOUR, created_at, closed_at) AS execution_time
            FROM work_orders
            WHERE status = 'Fechada' AND closed_at IS NOT NULL
            ORDER BY execution_time DESC LIMIT 1";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}
?>
