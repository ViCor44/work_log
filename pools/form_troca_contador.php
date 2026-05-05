<?php
require_once '../header.php';
require_once 'meter_continuity.php';

ensure_meter_replacements_table($conn);

$tanks = $conn->query("SELECT id, name, type, has_reject_counter FROM tanks WHERE water_reading_frequency > 0 ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$recent = $conn->query("\n    SELECT mr.*, t.name AS tank_name, CONCAT(u.first_name, ' ', u.last_name) AS user_name\n    FROM meter_replacements mr\n    JOIN tanks t ON t.id = mr.tank_id\n    LEFT JOIN users u ON u.id = mr.created_by\n    ORDER BY mr.replacement_datetime DESC, mr.id DESC\n    LIMIT 20\n")->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Registar Troca de Contador</h1>
        <a href="registos.php" class="btn btn-secondary">Voltar</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form action="guardar_troca_contador.php" method="POST" class="row g-3" id="form-troca">
                <div class="col-md-4">
                    <label for="tank_id" class="form-label">Tanque</label>
                    <select class="form-select" id="tank_id" name="tank_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($tanks as $tank): ?>
                            <option value="<?= (int)$tank['id'] ?>" data-has-reject="<?= (int)$tank['has_reject_counter'] ?>">
                                <?= htmlspecialchars($tank['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="reading_type" class="form-label">Tipo de Contador</label>
                    <select class="form-select" id="reading_type" name="reading_type" required>
                        <option value="normal">Normal</option>
                        <option value="rejected">Rejeitado</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="replacement_datetime" class="form-label">Data/Hora da Troca</label>
                    <input type="datetime-local" class="form-control" id="replacement_datetime" name="replacement_datetime" value="<?= date('Y-m-d\\TH:i') ?>" required>
                </div>

                <div class="col-md-3">
                    <label for="old_reading" class="form-label">Leitura Final Antiga</label>
                    <input type="number" step="0.001" min="0" class="form-control" id="old_reading" name="old_reading" required>
                </div>

                <div class="col-md-3">
                    <label for="new_reading" class="form-label">Leitura Inicial Nova</label>
                    <input type="number" step="0.001" min="0" class="form-control" id="new_reading" name="new_reading" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Offset Calculado</label>
                    <input type="text" class="form-control" id="offset_preview" value="0" readonly>
                    <small class="text-muted">Offset = antiga - nova</small>
                </div>

                <div class="col-md-12">
                    <label for="notes" class="form-label">Observações</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Ex.: contador avariado, substituição em manutenção corretiva"></textarea>
                </div>

                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary">Guardar Troca</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Últimas Trocas Registadas</h5>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Tanque</th>
                        <th>Tipo</th>
                        <th>Antiga</th>
                        <th>Nova</th>
                        <th>Offset</th>
                        <th>Utilizador</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="7" class="text-muted text-center">Sem trocas registadas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent as $row): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($row['replacement_datetime'])) ?></td>
                                <td><?= htmlspecialchars($row['tank_name']) ?></td>
                                <td><?= $row['reading_type'] === 'rejected' ? 'Rejeitado' : 'Normal' ?></td>
                                <td><?= number_format((float)$row['old_reading'], 3, ',', '.') ?></td>
                                <td><?= number_format((float)$row['new_reading'], 3, ',', '.') ?></td>
                                <td><?= number_format((float)$row['offset_delta'], 3, ',', '.') ?></td>
                                <td><?= htmlspecialchars($row['user_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const oldInput = document.getElementById('old_reading');
    const newInput = document.getElementById('new_reading');
    const preview = document.getElementById('offset_preview');

    function updateOffsetPreview() {
        const oldVal = parseFloat(oldInput.value || '0');
        const newVal = parseFloat(newInput.value || '0');
        const offset = oldVal - newVal;
        preview.value = Number.isFinite(offset) ? offset.toFixed(3) : '0';
    }

    oldInput.addEventListener('input', updateOffsetPreview);
    newInput.addEventListener('input', updateOffsetPreview);
})();
</script>

<?php require_once '../footer.php'; ?>
