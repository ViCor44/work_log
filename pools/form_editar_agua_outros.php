<?php
require_once '../header.php';

$edit_date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$edit_date) {
    die("Data não especificada.");
}

$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'outro' AND name NOT IN ('Rede', 'Agua Quente Edificio') AND water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT id, tank_id, meter_value FROM water_readings WHERE DATE(reading_datetime) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $edit_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$existing_data = [];
foreach ($results as $row) {
    $existing_data[$row['tank_id']] = $row;
}
?>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4">Editar Leituras de Outros Tanques para o dia <?= date('d/m/Y', strtotime($edit_date)) ?></h1>
    
    <form action="guardar_edicao_agua_outros.php" method="POST">
        <input type="hidden" name="edit_date" value="<?= htmlspecialchars($edit_date) ?>">
        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        </div>
                        <div class="card-body">
                            <?php $data = isset($existing_data[$tank['id']]) ? $existing_data[$tank['id']] : null; ?>
                            <input type="hidden" name="record_id[<?= $tank['id'] ?>]" value="<?= $data ? $data['id'] : '' ?>">
                            <div class="mb-3">
                                <label class="form-label">Leitura (m³)</label>
                                <input type="number" step="1" class="form-control" name="meter_value[<?= $tank['id'] ?>]" value="<?= $data ? htmlspecialchars($data['meter_value']) : '' ?>">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="list_agua_outros.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success">Guardar Todas as Alterações</button>
        </div>
    </form>
</div>
<?php require_once '../footer.php'; ?>