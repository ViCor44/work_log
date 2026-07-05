<?php
session_start();

// Verificar se o usuário é administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php';

function ensure_sms_pref_columns(mysqli $conn): void
{
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_controller TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_chemical TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_lora_offline TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_equipment_off TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_alarm_min_minutes INT NOT NULL DEFAULT 17");
}

ensure_sms_pref_columns($conn);

$message = "";

// Verificar se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $user_type = $_POST['user_type']; // papel do usuário (admin ou utilizador)
    $receive_sms_alarms = isset($_POST['receive_sms_alarms']) ? 1 : 0;
    $receive_sms_controller = isset($_POST['receive_sms_controller']) ? 1 : 0;
    $receive_sms_chemical = isset($_POST['receive_sms_chemical']) ? 1 : 0;
    $receive_sms_lora_offline = isset($_POST['receive_sms_lora_offline']) ? 1 : 0;
    $receive_sms_equipment_off = isset($_POST['receive_sms_equipment_off']) ? 1 : 0;
    $sms_alarm_min_minutes = isset($_POST['sms_alarm_min_minutes']) ? (int)$_POST['sms_alarm_min_minutes'] : 17;
    if ($sms_alarm_min_minutes < 0) { $sms_alarm_min_minutes = 0; }
    if ($sms_alarm_min_minutes > 1440) { $sms_alarm_min_minutes = 1440; }

    // Verificar se as senhas coincidem
    if ($password !== $confirm_password) {
        $message = "As senhas não coincidem.";
    } else {
        // Verificar se o username já existe
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $message = "O nome de usuário já existe. Por favor, escolha outro.";
        } else {
            // Hash da senha
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Inserir o novo utilizador na base de dados
            $stmt = $conn->prepare("INSERT INTO users (
                username, first_name, last_name, email, phone, password, user_type, accepted,
                receive_sms_alarms, receive_sms_controller, receive_sms_chemical,
                receive_sms_lora_offline, receive_sms_equipment_off, sms_alarm_min_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                die("Erro na preparação da consulta: " . $conn->error);
            }

            $stmt->bind_param(
                "sssssssiiiiii",
                $username,
                $first_name,
                $last_name,
                $email,
                $phone,
                $hashed_password,
                $user_type,
                $receive_sms_alarms,
                $receive_sms_controller,
                $receive_sms_chemical,
                $receive_sms_lora_offline,
                $receive_sms_equipment_off,
                $sms_alarm_min_minutes
            );

            if ($stmt->execute()) {
                $message = "Utilizador criado com sucesso!";
            } else {
                $message = "Erro ao criar utilizador: " . $stmt->error;
            }

            $stmt->close();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Utilizador</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h1>Criar Novo Utilizador</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" action="create_user.php">
        <div class="mb-3">
            <label for="username" class="form-label">Nome de Utilizador</label>
            <input type="text" class="form-control" id="username" name="username" autocomplete="off" required>
        </div>
        <div class="mb-3">
            <label for="first_name" class="form-label">Nome</label>
            <input type="text" class="form-control" id="first_name" name="first_name" autocomplete="off" required>
        </div>
        <div class="mb-3">
            <label for="last_name" class="form-label">Sobrenome</label>
            <input type="text" class="form-control" id="last_name" name="last_name" autocomplete="off" required>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" autocomplete="new-email" required>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Telefone</label>
            <input type="text" class="form-control" id="phone" name="phone" autocomplete="off" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">Senha</label>
            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label">Confirmar Senha</label>
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
        </div>
        <div class="mb-3">
            <label for="user_type" class="form-label">Tipo de Utilizador</label>
            <select class="form-select" id="user_type" name="user_type" required>
                <option value="user" selected>Comum</option>
                <option value="admin">Administrador</option>
				<option value="viewer">Viewer</option>
            </select>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="receive_sms_alarms" name="receive_sms_alarms" value="1">
            <label class="form-check-label" for="receive_sms_alarms">
                Ativar receção de SMS
            </label>
        </div>

        <div class="card mb-3">
            <div class="card-header">Preferências SMS</div>
            <div class="card-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_controller" name="receive_sms_controller" value="1" checked>
                    <label class="form-check-label" for="receive_sms_controller">Alarmes de controlador</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_chemical" name="receive_sms_chemical" value="1" checked>
                    <label class="form-check-label" for="receive_sms_chemical">Alarmes químicos (cloro / pH)</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_lora_offline" name="receive_sms_lora_offline" value="1" checked>
                    <label class="form-check-label" for="receive_sms_lora_offline">LoRa offline</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="receive_sms_equipment_off" name="receive_sms_equipment_off" value="1" checked>
                    <label class="form-check-label" for="receive_sms_equipment_off">Equipamento OFF (LoRa)</label>
                </div>

                <div class="mb-2">
                    <label for="sms_alarm_min_minutes" class="form-label">Minutos mínimos em alarme (controlador/químicos)</label>
                    <input type="number" class="form-control" id="sms_alarm_min_minutes" name="sms_alarm_min_minutes" min="0" max="1440" value="17">
                    <small class="text-muted">Cada utilizador pode ter o seu próprio tempo (0 = imediato).</small>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Criar Utilizador</button>
        <a href="manage_users.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
