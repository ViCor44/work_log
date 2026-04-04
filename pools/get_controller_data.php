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

// ------------------------------------------------------------------
// Camada de cache + lock para evitar colisões com o ciclo do dispositivo
// ------------------------------------------------------------------
$cache_dir  = __DIR__ . '/cache/';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

$cache_key        = md5($ip);
$cache_file       = $cache_dir . 'ctrl_' . $cache_key . '.json';
$lock_file        = $cache_dir . 'ctrl_' . $cache_key . '.lock';
$cache_ttl        = 8;  // segundos — ligeiramente abaixo do intervalo de polling (10 s)
$cache_fallback   = 20; // segundos — máximo para servir cache em caso de contention
$lock_max_age     = 10; // segundos — age máxima de um lock antes de ser considerado órfão

// 1. Cache fresco → responde imediatamente, sem tocar no controlador
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    readfile($cache_file);
    exit;
}

// 2. Limpa lock órfão (processo anterior crashou sem libertar)
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > $lock_max_age) {
    @unlink($lock_file);
}

// 3. Tenta adquirir lock exclusivo não-bloqueante
$lock_fp = fopen($lock_file, 'w');
if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
    // Outro processo já está a ir ao controlador
    fclose($lock_fp);
    // Serve cache antigo apenas se for suficientemente recente (evita dados obsoletos em falha real)
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_fallback) {
        readfile($cache_file);
    } else {
        echo json_encode(['error' => 'Timeout ou erro ao contactar controlador: '.$ip]);
    }
    exit;
}

// 4. Double-check: outro processo pode ter acabado de actualizar o cache
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    readfile($cache_file);
    exit;
}
// ------------------------------------------------------------------

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
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    echo json_encode(['error' => 'Timeout ou erro ao contactar controlador: '.$ip]);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
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

            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
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

    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    echo json_encode([
        'error' => 'Formato de resposta desconhecido',
        'raw' => substr($response_text,0,100)
    ]);

    exit;
}

// Guarda no cache e liberta o lock
$json_output = json_encode($data);
file_put_contents($cache_file, $json_output);
flock($lock_fp, LOCK_UN);
fclose($lock_fp);

echo $json_output;