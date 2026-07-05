<?php
// Ficheiro: config.php

// Define a URL base da sua aplicação. 
// Altere '/work_log/' se o nome da sua pasta for diferente.
// A barra no final é importante!
define('BASE_URL', '/work_log/');

// ======================================================
// == CONFIGURAÇÃO DO MODEM TELTONIKA (SMS) ==
// ======================================================
// Modem TRB145 acessível na rede local via REST API (RutOS).
// Token de autenticação tem ~299 s de validade — o cliente
// faz cache e refresh automático.
define('SMS_ENABLED',       true);            // desligar para desativar todos os envios
define('MODEM_SCHEME',      'http');          // http ou https
define('MODEM_HOST',        '192.168.63.253:8443');   // IP/host do TRB145
define('MODEM_USER',        'admin');         // utilizador da API
define('MODEM_PASS',        getenv('MODEM_PASS') ?: ''); // Password via variável de ambiente
define('MODEM_TIMEOUT',     8);               // segundos
define('MODEM_VERIFY_SSL',  false);           // false se usar https com certificado self-signed

// Debounce por tipo de alarme (minutos) — evita spam de SMS
// se o alarme oscilar rapidamente. Coloca 0 para desativar.
define('SMS_DEBOUNCE_MINUTES', 15);

// Ficheiro onde é guardado o token em cache (evita login em cada envio)
define('MODEM_TOKEN_FILE', __DIR__ . '/sessions/modem_token.json');
?>