<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $dev_eui = $_POST['dev_eui'];

    $stmt = $conn->prepare("INSERT INTO lorawan_devices (name, dev_eui) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $dev_eui);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Equipamento LoRaWAN criado com sucesso!";
        header("Location: gerir_ativos.php");
        exit;
    } else {
        $error_message = "Erro ao criar o equipamento: " . $stmt->error;
    }
    $stmt->close();
}
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Criar Novo Equipamento LoRaWAN</h3>
        </div>
        <div class="card-body">
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <form action="create_lora.php" method="POST">
                <div class="mb-3">
                    <label for="name" class="form-label">Nome do Equipamento</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="Ex: OSMOSE_1" required>
                </div>
                <div class="mb-3">
                    <label for="dev_eui" class="form-label">Device EUI (16 caracteres)</label>
                    <input type="text" class="form-control" id="dev_eui" name="dev_eui" maxlength="16" required>
                </div>
                <div class="text-end mt-4">
                    <a href="gerir_ativos.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Criar Equipamento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once 'footer.php';
?>