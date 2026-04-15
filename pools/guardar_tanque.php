<?php
require_once '../core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: gerir_tanques.php");
    exit;
}

// Recolha das variáveis do formulário
$name = $_POST['name'];
$type = $_POST['type'];
$water_freq = $_POST['water_reading_frequency'];
$hypo = $_POST['uses_hypochlorite'];
$analysis = $_POST['requires_analysis'];
$has_reject_counter = ($type === 'piscina' && isset($_POST['has_reject_counter'])) ? (int)$_POST['has_reject_counter'] : 0;
$volume_m3 = ($type === 'piscina' && !empty($_POST['volume_m3'])) ? (float)$_POST['volume_m3'] : null;
$has_controller = isset($_POST['has_controller']) ? (int)$_POST['has_controller'] : 0;
$controller_ip = ($has_controller == 1 && !empty($_POST['controller_ip'])) ? trim($_POST['controller_ip']) : null;

// Se estiver a editar (vem um 'id' escondido no formulário)
if (isset($_POST['id']) && !empty($_POST['id'])) {
    $tank_id = $_POST['id'];
    $stmt = $conn->prepare("
        UPDATE tanks SET 
            name = ?, type = ?, water_reading_frequency = ?, uses_hypochlorite = ?, requires_analysis = ?,
            has_controller = ?, controller_ip = ?, has_reject_counter = ?, volume_m3 = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssiiiisiii", $name, $type, $water_freq, $hypo, $analysis, $has_controller, $controller_ip, $has_reject_counter, $volume_m3, $tank_id);
} 
// Se estiver a criar um novo
else {
    $stmt = $conn->prepare("
        INSERT INTO tanks (name, type, water_reading_frequency, uses_hypochlorite, requires_analysis, has_controller, controller_ip, has_reject_counter, volume_m3) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssiiiisii", $name, $type, $water_freq, $hypo, $analysis, $has_controller, $controller_ip, $has_reject_counter, $volume_m3);
}

if ($stmt->execute()) {
    $_SESSION['success_message'] = "Tanque guardado com sucesso!";
} else {
    $_SESSION['error_message'] = "Erro ao guardar o tanque: " . $stmt->error;
}
$stmt->close();
header("Location: gerir_tanques.php");
exit;
?>