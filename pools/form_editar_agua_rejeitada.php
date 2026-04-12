<?php
require_once '../header.php';

$edit_date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$edit_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $edit_date)) {
    die("Data não especificada ou inválida.");
}

// Busca piscinas com contador de rejeitado
$tanks_stmt = $conn->query("SELECT id, name, volume_m3 FROM tanks WHERE type = 'piscina' AND has_reject_counter = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// Busca registos existentes para a data
$sql = "SELECT * FROM rejected_water_readings WHERE DATE(reading_datetime) = ?";
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

<style>
    .tank-card-form { background-color: #0d6efd; color: white; border-radius: 8px; padding: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); height: 100%; display: flex; flex-direction: column; }
    .tank-card-form h5 { font-weight: bold; }
    .tank-card-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; }
    .tank-card-form .form-control { background-color: rgba(255,255,255,0.9); border: 1px solid #ccc; color: #333; font-weight: bold; }
    .form-actions { background-color: #f8f9fa; padding: 1rem; border-radius: 0.5rem; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 8px rgba(0,0,0,0.1); }
</style>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4">
        <i class="fas fa-edit me-2 text-primary"></i>
        Editar Água Rejeitada — <?= date('d/m/Y', strtotime($edit_date)) ?>
    </h1>

    <form action="guardar_edicao_agua_rejeitada.php" method="POST">
        <input type="hidden" name="edit_date" value="<?= htmlspecialchars($edit_date) ?>">
        <div class="row">
            <?php foreach ($tanks as $tank): ?>
                <?php $existing = isset($existing_data[$tank['id']]) ? $existing_data[$tank['id']] : null; ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="tank-card-form">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <hr class="mt-1 mb-3">
                        <div class="mb-3">
                            <label class="form-label">Leitura Contador (m³)</label>
                            <input type="hidden" name="record_id[<?= $tank['id'] ?>]" value="<?= $existing ? (int)$existing['id'] : '' ?>">
                            <input type="number" step="0.001" min="0" class="form-control"
                                   name="meter_value[<?= $tank['id'] ?>]"
                                   value="<?= $existing ? htmlspecialchars($existing['meter_value']) : '' ?>"
                                   placeholder="Ex: 1234.567">
                        </div>
                        <?php if ($existing): ?>
                            <small class="opacity-75">Registado em: <?= date('H:i', strtotime($existing['reading_datetime'])) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="list_agua_rejeitada.php?month=<?= date('Y-m', strtotime($edit_date)) ?>" class="btn btn-secondary me-2">Cancelar</a>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-save me-1"></i>Guardar Alterações
            </button>
        </div>
    </form>
</div>

<?php require_once '../footer.php'; ?>
