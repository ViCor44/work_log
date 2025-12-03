<?php
require_once 'core.php';
require_once 'phpqrcode/qrlib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $conn->begin_transaction();
    try {
        $asset_id = $_GET['id'];

        // --- Processar Uploads de Ficheiros ---
        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $target_dir = "uploads/photos/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            $photo_path = $target_dir . time() . '_' . basename($_FILES["photo"]["name"]);
            move_uploaded_file($_FILES["photo"]["tmp_name"], $photo_path);
        }
        $manual_path = null;
        if (isset($_FILES['manual']) && $_FILES['manual']['error'] == 0) {
            $target_dir = "uploads/manuals/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            $manual_path = $target_dir . time() . '_' . basename($_FILES["manual"]["name"]);
            move_uploaded_file($_FILES["manual"]["tmp_name"], $manual_path);
        }

        // --- Atualizar dados na tabela 'assets' ---
        $stmt_asset = $conn->prepare("
            UPDATE assets 
            SET name = ?, category_id = ?, description = ?, manufacturer = ?, model = ?, serial_number = ?, 
                purchase_date = ?, supplier = ?, warranty_date = ?, photo = ?, manual = ?, asset_type_id = ?
            WHERE id = ?
        ");
        
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $warranty_date = !empty($_POST['warranty_date']) ? $_POST['warranty_date'] : null;
        $asset_type_id = !empty($_POST['asset_type_id']) ? $_POST['asset_type_id'] : null;
        $photo = $photo_path ?: $_POST['current_photo'] ?? null;
        $manual = $manual_path ?: $_POST['current_manual'] ?? null;

        $stmt_asset->bind_param("sisssssssssi", 
            $_POST['name'], $_POST['category_id'], $_POST['description'], 
            $_POST['manufacturer'], $_POST['model'], $_POST['serial_number'],
            $purchase_date, $_POST['supplier'], $warranty_date,
            $photo, $manual, $asset_type_id, $asset_id
        );
        $stmt_asset->execute();
        $stmt_asset->close();

        // --- Atualizar ou adicionar campos personalizados ---
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            $stmt_update = $conn->prepare("UPDATE asset_custom_data SET value = ? WHERE asset_id = ? AND field_id = ?");
            foreach ($_POST['custom_fields'] as $field_id => $data) {
                $value = $data['value'];
                $stmt_update->bind_param("sii", $value, $asset_id, $field_id);
                $stmt_update->execute();
            }
            $stmt_update->close();
        }

        // --- Adicionar novos campos personalizados ---
        if (isset($_POST['new_custom_fields']) && is_array($_POST['new_custom_fields'])) {
            $stmt_new_field = $conn->prepare("INSERT INTO custom_fields (asset_type_id, name, type, default_value) VALUES (?, ?, ?, ?)");
            $stmt_new_data = $conn->prepare("INSERT INTO asset_custom_data (asset_id, field_id, value) VALUES (?, ?, ?)");
            foreach ($_POST['new_custom_fields']['name'] as $index => $name) {
                $type = $_POST['new_custom_fields']['type'][$index];
                $value = $_POST['new_custom_fields']['value'][$index];
                $default = !empty($value) ? $value : null;
                $stmt_new_field->bind_param("isss", $asset_type_id, $name, $type, $default);
                $stmt_new_field->execute();
                $field_id = $conn->insert_id;
                $stmt_new_data->bind_param("iis", $asset_id, $field_id, $value);
                $stmt_new_data->execute();
            }
            $stmt_new_field->close();
            $stmt_new_data->close();
        }

        $conn->commit();
        $_SESSION['success_message'] = "Ativo atualizado com sucesso!";
        header("Location: list_assets.php");

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Erro ao atualizar o ativo: " . $e->getMessage();
        header("Location: edit_asset.php?id=" . $asset_id);
    }
    exit;
}