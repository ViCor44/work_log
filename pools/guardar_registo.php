<?php
require_once '../db.php'; // Ou db.php
require_once '../auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: registos.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$tank_id = $_POST['tank_id'];
$now = date('Y-m-d H:i:s');

$conn->begin_transaction();

try {
    // 1. Guardar Análises (se existirem)
    if (!empty($_POST['ph_level']) || !empty($_POST['chlorine_level']) || !empty($_POST['temperature'])) {
        $stmt = $conn->prepare("INSERT INTO analyses (tank_id, user_id, analysis_datetime, ph_level, chlorine_level, temperature, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ph = !empty($_POST['ph_level']) ? $_POST['ph_level'] : null;
        $cloro = !empty($_POST['chlorine_level']) ? $_POST['chlorine_level'] : null;
        $temp = !empty($_POST['temperature']) ? $_POST['temperature'] : null;
        $notes = !empty($_POST['analysis_notes']) ? $_POST['analysis_notes'] : null;
        $stmt->bind_param("iisddds", $tank_id, $user_id, $now, $ph, $cloro, $temp, $notes);
        $stmt->execute();
        $stmt->close();
    }

    // 2. Guardar Leituras de Água
    // Leitura Única
    if (!empty($_POST['water_reading_day'])) {
        $stmt = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisd", $tank_id, $user_id, $now, $_POST['water_reading_day']);
        $stmt->execute();
        $stmt->close();
    }
    // Leitura da Manhã
    if (!empty($_POST['water_reading_morning'])) {
        $stmt = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisd", $tank_id, $user_id, $now, $_POST['water_reading_morning']);
        $stmt->execute();
        $stmt->close();
    }
    // Leitura da Tarde
    if (!empty($_POST['water_reading_afternoon'])) {
        $stmt = $conn->prepare("INSERT INTO water_readings (tank_id, user_id, reading_datetime, meter_value) VALUES (?, ?, ?, ?)");
        // Para a tarde, podemos forçar a hora para as 18:00 para diferenciar, ou apenas usar $now
        $afternoon_time = date('Y-m-d 18:00:00');
        $stmt->bind_param("iisd", $tank_id, $user_id, $afternoon_time, $_POST['water_reading_afternoon']);
        $stmt->execute();
        $stmt->close();
    }

    // 3. Guardar Consumo de Hipoclorito
    if (!empty($_POST['hypo_consumption'])) {
        $stmt = $conn->prepare("INSERT INTO hypochlorite_readings (tank_id, user_id, reading_datetime, consumption_liters) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iisd", $tank_id, $user_id, $now, $_POST['hypo_consumption']);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    header("Location: registos.php?status=success");

} catch (Exception $e) {
    $conn->rollback();
    // Idealmente, registar o erro num log
    die("Ocorreu um erro ao guardar o registo. Por favor, tente novamente.");
}

exit;