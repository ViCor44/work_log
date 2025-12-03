<?php
// Define o tipo de conteúdo como JSON
header('Content-Type: application/json');
require_once '../db.php'; // Usa '..' para subir um nível e encontrar o db.php

// Validação do ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'ID do equipamento inválido.']);
    exit;
}
$equipment_id = (int)$_GET['id'];

// Prepara e executa a consulta para obter os logs
$stmt = $conn->prepare("
    SELECT 
        log.timestamp,
        COALESCE(usr.username, 'Sistema / Manual') AS user_name,
        log.action,
        log.details
    FROM 
        equipment_log AS log
    LEFT JOIN 
        users AS usr ON log.user_id = usr.id
    WHERE 
        log.equipment_id = ?
    ORDER BY 
        log.timestamp DESC
    LIMIT 100 -- Limita aos últimos 100 registos para performance
");

$stmt->bind_param("i", $equipment_id);
$stmt->execute();
$result = $stmt->get_result();
$log_entries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Devolve os resultados como JSON
echo json_encode($log_entries);