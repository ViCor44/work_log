<?php
// --- CONFIGURAÇÃO AVANÇADA DA SESSÃO ---


// Define o fuso horário para Portugal no início de tudo
date_default_timezone_set('Europe/Lisbon');

// --- Fim da Configuração ---


// Agora, inicia a sessão (se ainda não tiver sido iniciada)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclui a conexão à base de dados de forma segura
require_once __DIR__ . '/db.php';

// ======================================================
// == VERIFICAÇÃO DE LICENÇA ==
// ======================================================
$pagina_atual = basename($_SERVER['PHP_SELF']);
$paginas_permitidas = ['login.php', 'logout.php', 'licenca.php', 'guardar_licenca.php'];

if (!in_array($pagina_atual, $paginas_permitidas)) {
    $licenca_stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'license_key'");
    $licenca_db = $licenca_stmt->fetch_assoc();
    $licenca_valida = false;

    if ($licenca_db && !empty($licenca_db['setting_value'])) {
        $secret_key = 'MinhaChaveSuperSecreta@2025!'; // A MESMA CHAVE SECRETA
        $dados_licenca = base64_decode($licenca_db['setting_value']);
        $iv = substr($dados_licenca, 0, 16);
        $dados_encriptados = substr($dados_licenca, 16);
        $data_validade_str = openssl_decrypt($dados_encriptados, 'aes-256-cbc', $secret_key, 0, $iv);

        if ($data_validade_str) {
            $data_validade = new DateTime($data_validade_str);
            $hoje = new DateTime();
            if ($hoje <= $data_validade) {
                $licenca_valida = true;
            }
        }
    }

    if (!$licenca_valida) {
        $_SESSION['error_message'] = "A sua licença de software é inválida ou expirou. Por favor, insira uma nova chave.";
        header("Location: /work_log/admin/licenca.php");
        exit;
    }
}

// Verificação de login (opcional, pois o header.php já faz)
// --- LÓGICA DE DADOS DO UTILIZADOR E NOTIFICAÇÕES ---
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];

    // Vai buscar o nome do utilizador se ainda não estiver na sessão
    if (!isset($_SESSION['user_name'])) {
        $stmt_user = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        if ($stmt_user) {
            $stmt_user->bind_param("i", $user_id);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            if ($user_data = $result_user->fetch_assoc()) {
                $_SESSION['user_name'] = $user_data['first_name'] . ' ' . $user_data['last_name'];
            }
            $stmt_user->close();
        }
    }

    // ======================================================
    // == LÓGICA DE CONTAGEM DE NOTIFICAÇÕES MOVIDA PARA AQUI ==
    // ======================================================
    // Contar mensagens não lidas
    $unread_messages_count = 0;
    $stmt_msg = $conn->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    if ($stmt_msg) {
        $stmt_msg->bind_param("i", $user_id);
        $stmt_msg->execute();
        $stmt_msg->bind_result($unread_messages_count);
        $stmt_msg->fetch();
        $stmt_msg->close();
    }

    // Contar OTs por aceitar
    $unaccepted_ot_count = 0;
    $stmt_ot = $conn->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_user = ? AND accept_at IS NULL");
    if ($stmt_ot) {
        $stmt_ot->bind_param("i", $user_id);
        $stmt_ot->execute();
        $stmt_ot->bind_result($unaccepted_ot_count);
        $stmt_ot->fetch();
        $stmt_ot->close();
    }
}

function log_action($conn, $user_id, $action, $description) {
    $stmt = $conn->prepare("INSERT INTO logs (user_id, action, description) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $action, $description);
        $stmt->execute();
        $stmt->close();
    }
}

// ======================================================
// == LÓGICA DE CONTROLO DE ACESSO PARA O PERFIL "VISUALIZADOR" ==
// ======================================================

// Verifica se o utilizador está logado e se o seu tipo é 'viewer'
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'viewer') {
    
    // Lista de páginas que um 'viewer' PODE aceder
    $allowed_pages_for_viewer = [
        'dashboard.php',          // O dashboard principal
        'view_pool_details.php',  // A página de detalhes de uma piscina
        'get_controller_data.php',// A API para os dados em tempo real
        'get_lorawan_status.php', // A API para os dados LoRaWAN
        'get_pool_history.php',   // A API para os gráficos de histórico
        'logout.php',             // A página para terminar a sessão
        'profile_modal.php'       // O modal do perfil (se tiver um ficheiro separado)
    ];

    // Obtém o nome do ficheiro da página atual
    $current_page = basename($_SERVER['PHP_SELF']);

    // Se a página atual NÃO ESTIVER na lista de páginas permitidas, redireciona para o dashboard
    if (!in_array($current_page, $allowed_pages_for_viewer)) {
        header("Location: /work_log/pools/dashboard.php");
        exit;
    }
}

?>