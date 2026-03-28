<?php
require_once 'core.php';

if (!isset($_GET['type_id']) || !is_numeric($_GET['type_id'])) {
    exit;
}
$type_id = $_GET['type_id'];

$stmt = $conn->prepare("SELECT * FROM asset_type_fields WHERE asset_type_id = ? ORDER BY id ASC");
$stmt->bind_param("i", $type_id);
$stmt->execute();
$fields = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Gera o HTML para cada campo
foreach ($fields as $field) {
    echo '<div class="mb-3">';
    echo '    <label for="custom_field_'.$field['id'].'" class="form-label">'.htmlspecialchars($field['field_label']).'</label>';
    echo '    <input type="'.$field['field_type'].'" class="form-control" id="custom_field_'.$field['id'].'" name="custom_fields['.$field['id'].']">';
    echo '</div>';
}
?>