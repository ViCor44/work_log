<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

include 'db.php';

if (!isset($_POST['report_id']) || !isset($_POST['received'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Parâmetros em falta']);
    exit;
}

$report_id = (int) $_POST['report_id'];
$received  = ((int) $_POST['received']) ? 1 : 0;
$admin_id  = (int) $_SESSION['user_id'];

if ($received === 1) {
    $stmt = $conn->prepare("UPDATE reports SET received = 1, received_by = ?, received_at = NOW() WHERE id = ?");
    $stmt->bind_param("ii", $admin_id, $report_id);
} else {
    $stmt = $conn->prepare("UPDATE reports SET received = 0, received_by = NULL, received_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $report_id);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar o status: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$stmt->close();

$response = [
    'success'       => true,
    'received'      => $received,
    'received_by'   => null,
    'received_at'   => null,
];

if ($received === 1) {
    $stmt = $conn->prepare("SELECT CONCAT(u.first_name, ' ', u.last_name) AS admin_name, r.received_at
                            FROM reports r
                            LEFT JOIN users u ON u.id = r.received_by
                            WHERE r.id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $stmt->bind_result($admin_name, $received_at);
    if ($stmt->fetch()) {
        $response['received_by'] = $admin_name;
        $response['received_at'] = $received_at;
    }
    $stmt->close();
}

echo json_encode($response);
