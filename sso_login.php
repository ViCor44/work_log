<?php
/**
 * ============================================================
 *  SSO Login Handler — Super Login Integration
 * ============================================================
 *
 * Como usar:
 *   1. Copia este ficheiro para a pasta raiz do sistema externo.
 *      Ex: /economato/sso_login.php
 *
 *   2. Edita APENAS as duas linhas no bloco "CONFIGURAÇÃO":
 *        - SSO_SYSTEM_KEY  → chave do sistema em systems_map.php
 *        - SSO_MAP_PATH    → caminho absoluto para systems_map.php
 *
 *   3. Garante que 'login_url' em systems_map.php aponta para
 *      este ficheiro e que o sistema tem 'id_field' e 'redirect_ok'.
 *
 * ─── CONFIGURAÇÃO (único bloco a editar) ─────────────────────
 */

// Chave deste sistema tal como está definida em systems_map.php
define('SSO_SYSTEM_KEY', 'worklog');

// Caminho absoluto para o ficheiro systems_map.php do Super Login.
// Normalmente basta mudar 'super_login' se a pasta tiver outro nome.
define('SSO_MAP_PATH', $_SERVER['DOCUMENT_ROOT'] . '/super_login/systems_map.php');

/**
 * ─── HANDLER (não editar abaixo desta linha) ─────────────────
 */

// ── 0. Carregar configuração do mapa central ──────────────────
if (!file_exists(SSO_MAP_PATH)) {
    http_response_code(500);
    error_log('[SSO] systems_map.php não encontrado em: ' . SSO_MAP_PATH);
    exit('Erro de configuração SSO.');
}

$map    = require SSO_MAP_PATH;
$cfg    = $map['_config']           ?? null;
$system = $map[SSO_SYSTEM_KEY]      ?? null;

if (!$cfg || !$system) {
    http_response_code(500);
    error_log('[SSO] Chave "' . SSO_SYSTEM_KEY . '" ou "_config" não existe em systems_map.php.');
    exit('Erro de configuração SSO.');
}

$redirectError = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/super_login/login.php';

// Se o sistema já tem session_start() próprio, remove esta linha.
session_start();

// ── 1. Validação básica do token ──────────────────────────────
$token = trim($_GET['token'] ?? '');

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    header('Location: ' . $redirectError . '?error=sso_invalid_token');
    exit;
}

try {
    // ── 2. Ligar à BD do Super Login ─────────────────────────
    $pdoSL = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // ── 3. Validar token (único uso, não expirado, sistema correto) ──
    $stmt = $pdoSL->prepare("
        SELECT t.admin_id, m.user_id
          FROM admin_tokens t
          JOIN admin_user_map m
            ON  m.admin_id   = t.admin_id
            AND m.system_key = t.system_key
         WHERE t.token      = ?
           AND t.system_key = ?
           AND t.used       = 0
           AND t.expires_at > NOW()
         LIMIT 1
    ");
    $stmt->execute([$token, SSO_SYSTEM_KEY]);
    $row = $stmt->fetch();

    if (!$row) {
        header('Location: ' . $redirectError . '?error=sso_token_expired');
        exit;
    }

    // ── 4. Marcar token como usado (impede reutilização) ─────
    $pdoSL->prepare("UPDATE admin_tokens SET used = 1 WHERE token = ?")
           ->execute([$token]);

    $userId = (int) $row['user_id'];

    // ── 5. Buscar dados do utilizador na BD local ─────────────
    $pdoLocal = new PDO($system['dsn'], $system['db_user'], $system['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $idField = $system['id_field'] ?? 'id';

    $stmt = $pdoLocal->prepare(
        "SELECT * FROM {$system['users_table']} WHERE {$idField} = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: ' . $redirectError . '?error=sso_user_not_found');
        exit;
    }

    // ── 6. Iniciar sessão local ───────────────────────────────
    session_regenerate_id(true);

    // Variáveis genéricas — a maioria dos sistemas usa estas.
    // Se o teu sistema precisar de nomes diferentes, acrescenta linhas aqui.
    // Ex: $_SESSION['loggedin'] = true; ou $_SESSION['username'] = $user['username'];
    $_SESSION['user_id'] = $user[$idField];
    $_SESSION['user']    = $user;

    header('Location: ' . $system['redirect_ok']);
    exit;

} catch (PDOException $e) {
    error_log('[SSO] Erro BD (' . SSO_SYSTEM_KEY . '): ' . $e->getMessage());
    header('Location: ' . $redirectError . '?error=sso_db_error');
    exit;
}

// 2️⃣ Marcar token como usado
$pdoSuper->prepare("
    UPDATE admin_tokens SET used = 1 WHERE token = ?
")->execute([$token]);

// 3️⃣ Mapear admin → user do WorkLog
$stmt = $pdoSuper->prepare("
    SELECT user_id
    FROM admin_user_map
    WHERE admin_id = ? AND system_key = ?
");
$stmt->execute([$t['admin_id'], $t['system_key']]);
$map = $stmt->fetch();

if (!$map) {
    exit('Admin não mapeado no WorkLog.');
}

// 4️⃣ Buscar utilizador local no WorkLog
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ? AND accepted = 1
    LIMIT 1
");
$stmt->bind_param("i", $map['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: login.php');
    exit;
}

$user = $result->fetch_assoc();

// 5️⃣ Criar sessão (IGUAL ao login normal)
session_regenerate_id(true);

$_SESSION['user_id']    = $user['id'];
$_SESSION['username']   = $user['username'];
$_SESSION['first_name'] = $user['first_name'];
$_SESSION['last_name']  = $user['last_name'];
$_SESSION['user_type']  = $user['user_type']; // admin | user

// 6❣ Redirecionar para login_process.php (trata o routing por role)
header('Location: login_process.php');
exit;
