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

$url = "http://" . $ip;

if (strpos($ip, '192.') !== 0) {
    $url .= "/ajax_inputs";
}

if (strpos($ip, '191.') !== 0) {
    $url .= "/ajax_inputs";
}


$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,   // ligação
    CURLOPT_TIMEOUT => 4,          // timeout total
    CURLOPT_NOSIGNAL => 1,
    CURLOPT_FAILONERROR => false
]);

$response_text = curl_exec($ch);

if ($response_text === false) {
    curl_close($ch);
    echo json_encode(['error' => 'Timeout ou erro ao contactar controlador: '.$ip]);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['error' => 'Resposta HTTP inválida do controlador: '.$ip]);
    exit;
}

/*
----------------------------------
Tenta interpretar JSON primeiro
----------------------------------
*/

$data = json_decode($response_text, true);

/*
----------------------------------
Se não for JSON tenta XML
----------------------------------
*/

if (json_last_error() !== JSON_ERROR_NONE) {

    if (strpos(trim($response_text), '<?xml') === 0) {

        try {

            $xml = new SimpleXMLElement($response_text);

            $json_string = json_encode($xml);
            $data = json_decode($json_string, true);

        } catch (Exception $e) {

            echo json_encode(['error' => 'XML inválido recebido do controlador']);
            exit;
        }

    }

}

/*
----------------------------------
Validação final
----------------------------------
*/

if (empty($data)) {

    echo json_encode([
        'error' => 'Formato de resposta desconhecido',
        'raw' => substr($response_text,0,100)
    ]);

    exit;
}

echo json_encode($data);