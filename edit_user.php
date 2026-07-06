<?php
session_start();

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Database connection

function ensure_sms_pref_columns(mysqli $conn): void
{
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_controller TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_chemical TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_lora_offline TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_equipment_off TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_perlite TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_alarm_min_minutes INT NOT NULL DEFAULT 17");
}

ensure_sms_pref_columns($conn);

// Get the user ID to edit from the URL parameter
if (!isset($_GET['id'])) {
    die("User ID not provided.");
}

$user_to_edit_id = $_GET['id'];

// Get user data to pre-fill the form
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone, user_type,
                               receive_sms_alarms,
                               COALESCE(receive_sms_controller, receive_sms_alarms) AS receive_sms_controller,
                               COALESCE(receive_sms_chemical, receive_sms_alarms) AS receive_sms_chemical,
                               COALESCE(receive_sms_lora_offline, receive_sms_alarms) AS receive_sms_lora_offline,
                               COALESCE(receive_sms_equipment_off, receive_sms_alarms) AS receive_sms_equipment_off,
                               COALESCE(receive_sms_perlite, receive_sms_alarms) AS receive_sms_perlite,
                               COALESCE(sms_alarm_min_minutes, 17) AS sms_alarm_min_minutes
                        FROM users WHERE id = ?");
$stmt->bind_param("i", $user_to_edit_id);
$stmt->execute();
$stmt->bind_result(
    $first_name_e,
    $last_name_e,
    $email_e,
    $phone_e,
    $user_type_e,
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

// Check if the user was found
if (!$first_name_e) {
    die("User not found.");
}

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $user_type = $_POST['user_type'];
    $receive_sms_alarms = isset($_POST['receive_sms_alarms']) ? 1 : 0;
    $receive_sms_controller = isset($_POST['receive_sms_controller']) ? 1 : 0;
    $receive_sms_chemical = isset($_POST['receive_sms_chemical']) ? 1 : 0;
    $receive_sms_lora_offline = isset($_POST['receive_sms_lora_offline']) ? 1 : 0;
    $receive_sms_equipment_off = isset($_POST['receive_sms_equipment_off']) ? 1 : 0;
    $receive_sms_perlite = isset($_POST['receive_sms_perlite']) ? 1 : 0;
    $sms_alarm_min_minutes = isset($_POST['sms_alarm_min_minutes']) ? (int)$_POST['sms_alarm_min_minutes'] : 17;
    if ($sms_alarm_min_minutes < 0) { $sms_alarm_min_minutes = 0; }
    if ($sms_alarm_min_minutes > 1440) { $sms_alarm_min_minutes = 1440; }

    // Prevent the current admin from changing their own role
    if ($user_to_edit_id == $_SESSION['user_id']) {
        $stmt = $conn->prepare("UPDATE users
            SET first_name = ?, last_name = ?, email = ?, phone = ?,
                receive_sms_alarms = ?, receive_sms_controller = ?, receive_sms_chemical = ?,
                receive_sms_lora_offline = ?, receive_sms_equipment_off = ?,
                receive_sms_perlite = ?, sms_alarm_min_minutes = ?
            WHERE id = ?");
        $stmt->bind_param(
            "ssssiiiiiiii",
            $first_name,
            $last_name,
            $email,
            $phone,
            $receive_sms_alarms,
            $receive_sms_controller,
            $receive_sms_chemical,
            $receive_sms_lora_offline,
            $receive_sms_equipment_off,
            $receive_sms_perlite,
            $sms_alarm_min_minutes,
            $user_to_edit_id
        );
    } else {
        // Update all fields, including the role, for other users
        $stmt = $conn->prepare("UPDATE users
            SET first_name = ?, last_name = ?, email = ?, phone = ?, user_type = ?,
                receive_sms_alarms = ?, receive_sms_controller = ?, receive_sms_chemical = ?,
                receive_sms_lora_offline = ?, receive_sms_equipment_off = ?,
                receive_sms_perlite = ?, sms_alarm_min_minutes = ?
            WHERE id = ?");
        $stmt->bind_param(
            "sssssiiiiiiii",
            $first_name,
            $last_name,
            $email,
            $phone,
            $user_type,
            $receive_sms_alarms,
            $receive_sms_controller,
            $receive_sms_chemical,
            $receive_sms_lora_offline,
            $receive_sms_equipment_off,
            $receive_sms_perlite,
            $sms_alarm_min_minutes,
            $user_to_edit_id
        );
    }

    // Save the signature if provided
    if (!empty($_POST['signature_data'])) {
        $data = $_POST['signature_data'];
        $data = str_replace('data:image/png;base64,', '', $data);
        $data = base64_decode($data);
        $file_path = 'signatures/signature_user_' . $user_to_edit_id . '.png';
        file_put_contents($file_path, $data);

        // Update the signature path in the database
        $stmt_sig = $conn->prepare("UPDATE users SET signature_path = ? WHERE id = ?");
        $stmt_sig->bind_param("si", $file_path, $user_to_edit_id);
        $stmt_sig->execute();
        $stmt_sig->close();
    }

    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit;
    } else {
        echo "Error updating user: " . $stmt->error;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Utilizador</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Editar Utilizador</h1>
    <form method="post" action="edit_user.php?id=<?= htmlspecialchars($user_to_edit_id); ?>">
        <div class="mb-3">
            <label for="first_name" class="form-label">Primeiro Nome</label>
            <input type="text" class="form-control" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name_e); ?>" required>
        </div>
        <div class="mb-3">
            <label for="last_name" class="form-label">Último Nome</label>
            <input type="text" class="form-control" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name_e); ?>" required>
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email_e); ?>" required>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Telefone</label>
            <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($phone_e); ?>" required>
        </div>

        <?php if ($user_to_edit_id != $_SESSION['user_id']): ?>
        <div class="mb-3">
            <label for="user_type" class="form-label">Tipo</label>
            <select class="form-select" id="user_type" name="user_type" required>
                <option value="user" <?= $user_type_e == 'user' ? 'selected' : ''; ?>>User</option>
                <option value="admin" <?= $user_type_e == 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="viewer" <?= $user_type_e == 'viewer' ? 'selected' : ''; ?>>Viewer</option>
            </select>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            Não pode alterar o seu próprio tipo!
        </div>
        <?php endif; ?>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="receive_sms_alarms" name="receive_sms_alarms" value="1" <?= !empty($receive_sms_alarms_e) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="receive_sms_alarms">
                Ativar receção de SMS
            </label>
        </div>

        <div class="card mb-3">
            <div class="card-header">Preferências SMS</div>
            <div class="card-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_controller" name="receive_sms_controller" value="1" <?= !empty($receive_sms_controller_e) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="receive_sms_controller">Alarmes de controlador</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_chemical" name="receive_sms_chemical" value="1" <?= !empty($receive_sms_chemical_e) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="receive_sms_chemical">Alarmes químicos (cloro / pH)</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="receive_sms_lora_offline" name="receive_sms_lora_offline" value="1" <?= !empty($receive_sms_lora_offline_e) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="receive_sms_lora_offline">LoRa offline</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="receive_sms_equipment_off" name="receive_sms_equipment_off" value="1" <?= !empty($receive_sms_equipment_off_e) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="receive_sms_equipment_off">Equipamento OFF (LoRa)</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="receive_sms_perlite" name="receive_sms_perlite" value="1" <?= !empty($receive_sms_perlite_e) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="receive_sms_perlite">Substituir perlite (filtros)</label>
                </div>

                <div class="mb-2">
                    <label for="sms_alarm_min_minutes" class="form-label">Minutos mínimos em alarme (controlador/químicos)</label>
                    <input type="number" class="form-control" id="sms_alarm_min_minutes" name="sms_alarm_min_minutes" min="0" max="1440" value="<?= htmlspecialchars((string)$sms_alarm_min_minutes_e); ?>">
                    <small class="text-muted">0 = envio imediato quando o alarme entra.</small>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Assinatura (desenhe abaixo)</label><br>
            <canvas id="signature-pad" width="400" height="150" style="border:1px solid #ccc; display:block;"></canvas>
            <input type="hidden" id="signature-data" name="signature_data">
            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="clear-signature">Limpar Assinatura</button>
        </div>

        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
        <a href="manage_users.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
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

</body>
</html>

<?php
$conn->close();
?>