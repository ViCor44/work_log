<?php
require_once '../header.php';

// Busca tanques que têm qualquer tipo de contagem de água
$stmt = $conn->query("SELECT id, name, water_reading_frequency FROM tanks WHERE water_reading_frequency > 0 ORDER BY name");
$tanks = $stmt->fetch_all(MYSQLI_ASSOC);
?>

<style>
    .tank-card-form {
        background-color: #36a2eb; /* Um azul diferente para este formulário */
        color: white;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .tank-card-form h5 { font-weight: bold; }
    .tank-card-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; }
    .tank-card-form .form-control { background-color: rgba(255,255,255,0.9); border: 1px solid #ccc; color: #333; }
    .form-actions { background-color: #f8f9fa; padding: 1rem; border-radius: 0.5rem; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 8px rgba(0,0,0,0.1); }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Registar Leituras de Água em Lote</h1>
    </div>

    <?php if(count($tanks) > 0): ?>
    <form action="guardar_registos_batch.php" method="POST">
        <input type="hidden" name="tipo_registo" value="agua">

        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                    <div class="tank-card-form">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <p class="text-white-50" style="font-size: 0.8rem;">Frequência: <?= $tank['water_reading_frequency'] ?>x por dia</p>
                        <hr class="text-white-50 mt-1 mb-3">
                        <div class="mb-2">
                            <label class="form-label">Valor do Contador (m³)</label>
                            <input type="number" step="0.001" class="form-control" name="agua[<?= $tank['id'] ?>]">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="registos.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Todas as Leituras</button>
        </div>
    </form>
    <?php else: ?>
        <div class="alert alert-warning">Não existem tanques configurados para registo de leituras de água.</div>
    <?php endif; ?>
</div>

<?php
require_once '../footer.php';
?>