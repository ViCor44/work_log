<?php
require_once '../header.php';

// --- Lógica de busca ---

// 1. Buscar todos os tanques de hipoclorito para preencher o dropdown
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE uses_hypochlorite = 1 ORDER BY name ASC");
$tanks_list = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// 2. Verificar se o formulário de seleção foi submetido
$selected_tank_id = isset($_GET['tank_id']) ? $_GET['tank_id'] : null;
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$data = null;

if ($selected_tank_id) {
    // Se um tanque e data foram selecionados, busca o último registo desse dia
    $sql = "
        SELECT h.id, h.consumption_liters, h.reading_datetime, t.name as tank_name 
        FROM hypochlorite_readings h 
        JOIN tanks t ON h.tank_id = t.id 
        WHERE h.tank_id = ? AND DATE(h.reading_datetime) = ?
        ORDER BY h.reading_datetime DESC 
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $selected_tank_id, $selected_date);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
    }
    $stmt->close();
}
?>

<div class="container mt-5" style="max-width: 700px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Editar Registo de Hipoclorito</h3>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end mb-4 border-bottom pb-4">
                <div class="col-md-5">
                    <label for="tank_id" class="form-label">1. Selecione o Tanque:</label>
                    <select id="tank_id" name="tank_id" class="form-select" required>
                        <option value="">-- Escolha um tanque --</option>
                        <?php foreach($tanks_list as $tank): ?>
                            <option value="<?= $tank['id'] ?>" <?= ($selected_tank_id == $tank['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tank['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label for="date" class="form-label">2. Selecione a Data:</label>
                    <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Procurar</button>
                </div>
            </form>

            <?php if ($selected_tank_id): ?>
                <hr>
                <?php if ($data): ?>
                    <h5 class="mt-4">A editar o registo de "<?= htmlspecialchars($data['tank_name']) ?>" de <?= date('d/m/Y', strtotime($data['reading_datetime'])) ?></h5>
                    <form action="guardar_edicao_hipoclorito.php" method="POST" class="mt-3">
                        <input type="hidden" name="record_id" value="<?= $data['id'] ?>">
                        <div class="mb-3">
                            <label for="consumption_liters" class="form-label">Nível do Depósito (Litros)</label>
                            <input type="number" step="0.01" class="form-control" id="consumption_liters" name="consumption_liters" value="<?= htmlspecialchars($data['consumption_liters']) ?>" required>
                        </div>
                        <div class="text-end">
                            <a href="list_hipoclorito.php" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-success">Guardar Alterações</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning mt-4">
                        Nenhum registo de hipoclorito encontrado para "<?= htmlspecialchars(array_column($tanks_list, 'name', 'id')[$selected_tank_id]) ?>" na data selecionada.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>