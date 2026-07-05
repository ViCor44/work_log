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
//
// >>> SEGREDOS (MODEM_PASS, etc.) devem ficar em config.local.php,
//     que é ignorado pelo git. Ver config.local.example.php.
define('SMS_ENABLED',       true);            // desligar para desativar todos os envios
define('MODEM_SCHEME',      'https');         // http ou https
define('MODEM_HOST',        '192.168.63.253:8443');   // IP[:porta] do TRB145
define('MODEM_USER',        'admin');         // utilizador da API
define('MODEM_ID',          '3-1');           // ID do modem interno (ver /api/messages/config em RutOS)
define('MODEM_TIMEOUT',     8);               // segundos
define('MODEM_VERIFY_SSL',  false);           // false se usar https com certificado self-signed

// Debounce por tipo de alarme (minutos) — evita spam de SMS
// se o alarme oscilar rapidamente. Coloca 0 para desativar.
define('SMS_DEBOUNCE_MINUTES', 15);

// Tempo mínimo (minutos) que um alarme de CONTROLADOR tem de estar
// continuamente ativo antes de dispararmos SMS. Alarmes que se
// resolvam sozinhos antes deste tempo não geram SMS (nem [ALARME] nem [OK]).
// Não se aplica a alarmes LoRa (esses já têm 10 min de tolerância).
define('SMS_ALARM_MIN_MINUTES', 17);

// Limites químicos (SMS quando um valor sai destes intervalos e
// também quando regressa aos valores normais).
define('LIMIT_CLORO_MIN', 1.0);
define('LIMIT_CLORO_MAX', 3.0);
define('LIMIT_PH_MIN',    7.0);
define('LIMIT_PH_MAX',    7.8);

// Ficheiro onde é guardado o token em cache (evita login em cada envio)
define('MODEM_TOKEN_FILE', __DIR__ . '/sessions/modem_token.json');

// ------------------------------------------------------
// Overrides locais (credenciais) — não versionado.
// Copiar config.local.example.php para config.local.php e preencher.
// ------------------------------------------------------
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Fallback: variável de ambiente (ex.: Apache SetEnv MODEM_PASS ...)
// e, se não existir, fica vazia.
if (!defined('MODEM_PASS')) {
    define('MODEM_PASS', getenv('MODEM_PASS') ?: '');
}
?>