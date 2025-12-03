<?php
// ping_controller.php - Versão 2.0 (Comandos e Timeout Ajustados)

header('Content-Type: application/json');

$ip = $_GET['ip'] ?? null;

if (empty($ip)) {
    http_response_code(400); 
    echo json_encode(['status' => 'error', 'message' => 'IP parameter missing.']);
    exit;
}

$ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

if (!$ip) {
    http_response_code(400); 
    echo json_encode(['status' => 'error', 'message' => 'Invalid IP address format.']);
    exit;
}

// Aumentamos o tempo limite para 2 segundos para dar mais folga à rede
$timeout = 2; 
$count = 1;

// 1. Tentar Linux (Comando padrão: -c <count> -W <timeout em segundos>)
$command = "ping -c {$count} -W {$timeout} " . escapeshellarg($ip);
exec($command, $output, $return_var);

// 2. Se falhar, tentar Windows (Comando alternativo: -n <count> -w <timeout em milissegundos>)
if ($return_var !== 0) {
    $timeout_ms = $timeout * 1000; // 2000 ms
    $command = "ping -n {$count} -w {$timeout_ms} " . escapeshellarg($ip);
    exec($command, $output, $return_var);
}

// 3. Tentar BusyBox / Outros ambientes UNIX simplificados (timeout por -i)
// Se os anteriores falharem, podemos tentar outras variantes...

// 4. Retornar o Status
if ($return_var === 0) {
    echo json_encode(['status' => 'online']);
} else {
    // Se o código de retorno for diferente de zero, o ping falhou em ambos os comandos
    echo json_encode(['status' => 'offline']);
}
?>