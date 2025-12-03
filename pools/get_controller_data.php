<?php
require_once '../core.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Acesso não autorizado']);
    exit;
}

if (!isset($_GET['ip'])) {
    echo json_encode(['error' => 'IP do controlador não especificado']);
    exit;
}

$ip = $_GET['ip'];

$url = "http://" . $ip; // URL base

// Se o IP NÃO começar por "192.", adiciona /ajax_inputs
// A função strpos() verifica a posição da string. Se for 0, significa que está no início.
if (strpos($ip, '192.') !== 0) {
    $url .= "/ajax_inputs";
}

// Usamos cURL para ir buscar os dados do controlador
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Timeout de 5 segundos
$response_text = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || $response_text === false) {
    echo json_encode(['error' => 'Não foi possível contactar o controlador no IP: ' . $ip]);
    exit;
}

// ======================================================
// == NOVA LÓGICA PARA PROCESSAR XML OU JSON ==
// ======================================================

// Tenta descodificar a resposta como JSON primeiro
$data = json_decode($response_text, true);

// Se a descodificação JSON falhar, e a resposta parece ser XML
if (json_last_error() !== JSON_ERROR_NONE && strpos(trim($response_text), '<?xml') === 0) {
    try {
        // Converte a string XML num objeto
        $xml = new SimpleXMLElement($response_text);
        
        // Converte o objeto XML para um array JSON (isto é uma conversão simples)
        // O resultado será algo como {"ph": "7.4", "cloro": "1.5"}
        $json_string = json_encode($xml);
        $data = json_decode($json_string, true);

    } catch (Exception $e) {
        echo json_encode(['error' => 'A resposta do controlador é um XML inválido.']);
        exit;
    }
}

// Se, depois de tudo, não conseguimos obter dados, devolvemos um erro.
if (empty($data)) {
    echo json_encode(['error' => 'O formato da resposta do controlador não foi reconhecido (nem JSON, nem XML válido).']);
    exit;
}

// Devolve os dados já em formato JSON
echo json_encode($data);
?>