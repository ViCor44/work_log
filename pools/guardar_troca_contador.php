<?php
require_once '../core.php';
require_once 'meter_continuity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: form_troca_contador.php');
    exit;
}

ensure_meter_replacements_table($conn);

$tankId = isset($_POST['tank_id']) ? (int)$_POST['tank_id'] : 0;
$readingType = isset($_POST['reading_type']) ? $_POST['reading_type'] : 'normal';
$replacementDatetime = isset($_POST['replacement_datetime']) ? trim($_POST['replacement_datetime']) : '';
$oldReading = isset($_POST['old_reading']) ? (float)$_POST['old_reading'] : null;
$newReading = isset($_POST['new_reading']) ? (float)$_POST['new_reading'] : null;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if ($tankId <= 0 || !in_array($readingType, ['normal', 'rejected'], true) || $replacementDatetime === '' || $oldReading === null || $newReading === null) {
    $_SESSION['error_message'] = 'Dados inválidos para registar a troca de contador.';
    header('Location: form_troca_contador.php');
    exit;
}

$replacementDatetime = str_replace('T', ' ', $replacementDatetime) . ':00';
$offsetDelta = $oldReading - $newReading;

$stmt = $conn->prepare("\n+    INSERT INTO meter_replacements\n+    (tank_id, reading_type, replacement_datetime, old_reading, new_reading, offset_delta, notes, created_by)\n+    VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n+");

if (!$stmt) {
    $_SESSION['error_message'] = 'Erro ao preparar o registo de troca.';
    header('Location: form_troca_contador.php');
    exit;
}

$stmt->bind_param(
    'issdddsi',
    $tankId,
    $readingType,
    $replacementDatetime,
    $oldReading,
    $newReading,
    $offsetDelta,
    $notes,
    $userId
);

if ($stmt->execute()) {
    $_SESSION['success_message'] = 'Troca de contador registada com sucesso. Offset aplicado: ' . number_format($offsetDelta, 3, ',', '.');
} else {
    $_SESSION['error_message'] = 'Erro ao guardar a troca de contador.';
}

$stmt->close();

header('Location: form_troca_contador.php');
exit;
