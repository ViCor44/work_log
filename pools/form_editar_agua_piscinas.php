<?php
require_once '../header.php';

// Valida a data recebida pela URL
$edit_date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$edit_date) {
    die("Data não especificada.");
}

// Busca as piscinas que têm contagem de água
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'piscina' AND water_reading_frequency > 0 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// Busca TODOS os registos para a data especificada para as piscinas
$sql = "SELECT * FROM water_readings WHERE DATE(reading_datetime) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $edit_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Organiza os dados existentes numa matriz para preencher os campos
$existing_data = [];
foreach ($results as $row) {
    $period = (date('H', strtotime($row['reading_datetime'])) < 13) ? 'manha' : 'tarde';
    $existing_data[$row['tank_id']][$period] = $row;
}
?>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4">Editar Leituras das Piscinas para o dia <?= date('d/m/Y', strtotime($edit_date)) ?></h1>
    
    <form action="guardar_edicao_agua_piscinas.php" method="POST">
        <input type="hidden" name="edit_date" value="<?= htmlspecialchars($edit_date) ?>">
        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
                    <div class="tank-card-form">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <hr class="mt-1 mb-3">
                        
                        <?php 
                            $manha_data = isset($existing_data[$tank['id']]['manha']) ? $existing_data[$tank['id']]['manha'] : null;
                            $tarde_data = isset($existing_data[$tank['id']]['tarde']) ? $existing_data[$tank['id']]['tarde'] : null;
                        ?>

                        <div class="mb-3">
                            <label class="form-label">Leitura Manhã (m³)</label>
                            <input type="hidden" name="record_id[<?= $tank['id'] ?>][manha]" value="<?= $manha_data ? $manha_data['id'] : '' ?>">
                            <input type="number" step="0.001" class="form-control" name="meter_value[<?= $tank['id'] ?>][manha]" value="<?= $manha_data ? htmlspecialchars($manha_data['meter_value']) : '' ?>">
                        </div>

                         <div class="mb-3">
                            <label class="form-label">Leitura Tarde (m³)</label>
                            <input type="hidden" name="record_id[<?= $tank['id'] ?>][tarde]" value="<?= $tarde_data ? $tarde_data['id'] : '' ?>">
                            <input type="number" step="0.001" class="form-control" name="meter_value[<?= $tank['id'] ?>][tarde]" value="<?= $tarde_data ? htmlspecialchars($tarde_data['meter_value']) : '' ?>">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="list_agua_piscinas.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success">Guardar Todas as Alterações</button>
        </div>
    </form>
</div>

<?php require_once '../footer.php'; ?>