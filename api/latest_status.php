<?php
// Inclui o core para ter acesso à base de dados e às configurações
require_once '../core.php';

// Define o cabeçalho da resposta como JSON, para que os navegadores e outras apps saibam como interpretar
header('Content-Type: application/json; charset=utf-8');

// Array principal que irá conter toda a nossa resposta
$response_data = [
    'analyses' => [],
    'hipoclorito' => [],
    'agua_piscinas' => [],
    'agua_outros_tanques' => [],
    'agua_rede' => [],
    'edificio' => []
];

// --- 1. Buscar as Últimas Análises ---
$sql_analyses = "
    SELECT t.name AS tank_name, a.*
    FROM analyses a
    INNER JOIN (
        SELECT tank_id, MAX(analysis_datetime) AS max_datetime
        FROM analyses
        GROUP BY tank_id
    ) AS latest ON a.tank_id = latest.tank_id AND a.analysis_datetime = latest.max_datetime
    JOIN tanks t ON a.tank_id = t.id
    WHERE t.requires_analysis = 1
";
$result = $conn->query($sql_analyses);
if ($result) {
    $response_data['analyses'] = $result->fetch_all(MYSQLI_ASSOC);
}

// --- 2. Buscar os Últimos Registos de Hipoclorito ---
$sql_hipo = "
    SELECT t.name AS tank_name, h.*
    FROM hypochlorite_readings h
    INNER JOIN (
        SELECT tank_id, MAX(reading_datetime) AS max_datetime
        FROM hypochlorite_readings
        GROUP BY tank_id
    ) AS latest ON h.tank_id = latest.tank_id AND h.reading_datetime = latest.max_datetime
    JOIN tanks t ON h.tank_id = t.id
    WHERE t.uses_hypochlorite = 1
";
$result = $conn->query($sql_hipo);
if ($result) {
    $response_data['hipoclorito'] = $result->fetch_all(MYSQLI_ASSOC);
}

// --- 3. Buscar as Últimas Leituras de Água para TODAS as categorias ---
$sql_water = "
    SELECT t.name AS tank_name, t.type AS tank_type, w.*
    FROM water_readings w
    INNER JOIN (
        SELECT tank_id, MAX(reading_datetime) AS max_datetime
        FROM water_readings
        GROUP BY tank_id
    ) AS latest ON w.tank_id = latest.tank_id AND w.reading_datetime = latest.max_datetime
    JOIN tanks t ON w.tank_id = t.id
    WHERE t.water_reading_frequency > 0
";
$result = $conn->query($sql_water);
if ($result) {
    $all_water_readings = $result->fetch_all(MYSQLI_ASSOC);
    // Agora, separamos os resultados pelas categorias corretas
    foreach ($all_water_readings as $reading) {
        if ($reading['tank_name'] === 'Rede') {
            $response_data['agua_rede'][] = $reading;
        } elseif ($reading['tank_name'] === 'Edificio') {
            $response_data['edificio'][] = $reading;
        } elseif ($reading['tank_type'] === 'piscina') {
            $response_data['agua_piscinas'][] = $reading;
        } else {
            $response_data['agua_outros_tanques'][] = $reading;
        }
    }
}

// --- Final: Codificar o array completo para JSON e enviá-lo ---
// JSON_PRETTY_PRINT torna o output legível para humanos
// JSON_UNESCAPED_UNICODE garante que os acentos (ç, á, etc.) aparecem corretamente
echo json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

exit;
?>