<?php
session_start();

// Verifica se o usuário está logado e se é admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Conexão ao banco de dados

function ensure_sms_pref_columns(mysqli $conn): void
{
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_controller TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_chemical TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_lora_offline TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS receive_sms_equipment_off TINYINT(1) NOT NULL DEFAULT 1");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_alarm_min_minutes INT NOT NULL DEFAULT 17");
}

ensure_sms_pref_columns($conn);

// Consulta para obter todos os usuários
$users = [];
$stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone, user_type, accepted,
                               receive_sms_alarms,
                               COALESCE(receive_sms_controller, receive_sms_alarms) AS receive_sms_controller,
                               COALESCE(receive_sms_chemical, receive_sms_alarms) AS receive_sms_chemical,
                               COALESCE(receive_sms_lora_offline, receive_sms_alarms) AS receive_sms_lora_offline,
                               COALESCE(receive_sms_equipment_off, receive_sms_alarms) AS receive_sms_equipment_off,
                               COALESCE(sms_alarm_min_minutes, 17) AS sms_alarm_min_minutes
                        FROM users");
if ($stmt) {
    $stmt->execute();
    $stmt->bind_result(
        $user_id,
        $first_name,
        $last_name,
        $email,
        $phone,
        $user_type,
        $accepted,
        $receive_sms_alarms,
        $receive_sms_controller,
        $receive_sms_chemical,
        $receive_sms_lora_offline,
        $receive_sms_equipment_off,
        $sms_alarm_min_minutes
    );
    while ($stmt->fetch()) {
        $users[] = [
            'id' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'user_type' => $user_type,
            'accepted' => $accepted,
            'receive_sms_alarms' => $receive_sms_alarms,
            'receive_sms_controller' => $receive_sms_controller,
            'receive_sms_chemical' => $receive_sms_chemical,
            'receive_sms_lora_offline' => $receive_sms_lora_offline,
            'receive_sms_equipment_off' => $receive_sms_equipment_off,
            'sms_alarm_min_minutes' => $sms_alarm_min_minutes,
        ];
    }
    $stmt->close();
} else {
    die("Erro na consulta de usuários: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Utilizadores</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1>Gerir Utilizadores</h1>
    <div class="mb-3">
        <a href="create_user.php" class="btn btn-primary">Criar Novo Utilizador</a>
        <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
    </div>
    
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Email</th>
                <th>Telefone</th>
                <th>Tipo</th>
                <th>SMS</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['id']); ?></td>
                <td><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                <td><?= htmlspecialchars($user['email']); ?></td>
                <td><?= htmlspecialchars($user['phone']); ?></td>
                <td><?= htmlspecialchars($user['user_type']); ?></td>
                <td>
                    <?php if (!empty($user['receive_sms_alarms'])): ?>
                        <?= !empty($user['receive_sms_controller']) ? 'Ctrl ' : ''; ?>
                        <?= !empty($user['receive_sms_chemical']) ? 'Quim ' : ''; ?>
                        <?= !empty($user['receive_sms_lora_offline']) ? 'LoRaOff ' : ''; ?>
                        <?= !empty($user['receive_sms_equipment_off']) ? 'EquipOff ' : ''; ?>
                        (<?= (int)$user['sms_alarm_min_minutes']; ?> min)
                    <?php else: ?>
                        Desativado
                    <?php endif; ?>
                </td>
                <td>
                    <a href="edit_user.php?id=<?= $user['id']; ?>" class="btn btn-warning btn-sm">Editar</a>
                    <?php if ($_SESSION['user_id'] !== $user['id']): // Verifica se não é o próprio usuário ?>
                        <a href="delete_user.php?id=<?= $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir este utilizador?');">Excluir</a>
                    <?php endif; ?>
                    <?php if ($user['accepted'] == 0): ?>
                        <a href="accept_user.php?id=<?= $user['id']; ?>" class="btn btn-success btn-sm">Aceitar</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
