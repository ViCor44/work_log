<?php
// Este script corre automaticamente no servidor
require_once dirname(__DIR__) . '/db.php';
date_default_timezone_set('Europe/Lisbon');
// Define o tempo limite em minutos. Se um dispositivo não enviar dados
// por mais de 15 minutos, será considerado offline.
$timeout_minutes = 10;
$time_limit = date('Y-m-d H:i:s', strtotime("-$timeout_minutes minutes"));

// Query que marca como 'Off' todos os dispositivos cujo 'last_seen' é mais antigo que o tempo limite
$conn->query("
    UPDATE lorawan_devices 
    SET status = 'Off' 
    WHERE status = 'On' AND last_seen < '$time_limit'
");

$conn->close();
echo "Verificação de estado dos dispositivos LoRaWAN concluída.";
?>