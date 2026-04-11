<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso nao autorizado']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['error' => 'ID do filtro invalido']);
    exit;
}

$filter_id = (int) $_GET['id'];

$stmt = $conn->prepare(
    "SELECT rem.id, rem.name, rem.ip_address, rem.slave_id
     FROM remote_equipment rem
     LEFT JOIN categories cat ON cat.id = rem.category_id
     WHERE rem.id = ? AND LOWER(COALESCE(cat.name, '')) LIKE '%filtro%'
     LIMIT 1"
);
$stmt->bind_param('i', $filter_id);
$stmt->execute();
$result = $stmt->get_result();
$filter = $result->fetch_assoc();
$stmt->close();

if (!$filter) {
    echo json_encode(['error' => 'Filtro nao encontrado']);
    exit;
}

$ip = $filter['ip_address'];
$slave_id = (int) $filter['slave_id'];

$url = "http://{$ip}/api/status/{$slave_id}";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_FAILONERROR => false,
]);

$response_text = curl_exec($ch);
if ($response_text === false) {
    $error = curl_error($ch);
    curl_close($ch);
    echo json_encode(['error' => 'Timeout ou erro ao contactar filtro: ' . $error]);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ((int) $http_code !== 200) {
    echo json_encode(['error' => 'Resposta HTTP invalida do filtro']);
    exit;
}

$data = json_decode($response_text, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    echo json_encode(['error' => 'Resposta invalida do filtro']);
    exit;
}

$data['filter_id'] = $filter_id;
$data['filter_name'] = $filter['name'];
$data['ip_address'] = $ip;
$data['slave_id'] = $slave_id;

echo json_encode($data);
