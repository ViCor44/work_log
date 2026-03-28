<?php
require_once 'core.php';
require_once 'phpqrcode/qrlib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
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

        // --- Inserir os dados na tabela 'assets' ---
        $stmt_asset = $conn->prepare("
            INSERT INTO assets (name, category_id, description, manufacturer, model, serial_number, purchase_date, supplier, warranty_date, photo, manual, asset_type_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
		        
        // Define variáveis, usando null se estiverem vazias
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $warranty_date = !empty($_POST['warranty_date']) ? $_POST['warranty_date'] : null;
        $asset_type_id = !empty($_POST['asset_type_id']) ? $_POST['asset_type_id'] : null;

        $stmt_asset->bind_param("sisssssssssi", 
            $_POST['name'], $_POST['category_id'], $_POST['description'], 
            $_POST['manufacturer'], $_POST['model'], $_POST['serial_number'],
            $purchase_date, $_POST['supplier'], $warranty_date,
            $photo_path, $manual_path, $asset_type_id
        );
        $stmt_asset->execute();
        $new_asset_id = $stmt_asset->insert_id;
        $stmt_asset->close();
		
		// ======================================================
        // == A SUA LÓGICA DE QR CODE, ADAPTADA E INTEGRADA ==
        // ======================================================
        
        // 1. Define o URL para o QR Code (usando o seu URL base)
        $url = "http://serverlab/worklog/view_asset.php?id=" . $new_asset_id;
        
        // 2. Define onde guardar a imagem do QR Code
        $qr_code_dir = "uploads/qrcodes/";
        if (!is_dir($qr_code_dir)) {
            mkdir($qr_code_dir, 0777, true);
        }
        $qrcode_file = $qr_code_dir . "qrcode_" . $new_asset_id . ".png";
        
        // 3. Gera o ficheiro PNG do QR Code
        QRcode::png($url, $qrcode_file);

        // 4. Atualiza a tabela 'assets' com o caminho para o código QR
        // Assumindo que a sua coluna se chama 'qr_code_path' como definimos antes
        $stmt_qr = $conn->prepare("UPDATE assets SET qrcode = ? WHERE id = ?");
        $stmt_qr->bind_param("si", $qrcode_file, $new_asset_id);
        $stmt_qr->execute();
        $stmt_qr->close();

        // ======================================================

        // --- Inserir os dados personalizados na tabela 'asset_custom_data' ---
        if (isset($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
            $stmt_custom = $conn->prepare("INSERT INTO asset_custom_data (asset_id, field_id, value) VALUES (?, ?, ?)");
            foreach ($_POST['custom_fields'] as $field_id => $value) {
                if (!empty($value)) {
                    $stmt_custom->bind_param("iis", $new_asset_id, $field_id, $value);
                    $stmt_custom->execute();
                }
            }
            $stmt_custom->close();
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Ativo criado com sucesso!";
        header("Location: list_assets.php");

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Erro ao criar o ativo: " . $e->getMessage();
        header("Location: create_asset.php");
    }
    exit;
}
?>