<?php
session_start();
require 'db.php'; // ligação à BD do WorkLog

$token = $_GET['token'] ?? null;

if (!$token) {
    header('Location: login.php');
    exit;
}


// 1️⃣ Validar token
$stmt = $pdoSuper->prepare("
    SELECT admin_id, system_id
    FROM admin_tokens
    WHERE token = ?
      AND used = 0
      AND expires_at > NOW()
");
$stmt->execute([$token]);
$t = $stmt->fetch();

if (!$t) {
    header('Location: login.php');
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
    WHERE admin_id = ? AND system_id = ?
");
$stmt->execute([$t['admin_id'], $t['system_id']]);
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

// 6️⃣ Redirecionar
if ($user['user_type'] === 'admin') {
    header('Location: admin_dashboard.php');
} else {
    header('Location: user_dashboard.php');
}
exit;
