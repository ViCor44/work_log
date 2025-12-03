<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['license_key'])) {
    $nova_licenca = $_POST['license_key'];
    // Usa "INSERT ... ON DUPLICATE KEY UPDATE" para inserir ou atualizar a chave
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("ss", $nova_licenca, $nova_licenca);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Chave de licença atualizada com sucesso!";
    } else {
        $_SESSION['error_message'] = "Erro ao atualizar a chave de licença.";
    }
    $stmt->close();
}
header("Location: licenca.php");
exit;
?>