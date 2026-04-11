<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $ip_address = trim($_POST['ip_address'] ?? '');
    $slave_id = isset($_POST['slave_id']) ? (int)$_POST['slave_id'] : 1;

    if ($name === '' || $ip_address === '') {
        $error_message = 'Nome e IP sao obrigatorios.';
    } else {
        $stmt = $conn->prepare("INSERT INTO filter_equipment (name, ip_address, slave_id) VALUES (?, ?, ?)");

        if ($stmt === false) {
            $error_message = 'Erro ao preparar insercao. Verifique se a tabela filter_equipment existe.';
        } else {
            $stmt->bind_param('ssi', $name, $ip_address, $slave_id);

            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Filtro Defender criado com sucesso!';
                header('Location: gerir_ativos.php');
                exit;
            }

            $error_message = 'Erro ao criar o filtro: ' . $stmt->error;
            $stmt->close();
        }
    }
}
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Criar Equipamento Filtro Defender</h3>
        </div>
        <div class="card-body">
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <form action="create_filter_equipment.php" method="POST">
                <div class="mb-3">
                    <label for="name" class="form-label">Nome do Filtro</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="Ex: Filtro Defender 1" required>
                </div>

                <div class="mb-3">
                    <label for="ip_address" class="form-label">Endereco IP</label>
                    <input type="text" class="form-control" id="ip_address" name="ip_address" placeholder="Ex: 192.168.1.50" required>
                </div>

                <div class="mb-3">
                    <label for="slave_id" class="form-label">Slave ID Modbus</label>
                    <input type="number" class="form-control" id="slave_id" name="slave_id" value="1" min="1" required>
                    <div class="form-text">Por defeito e 1. Ajuste se necessario.</div>
                </div>

                <div class="text-end mt-4">
                    <a href="gerir_ativos.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Criar Filtro</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once 'footer.php';
?>
