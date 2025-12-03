<?php
session_start();
include 'db.php'; // Certifique-se de que este caminho está correto

// Teste se o objeto $pdo está definido
if (!isset($pdo)) {
    echo json_encode(['success' => false, 'message' => 'A conexão ao banco de dados não foi estabelecida.']);
    exit;
}

// Código para aceitar a ordem de trabalho
$data = json_decode(file_get_contents('php://input'), true);
$workOrderId = $data['id'];

// Verifique se o ID da ordem de trabalho foi recebido
if (!$workOrderId) {
    echo json_encode(['success' => false, 'message' => 'ID da ordem de trabalho não recebido.']);
    exit;
}

// Prepare a consulta para atualizar a ordem de trabalho
$stmt = $pdo->prepare("UPDATE work_orders SET accept_at = NOW(), accept_by = :accept_by WHERE id = :id");
$stmt->execute([
    'accept_by' => $_SESSION['username'], // Supondo que você tenha o nome de usuário armazenado na sessão
    'id' => $workOrderId
]);

// Responda com sucesso
echo json_encode(['success' => true, 'acceptor_name' => $_SESSION['username']]);
?>
