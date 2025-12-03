<?php
require_once 'core.php';

// Apenas administradores podem executar estas ações
if ($_SESSION['user_type'] !== 'admin') {
    header("Location: redirect_page.php");
    exit;
}

$action = null;
if (isset($_POST['action'])) {
    $action = $_POST['action'];
} elseif (isset($_GET['action'])) {
    $action = $_GET['action'];
}

switch ($action) {
    case 'create_type':
        if (!empty($_POST['type_name'])) {
            $stmt = $conn->prepare("INSERT INTO asset_types (name) VALUES (?)");
            $stmt->bind_param("s", $_POST['type_name']);
            $stmt->execute();
        }
        header("Location: gerir_tipos_ativo.php");
        break;

    case 'create_field':
        if (!empty($_POST['type_id']) && !empty($_POST['field_label']) && !empty($_POST['field_type'])) {
            $stmt = $conn->prepare("INSERT INTO asset_type_fields (asset_type_id, field_label, field_type) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $_POST['type_id'], $_POST['field_label'], $_POST['field_type']);
            $stmt->execute();
        }
        header("Location: gerir_campos_tipo.php?type_id=" . $_POST['type_id']);
        break;

    case 'delete_field':
        if (!empty($_GET['id'])) {
            $stmt = $conn->prepare("DELETE FROM asset_type_fields WHERE id = ?");
            $stmt->bind_param("i", $_GET['id']);
            $stmt->execute();
        }
        header("Location: gerir_campos_tipo.php?type_id=" . $_GET['type_id']);
        break;
    
    default:
        header("Location: gerir_tipos_ativo.php");
        break;
}
exit;
?>