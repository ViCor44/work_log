<?php
require_once 'header.php';

// Ir buscar a lista de Casas de Máquinas (categories) para preencher o dropdown
$categories = [];
$result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($result) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Processa o formulário quando é submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Recolher os dados do formulário
    $name = $_POST['name'];
    $ip_address = $_POST['ip_address'];
    $slave_id = $_POST['slave_id'];
    $category_id = $_POST['category_id'];

    // Preparar e executar a inserção na base de dados
    $stmt = $conn->prepare("INSERT INTO remote_equipment (name, ip_address, slave_id, category_id) VALUES (?, ?, ?, ?)");
    // "ssii" significa que as variáveis são: string, string, integer, integer
    $stmt->bind_param("ssii", $name, $ip_address, $slave_id, $category_id);
    
   if ($stmt->execute()) {
    $_SESSION['success_message'] = "Equipamento remoto criado com sucesso!";
    
    // Redireciona para a página de gestão de ativos

	} else {
	    $error_message = "Erro ao criar o equipamento: " . $stmt->error;
	} 
    $stmt->close();
}
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Criar Novo Equipamento Remoto</h3>
        </div>
        <div class="card-body">
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <form action="create_remote_equipment.php" method="POST">
                <div class="mb-3">
                    <label for="name" class="form-label">Nome do Equipamento</label>
                    <input type="text" class="form-control" id="name" name="name" placeholder="Ex: Bomba de Calor Piscina Principal" required>
                    <div class="form-text">Nome descritivo que aparecerá no dashboard.</div>
                </div>

                <div class="mb-3">
                    <label for="ip_address" class="form-label">Endereço IP (Arduino)</label>
                    <input type="text" class="form-control" id="ip_address" name="ip_address" placeholder="Ex: 191.188.127.152" required>
                </div>

                <div class="mb-3">
                    <label for="slave_id" class="form-label">ID do Slave Modbus</label>
                    <input type="number" class="form-control" id="slave_id" name="slave_id" placeholder="Ex: 4" required>
                </div>

                <div class="mb-3">
                    <label for="category_id" class="form-label">Casa de Máquinas</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value="" disabled selected>Selecione uma Casa de Máquinas...</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['id']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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