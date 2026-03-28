<?php
require_once '../header.php';

$edit_date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$edit_date) {
    die("Data não especificada.");
}

$stmt_tank = $conn->prepare("SELECT id FROM tanks WHERE name = 'Edificio' LIMIT 1");
$stmt_tank->execute();
$result_tank = $stmt_tank->get_result();
$edificio_tank_id = $result_tank->num_rows > 0 ? $result_tank->fetch_assoc()['id'] : null;
$stmt_tank->close();

if (!$edificio_tank_id) {
    die("Tanque 'Edificio' não encontrado.");
}

// Busca o registo para a data especificada
$sql = "SELECT id, reading_datetime, meter_value FROM water_readings WHERE tank_id = ? AND DATE(reading_datetime) = ? ORDER BY reading_datetime ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $edificio_tank_id, $edit_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Como só há uma leitura, pegamos na primeira (e única) que encontrarmos
$data = !empty($results) ? $results[0] : null;
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Editar Leitura de Água Quente para o dia <?= date('d/m/Y', strtotime($edit_date)) ?></h3>
        </div>
        <div class="card-body">
            <?php if ($data): ?>
                <form action="guardar_edicao_edificio.php" method="POST">
                    <input type="hidden" name="record_id" value="<?= $data['id'] ?>">
                    <div class="mb-3">
                        <label for="meter_value" class="form-label">Valor da Leitura (m³)</label>
                        <input type="number" step="0.001" class="form-control form-control-lg" id="meter_value" name="meter_value" value="<?= htmlspecialchars($data['meter_value']) ?>" required>
                    </div>
                    <div class="text-end mt-4">
                        <a href="list_edificio.php" class="btn btn-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-success">Guardar Alteração</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">
                    Nenhum registo de 'Água Quente Edifício' encontrado para a data selecionada. Não é possível editar.
                </div>
                 <div class="text-end mt-4">
                    <a href="list_edificio.php" class="btn btn-secondary">Voltar</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>