<?php
require_once 'config.php';
require_once 'core.php';

// Garante que as colunas de preferências SMS existem (idempotente).
if (isset($conn) && $conn instanceof mysqli) {
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_alarms TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_controller TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_chemical TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_lora_offline TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_equipment_off TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_perlite TINYINT(1) NOT NULL DEFAULT 1");
    @$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_alarm_min_minutes INT NOT NULL DEFAULT 17");
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $user_id = $_SESSION['user_id']; // ID do usuário logado
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $security_question = $_POST['security_question'];
    $security_answer = $_POST['security_answer'];

	// Salvar a assinatura se enviada
	if (!empty($_POST['signature_data'])) {
	    $data = $_POST['signature_data'];
	    $data = str_replace('data:image/png;base64,', '', $data);
	    $data = base64_decode($data);
	    $file_path = 'signatures/signature_user_' . $user_id . '.png';
	    file_put_contents($file_path, $data);
	
	    // Atualiza o caminho da assinatura na BD
	    $stmt_sig = $conn->prepare("UPDATE users SET signature_path = ? WHERE id = ?");
	    $stmt_sig->bind_param("si", $file_path, $user_id);
	    $stmt_sig->execute();
	    $stmt_sig->close();
	}

    // Atualiza os dados pessoais (NÃO toca em preferências SMS — essas têm o seu
    // próprio botão de submissão para evitar desligar receção de SMS por engano).
    $stmt = $conn->prepare("UPDATE users
        SET first_name = ?, last_name = ?, email = ?, phone = ?,
            security_question = ?, security_answer = ?
        WHERE id = ?");
    if (!$stmt) {
        die("Erro na consulta: " . $conn->error);
    }
    $stmt->bind_param(
        "ssssssi",
        $first_name,
        $last_name,
        $email,
        $phone,
        $security_question,
        $security_answer,
        $user_id
    );

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Perfil atualizado com sucesso!";
    } else {
        $_SESSION['error_message'] = "Erro ao atualizar o perfil: " . $stmt->error;
    }
    $stmt->close();

	header('Content-Type: text/html; charset=utf-8');
    // Redireciona de volta para a página principal após atualização
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Submissão dedicada só das preferências SMS (botão próprio no modal).
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_sms_prefs'])) {
    $user_id = $_SESSION['user_id'];

    $receive_sms_alarms        = isset($_POST['receive_sms_alarms']) ? 1 : 0;
    $receive_sms_controller    = isset($_POST['receive_sms_controller']) ? 1 : 0;
    $receive_sms_chemical      = isset($_POST['receive_sms_chemical']) ? 1 : 0;
    $receive_sms_lora_offline  = isset($_POST['receive_sms_lora_offline']) ? 1 : 0;
    $receive_sms_equipment_off = isset($_POST['receive_sms_equipment_off']) ? 1 : 0;
    $receive_sms_perlite       = isset($_POST['receive_sms_perlite']) ? 1 : 0;
    $sms_alarm_min_minutes     = isset($_POST['sms_alarm_min_minutes']) ? (int)$_POST['sms_alarm_min_minutes'] : 17;
    if ($sms_alarm_min_minutes < 0)    { $sms_alarm_min_minutes = 0; }
    if ($sms_alarm_min_minutes > 1440) { $sms_alarm_min_minutes = 1440; }

    $stmt = $conn->prepare("UPDATE users
        SET receive_sms_alarms = ?, receive_sms_controller = ?, receive_sms_chemical = ?,
            receive_sms_lora_offline = ?, receive_sms_equipment_off = ?,
            receive_sms_perlite = ?, sms_alarm_min_minutes = ?
        WHERE id = ?");
    if (!$stmt) {
        die("Erro na consulta: " . $conn->error);
    }
    $stmt->bind_param(
        "iiiiiiii",
        $receive_sms_alarms,
        $receive_sms_controller,
        $receive_sms_chemical,
        $receive_sms_lora_offline,
        $receive_sms_equipment_off,
        $receive_sms_perlite,
        $sms_alarm_min_minutes,
        $user_id
    );
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Preferências de SMS atualizadas.";
    } else {
        $_SESSION['error_message'] = "Erro ao atualizar preferências SMS: " . $stmt->error;
    }
    $stmt->close();

    header('Content-Type: text/html; charset=utf-8');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Recupera o nome do utilizador logado e outros dados
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone, username, user_type,
                               security_question, security_answer,
                               receive_sms_alarms,
                               COALESCE(receive_sms_controller, receive_sms_alarms) AS receive_sms_controller,
                               COALESCE(receive_sms_chemical, receive_sms_alarms) AS receive_sms_chemical,
                               COALESCE(receive_sms_lora_offline, receive_sms_alarms) AS receive_sms_lora_offline,
                               COALESCE(receive_sms_equipment_off, receive_sms_alarms) AS receive_sms_equipment_off,
                               COALESCE(receive_sms_perlite, receive_sms_alarms) AS receive_sms_perlite,
                               COALESCE(sms_alarm_min_minutes, 17) AS sms_alarm_min_minutes
                        FROM users WHERE id = ?");
if (!$stmt) {
    die("Erro na consulta: " . $conn->error);
}
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result(
    $first_name,
    $last_name,
    $email,
    $phone,
    $username,
    $user_type,
    $security_question,
    $security_answer,
    $receive_sms_alarms_e,
    $receive_sms_controller_e,
    $receive_sms_chemical_e,
    $receive_sms_lora_offline_e,
    $receive_sms_equipment_off_e,
    $receive_sms_perlite_e,
    $sms_alarm_min_minutes_e
);
$stmt->fetch();
$stmt->close();
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="/work_log/about.php">WorkLog CMMS</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
				<li class="nav-item">
                    <a class="nav-link" href="#" onclick="openDashboardInNewWindow();" title="Abrir Dashboard noutra janela">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        Olá, <?= htmlspecialchars($_SESSION['user_name']); ?>
					</a>
                </li>
				<li class="nav-item">
					<a class="nav-link" href="/work_log/inbox.php">        
                        <?php if (isset($unread_messages_count) && $unread_messages_count > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-8" title="<?= $unread_messages_count ?> mensagem(ns) não lida(s)">
                                <i class="fas fa-envelope"></i> <?= $unread_messages_count ?>
                            </span>
                        <?php endif; ?>
                    </a>
					<a class="nav-link" href="/work_log/list_work_orders.php">    
                        <?php if (isset($unaccepted_ot_count) && $unaccepted_ot_count > 0): ?>
                            <span class="badge bg-warning rounded-pill text-dark ms-8" title="<?= $unaccepted_ot_count ?> OT(s) por aceitar">
                                <i class="fas fa-tasks"></i> <?= $unaccepted_ot_count ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/work_log/logout.php">Sair</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Modal de Edição de Perfil -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileLabel">Editar Perfil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Formulário para editar perfil -->
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="first_name" class="form-label">Primeiro Nome</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="last_name" class="form-label">Último Nome</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Telefone</label>
                        <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="security_question" class="form-label">Pergunta de Segurança</label>
                        <input type="text" class="form-control" id="security_question" name="security_question" value="<?= htmlspecialchars($security_question); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="security_answer" class="form-label">Resposta de Segurança</label>
                        <input type="text" class="form-control" id="security_answer" name="security_answer" value="<?= htmlspecialchars($security_answer); ?>" required>
                    </div>
                    <!-- Campos não editáveis (nome de utilizador e tipo de utilizador) -->
                    <div class="mb-3">
                        <label for="username" class="form-label">Nome de Utilizador</label>
                        <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($username); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="user_type" class="form-label">Tipo de Utilizador</label>
                        <input type="text" class="form-control" id="user_type" value="<?= htmlspecialchars($user_type); ?>" disabled>
                    </div>

					<div class="mb-3">
					    <label class="form-label">Assinatura (desenhe abaixo)</label><br>
					    <canvas id="signature-pad" width="400" height="150" style="border:1px solid #ccc; display:block;"></canvas>
						<?php
						// Exibir a assinatura atual se existir
						$signature_path = "";
						$stmt_sig = $conn->prepare("SELECT signature_path FROM users WHERE id = ?");
						$stmt_sig->bind_param("i", $user_id);
						$stmt_sig->execute();
						$stmt_sig->bind_result($signature_path);
						$stmt_sig->fetch();
						$stmt_sig->close();
						
						if (!empty($signature_path) && file_exists($signature_path)): ?>
						    <div class="mt-3">
						        <label class="form-label">Assinatura Atual:</label><br>
						        <img src="<?= htmlspecialchars($signature_path); ?>" alt="Assinatura" style="border:1px solid #ccc; max-width:400px; height:auto;">
						    </div>
						<?php endif; ?>
					    <input type="hidden" id="signature-data" name="signature_data">
					    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clear-signature">Limpar Assinatura</button>
					</div>
                    <button type="submit" name="update_profile" class="btn btn-primary">Salvar Alterações</button>
                </form>

                <!-- Formulário separado só para preferências SMS. O botão
                     "Salvar Alterações" acima nunca modifica flags de SMS. -->
                <form method="post" action="" class="mt-3">
                    <div class="card mb-3">
                        <div class="card-header">Preferências SMS</div>
                        <div class="card-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_alarms" name="receive_sms_alarms" value="1" <?= !empty($receive_sms_alarms_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_alarms">Ativar receção de SMS</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_controller" name="receive_sms_controller" value="1" <?= !empty($receive_sms_controller_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_controller">Alarmes de controlador</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_chemical" name="receive_sms_chemical" value="1" <?= !empty($receive_sms_chemical_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_chemical">Alarmes químicos (cloro / pH)</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_lora_offline" name="receive_sms_lora_offline" value="1" <?= !empty($receive_sms_lora_offline_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_lora_offline">LoRa offline</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_equipment_off" name="receive_sms_equipment_off" value="1" <?= !empty($receive_sms_equipment_off_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_equipment_off">Equipamento OFF (LoRa)</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="np_receive_sms_perlite" name="receive_sms_perlite" value="1" <?= !empty($receive_sms_perlite_e) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="np_receive_sms_perlite">Substituir perlite (filtros)</label>
                            </div>
                            <div class="mb-2">
                                <label for="np_sms_alarm_min_minutes" class="form-label">Minutos mínimos em alarme (controlador/químicos)</label>
                                <input type="number" class="form-control" id="np_sms_alarm_min_minutes" name="sms_alarm_min_minutes" min="0" max="1440" value="<?= htmlspecialchars((string)$sms_alarm_min_minutes_e); ?>">
                                <small class="text-muted">0 = envio imediato quando o alarme entra.</small>
                            </div>
                            <button type="submit" name="update_sms_prefs" class="btn btn-outline-primary btn-sm">Guardar preferências SMS</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>	

<!-- Mensagens de Sucesso ou Erro -->
<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <?= $_SESSION['success_message']; ?>
        <?php unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger">
        <?= $_SESSION['error_message']; ?>
        <?php unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<style>
    .user-name {
        margin-right: -700px; /* Ajuste o valor conforme necessário */
    }
    .form-label {
        color: blue;
    }
    .modal-title {
        color: blue;
    }
    .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
        }
</style>
<script>
const canvas = document.getElementById("signature-pad");
const ctx = canvas.getContext("2d");
let drawing = false;

canvas.addEventListener("mousedown", () => drawing = true);
canvas.addEventListener("mouseup", () => {
  drawing = false;
  ctx.beginPath();
});
canvas.addEventListener("mousemove", draw);
canvas.addEventListener("mouseout", () => drawing = false);

function draw(e) {
  if (!drawing) return;
  ctx.lineWidth = 2;
  ctx.lineCap = "round";
  ctx.strokeStyle = "#000";
  ctx.lineTo(e.offsetX, e.offsetY);
  ctx.stroke();
  ctx.beginPath();
  ctx.moveTo(e.offsetX, e.offsetY);
}

document.getElementById("clear-signature").addEventListener("click", () => {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
});

document.querySelector("form").addEventListener("submit", function () {
  const dataURL = canvas.toDataURL("image/png");
  document.getElementById("signature-data").value = dataURL;
});
</script>

<script>
    function openDashboardInNewWindow() {
        // Define o URL para a sua página de dashboard
        const url = '/work_log/pools/dashboard.php';
        
        // Define um nome para a janela. Usar o mesmo nome fará com que o botão
        // reutilize a janela se ela já estiver aberta, em vez de abrir uma nova.
        const windowName = 'WorkLogDashboard';
        
        // Define as características da nova janela (tamanho, sem barras de ferramentas, etc.)
        const windowFeatures = 'width=1280,height=720,menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes';
        
        // Abre a nova janela
        window.open(url, windowName, windowFeatures);
    }
</script>
