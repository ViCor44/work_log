<?php
session_start();
include 'db.php'; // Inclui a conexão com o banco de dados
require_once('PHPMailer/PHPMailerAutoload.php');

/**
 * Escreve uma mensagem no ficheiro de log de logins.
 * @param string $message A mensagem a ser registada.
 */
function log_login_attempt($message) {
    $log_file = 'logs/login_log.txt'; // O nome do seu ficheiro de log
    $timestamp = date('Y-m-d H:i:s');
    // Formato da entrada: [DATA HORA] MENSAGEM
    $log_entry = "[" . $timestamp . "] " . $message . "\n";
    // FILE_APPEND garante que a nova entrada é adicionada no final do ficheiro
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // ALTERAÇÃO: A query agora busca também os novos campos de sessão
   // Modificação: Adiciona a verificação do campo 'is_approved = 1'
	// Linha 31: A query continua a buscar o utilizador sem a verificação de aprovação
	$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
	$stmt->bind_param("s", $username);
	$stmt->execute();
	$result = $stmt->get_result();
	
	if ($result->num_rows > 0) {
	    $user = $result->fetch_assoc();
	    
	    // ======================================================
	    // == NOVA VERIFICAÇÃO DE APROVAÇÃO DO ADMINISTRADOR ==
	    // ======================================================
	    if ($user['accepted'] != 1) { // Supondo que 'is_approved' seja 1 para aprovado
	        $error_message = "A sua conta ainda não foi aprovada por um administrador. Por favor, aguarde.";
	        $log_message = "Tentativa de login bloqueada. Conta não aprovada para o utilizador: '" . htmlspecialchars($username) . "'. IP: " . $_SERVER['REMOTE_ADDR'];
	        log_login_attempt($log_message);
	        goto end_login_logic; // Salta para o fim do bloco de login
	
	    }

        if (password_verify($password, $user['password'])) {
            
            // ======================================================
            // == NOVA LÓGICA DE VERIFICAÇÃO DE SESSÃO ATIVA ==
            // ======================================================
            $current_ip = $_SERVER['REMOTE_ADDR'];
			
			 // ======================================================
            // == NOVA LÓGICA DE ALERTA POR EMAIL ==
            // ======================================================
           

            // Compara o IP atual com o último IP guardado
            if (!empty($user['last_login_ip']) && $user['last_login_ip'] != $current_ip) {
                // Se forem diferentes, envia o email de alerta
                send_security_alert_email($user['email'], $user['first_name'], $current_ip);
				$log_message = "Tentativa de login bloqueada (sessão ativa noutro IP) para o utilizador: '" . htmlspecialchars($username) . "'. IP: " . $current_ip;
                log_login_attempt($log_message);
            } else {
                // Log de login bem-sucedido
                $log_message = "Login bem-sucedido para o utilizador: '" . htmlspecialchars($username) . "'. IP: " . $current_ip;
                log_login_attempt($log_message);
			}

            // Verifica se já existe uma sessão ativa noutro IP
            if (!empty($user['session_id']) && $user['last_login_ip'] != $current_ip) {
                // Se sim, mostra uma mensagem de erro e não permite o login
                $error_message = "A sua conta já tem uma sessão ativa noutro equipamento. Por favor, termine a outra sessão primeiro.";
            } else {
                // Se não houver sessão ativa ou for do mesmo IP, permite o login

                // Inicia a sessão e armazena informações do utilizador
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_type'] = $user['user_type'];
                
                // Guarda o novo ID da sessão e o IP na base de dados
                $new_session_id = session_id();
                $stmt_update = $conn->prepare("UPDATE users SET session_id = ?, last_login_ip = ? WHERE id = ?");
                $stmt_update->bind_param("ssi", $new_session_id, $current_ip, $user['id']);
                $stmt_update->execute();
                $stmt_update->close();
                
                // Redireciona para a página inicial
                header("Location: redirect_page.php");
                exit;
            }
            // ======================================================

        } else {
            $error_message = "Password incorreta.";
			$log_message = "Tentativa de login falhada. Password incorreta para o utilizador: '" . htmlspecialchars($username) . "'. IP: " . $current_ip;
            log_login_attempt($log_message);
        }
    } else {
        $current_ip = $_SERVER['REMOTE_ADDR'];		
        $error_message = "Utilizador não encontrado.";
		$log_message = "Tentativa de login falhada. Utilizador não encontrado: '" . htmlspecialchars($username) . "'. IP: " . $current_ip;
        log_login_attempt($log_message);
		end_login_logic:
    }
}

function send_security_alert_email($user_email, $user_name, $login_ip) {

    $mail = new PHPMailer();
    $mail->SMTPDebug = 0; // Desligue para produção, ou 2 para depurar
    $mail->Debugoutput = 'html';
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPSecure = 'tls';
    $mail->SMTPAuth = true;
    $mail->Username = 'slide.rocketchat@gmail.com';
    $mail->Password = 'jbbo gsys gvmq bise'; // Sua senha de aplicação
    $mail->setFrom('slide.rocketchat@gmail.com', 'WorkLog');
    $mail->addAddress($user_email);
    $mail->isHTML(true);
    $mail->Subject ="WorkLog CMMS - Alerta de Segurança: Novo Acesso à sua Conta";
    $mail->Body    = "<html>
        <head><title>WorkLog CMMS - Alerta de Segurança: Novo Acesso à sua Conta</title></head>
        <body>
            <p>Olá, " . htmlspecialchars($user_name) . ",</p>
            <p>Detetámos um novo acesso à sua conta WorkLog CMMS a partir de um novo dispositivo ou localização.</p>
            <p><strong>Detalhes do Acesso:</strong></p>
            <ul>
                <li><strong>Data e Hora:</strong> " . date('d/m/Y H:i:s') . "</li>
                <li><strong>Endereço IP:</strong> " . htmlspecialchars($login_ip) . "</li>
            </ul>
            <p>Se foi você, pode ignorar este e-mail. A sua conta está segura.</p>
            <p>Se não reconhece esta atividade, por favor, altere a sua password imediatamente e contacte um administrador.</p>
            <br>
            <p>Obrigado,<br>A Equipa WorkLog CMMS</p>
        </body>
        </html>
    ";
	$mail->send();

}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WorkLog CMMS</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/work_log/css/all.min.css">
    <style>
        body, html {
            height: 100%;
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: url('images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp') no-repeat center center fixed;
            background-size: cover;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
        }
        .login-card {
            background-color: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header img {
            max-width: 180px;
            margin-bottom: 1rem;
        }
        .login-header h1 {
            font-weight: 700;
            color: #343a40;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="login-header">
        
        <h1>WorkLog CMMS</h1>
    </div>
    <div class="card login-card">
        <div class="card-body p-4">
            <h5 class="card-title text-center mb-4">Aceda à sua conta</h5>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Nome de Utilizador</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-primary btn-lg">Login</button>
                </div>
				<p class="mt-3 text-center">Ainda não tem conta? <a href="register.php">Registre-se</a></p>
                <div class="text-center">
                    <a href="forgot_password.php">Esqueceu-se da password?</a>
                </div>
            </form>
        </div>
    </div>
    <div class="text-center mt-4">
        <a href="about.php" class="btn btn-outline-dark">
            <i class="fas fa-info-circle me-2"></i> Sobre o WorkLog CMMS
        </a>
    </div>
</div>

<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>