<?php
/**
 * Cliente HTTP para envio de SMS através de um modem Teltonika (RutOS 7+).
 * Testado com TRB145.
 *
 * Fluxo:
 *  1. Faz POST /api/login com utilizador/password → recebe token JWT.
 *  2. Guarda o token num ficheiro JSON (expira em ~299 s).
 *  3. Envia SMS com POST /api/messages/actions/send + Authorization: Bearer.
 *  4. Se o modem devolver 401 (token expirado antes do esperado), refaz login uma vez.
 *
 * Uso:
 *   require_once __DIR__ . '/sms_client.php';
 *   $client = new TeltonikaSmsClient();
 *   $res = $client->send('+351912345678', 'Mensagem');
 *   if (!$res['ok']) { error_log($res['error']); }
 */

require_once dirname(__DIR__) . '/config.php';

class TeltonikaSmsClient
{
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $user;
    /** @var string */
    private $pass;
    /** @var int */
    private $timeout;
    /** @var bool */
    private $verifySsl;
    /** @var string */
    private $tokenFile;
    /** @var int  Margem de segurança antes da expiração real (segundos). */
    private $safetyWindow = 20;

    public function __construct()
    {
        $scheme = defined('MODEM_SCHEME') ? MODEM_SCHEME : 'http';
        $host   = defined('MODEM_HOST')   ? MODEM_HOST   : '192.168.2.1';

        $this->baseUrl   = rtrim($scheme . '://' . $host, '/');
        $this->user      = defined('MODEM_USER')       ? MODEM_USER       : 'admin';
        $this->pass      = defined('MODEM_PASS')       ? MODEM_PASS       : '';
        $this->timeout   = defined('MODEM_TIMEOUT')    ? (int)MODEM_TIMEOUT : 8;
        $this->verifySsl = defined('MODEM_VERIFY_SSL') ? (bool)MODEM_VERIFY_SSL : false;
        $this->tokenFile = defined('MODEM_TOKEN_FILE') ? MODEM_TOKEN_FILE  : (dirname(__DIR__) . '/sessions/modem_token.json');
    }

    /**
     * Envia um SMS. Devolve ['ok'=>bool, 'http_code'=>int, 'response'=>string|array, 'error'=>string].
     */
    public function send(string $number, string $message): array
    {
        if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'SMS desativado (SMS_ENABLED=false)'];
        }
        if ($this->pass === '') {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'MODEM_PASS não configurada em config.php'];
        }

        $number = $this->normalizeNumber($number);
        if ($number === '') {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'Número de destino vazio ou inválido'];
        }

        $token = $this->getToken();
        if ($token === null) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'Falha a obter token do modem'];
        }

        $result = $this->doSend($number, $message, $token);

        // Se recebeu 401/403 → token pode ter expirado antes do esperado. Força refresh e tenta 1x.
        if (!$result['ok'] && in_array($result['http_code'], [401, 403], true)) {
            @unlink($this->tokenFile);
            $token = $this->getToken();
            if ($token !== null) {
                $result = $this->doSend($number, $message, $token);
            }
        }

        return $result;
    }

    /**
     * Devolve informação (não sensível) sobre o estado do token em cache.
     */
    public function getTokenStatus(): array
    {
        $info = @file_get_contents($this->tokenFile);
        if ($info === false) {
            return ['cached' => false];
        }
        $data = json_decode($info, true);
        if (!is_array($data) || !isset($data['expires_at'])) {
            return ['cached' => false];
        }
        return [
            'cached'         => true,
            'expires_at'     => (int)$data['expires_at'],
            'expires_in_sec' => (int)$data['expires_at'] - time(),
        ];
    }

    // ---------- Internos ----------

    private function normalizeNumber(string $n): string
    {
        $n = trim($n);
        // Remove espaços internos e caracteres não-numéricos (exceto '+' inicial).
        $hasPlus = (strlen($n) > 0 && $n[0] === '+');
        $digits  = preg_replace('/\D+/', '', $n);
        if ($digits === '') {
            return '';
        }
        return ($hasPlus ? '+' : '') . $digits;
    }

    private function getToken(): ?string
    {
        // 1. Tenta cache válido.
        $cached = @file_get_contents($this->tokenFile);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data)
                && isset($data['token'], $data['expires_at'])
                && (int)$data['expires_at'] > (time() + $this->safetyWindow)) {
                return (string)$data['token'];
            }
        }
        // 2. Faz novo login.
        return $this->login();
    }

    private function login(): ?string
    {
        $url = $this->baseUrl . '/api/login';
        $payload = json_encode(['username' => $this->user, 'password' => $this->pass]);

        $res = $this->httpRequest('POST', $url, $payload, null);
        if (!$res['ok']) {
            return null;
        }

        $body = $res['response'];
        // RutOS 7 devolve: { "success": true, "data": { "token": "...", "expires": 299 } }
        // Alguns firmwares devolvem 'expires' em segundos, outros omitem-no.
        $token   = null;
        $expires = 299;
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) {
                if (isset($body['data']['token']))   { $token   = (string)$body['data']['token']; }
                if (isset($body['data']['expires'])) { $expires = (int)$body['data']['expires']; }
            }
            if ($token === null && isset($body['token'])) { $token = (string)$body['token']; }
        }
        if ($token === null || $token === '') {
            return null;
        }

        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents(
            $this->tokenFile,
            json_encode(['token' => $token, 'expires_at' => time() + max(60, $expires)]),
            LOCK_EX
        );
        @chmod($this->tokenFile, 0640);

        return $token;
    }

    private function doSend(string $number, string $message, string $token): array
    {
        $url = $this->baseUrl . '/api/messages/actions/send';
        $payload = json_encode(['data' => ['number' => $number, 'message' => $message]]);

        return $this->httpRequest('POST', $url, $payload, $token);
    }

    /**
     * Executa um pedido HTTP JSON. Devolve
     *   ['ok'=>bool,'http_code'=>int,'response'=>mixed,'error'=>string]
     * response é decodificado como array associativo quando possível.
     */
    private function httpRequest(string $method, string $url, ?string $body, ?string $token): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(4, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw       = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'http_code' => 0, 'response' => null, 'error' => 'cURL: ' . $curlError];
        }

        $decoded = json_decode((string)$raw, true);
        $parsed  = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;

        // Sucesso considerado quando HTTP 2xx e (se JSON) success != false
        $ok = ($httpCode >= 200 && $httpCode < 300);
        if ($ok && is_array($parsed) && array_key_exists('success', $parsed) && $parsed['success'] === false) {
            $ok = false;
        }

        $error = '';
        if (!$ok) {
            if (is_array($parsed)) {
                $error = 'HTTP ' . $httpCode . ' — ' . json_encode($parsed);
            } else {
                $error = 'HTTP ' . $httpCode . ' — ' . substr((string)$parsed, 0, 400);
            }
        }

        return ['ok' => $ok, 'http_code' => $httpCode, 'response' => $parsed, 'error' => $error];
    }
}
