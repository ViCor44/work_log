<?php
session_start();

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php'; // Database connection

// Get the user ID to edit from the URL parameter
if (!isset($_GET['id'])) {
    die("User ID not provided.");
}

$user_to_edit_id = $_GET['id'];

// Get user data to pre-fill the form
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone, user_type FROM users WHERE id = ?");
$stmt->bind_param("i", $user_to_edit_id);
$stmt->execute();
$stmt->bind_result($first_name_e, $last_name_e, $email_e, $phone_e, $user_type_e);
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

    // Prevent the current admin from changing their own role
    if ($user_to_edit_id == $_SESSION['user_id']) {
        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $first_name, $last_name, $email, $phone, $user_to_edit_id);
    } else {
        // Update all fields, including the role, for other users
        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, user_type = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $first_name, $last_name, $email, $phone, $user_type, $user_to_edit_id);
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