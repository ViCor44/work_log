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
$current_ip = $_SERVER['REMOTE_ADDR'];
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
        body {
            background: linear-gradient(to bottom, #2E4057, #1E2A44);
            height: 100vh;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
            margin: 0;
        }

        .container {
            display: flex;
            flex-direction: row;
            height: 100vh;
        }

        .info-section {
            width: 40%;
            background: linear-gradient(to bottom, #2E4057, #1E2A44);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 4rem;
            position: relative;
        }

        .info-section::after {
            content: '';
            position: absolute;
            top: 0;
            right: -50px;
            width: 100px;
            height: 100%;
            background: linear-gradient(to right, #1E2A44, transparent);
            z-index: 1;
        }

        .logo {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: #B794F4;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.5rem;
            color: #553C9A;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .logo-subtext {
            font-size: 0.875rem;
            opacity: 0.8;
        }

        .info-section h1 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .info-section p {
            font-size: 1rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .sobre-link {
            color: #4299E1;
            text-decoration: none;
            font-weight: 500;
        }

        .form-section {
            width: 60%;
            background: white;
            display: flex;
            justify-content: center;
            align-items: center;
            border-top-left-radius: 50px;
            border-bottom-left-radius: 50px;
            position: relative;
            z-index: 2;
        }

        .form-container {
            max-width: 400px;
            width: 100%;
            padding: 2rem;
        }

        .form-container h2 {
            font-size: 1.5rem;
            margin-bottom: 2rem;
            text-align: left;
        }

        .input-group {
            margin-bottom: 1.5rem;
        }

        .input-group label {
            display: block;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            color: #4A5568;
        }

        .input-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #E2E8F0;
            border-radius: 0.375rem;
            background: #F7FAFC;
        }

        button {
            width: 100%;
            padding: 0.75rem;
            background: #4299E1;
            color: white;
            border: none;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
        }

        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            font-size: 0.875rem;
        }

        .links a {
            color: #4299E1;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .info-section {
                width: 100%;
                height: 40%;
                border-bottom-right-radius: 50px;
                border-bottom-left-radius: 50px;
                padding: 2rem;
                text-align: center;
            }

            .info-section::after {
                display: none;
            }

            .form-section {
                width: 100%;
                height: 60%;
                border-top-left-radius: 50px;
                border-top-right-radius: 50px;
            }

            .logo {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Lado Esquerdo: Informações -->
        <div class="info-section">
            <div class="logo">
                <div class="logo-icon">W
                <!-- Placeholder para ícone; substitua por SVG ou imagem real se disponível -->
                </div>
                <div>
                    <div class="logo-text">WorkLog</div>
                    <div class="logo-subtext">CMMS</div>
                </div>
            </div>
            <h1>Bem-vindo ao WorkLog CMMS</h1>
            <p>Aceda ao dashboard para aceder às funcionalidades do sistema.</p>
            <a href="about.php" class="sobre-link">Sobre</a>
        </div>

        <!-- Lado Direito: Formulário de Login -->
        <div class="form-section">
            <div class="form-container">
                <h2>Login</h2>

                <?php if (isset($error_message)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-md relative mb-6" role="alert">
                        <?= htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST">
                    <div class="input-group">
                        <label for="username">Nome de Utilizador</label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            required>
                    </div>
                    <div class="input-group">
                        <label for="password">Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required>
                    </div>
                    <button type="submit">Entrar</button>
                    <div class="links">
                        <span>Não tem conta? <a href="register.php">Registe-se</a></span>
                        <a href="forgot_password.php">Esqueci-me da password</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>