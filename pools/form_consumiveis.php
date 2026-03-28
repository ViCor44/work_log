<?php
require_once '../header.php';

// Busca todas as piscinas para o dropdown
$pools_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'piscina' ORDER BY name ASC");
$pools = $pools_stmt->fetch_all(MYSQLI_ASSOC);

// Busca todos os produtos químicos para o dropdown
$chemicals_stmt = $conn->query("SELECT id, name, unit FROM chemicals ORDER BY name ASC");
$chemicals = $chemicals_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Registar Troca de Consumíveis</h3>
        </div>
        <div class="card-body">
            <form action="guardar_consumivel.php" method="POST">
                
                <div class="mb-3">
                    <label for="tank_id" class="form-label">1. Selecione a Piscina</label>
                    <select class="form-select" id="tank_id" name="tank_id" required>
                        <option value="">-- Escolha uma piscina --</option>
                        <?php foreach($pools as $pool): ?>
                            <option value="<?= $pool['id'] ?>"><?= htmlspecialchars($pool['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="chemical_id" class="form-label">2. Selecione o Produto</label>
                    <select class="form-select" id="chemical_id" name="chemical_id" required>
                        <option value="">-- Escolha um produto --</option>
                        <?php foreach($chemicals as $chemical): ?>
                            <option value="<?= $chemical['id'] ?>" data-unit="<?= htmlspecialchars($chemical['unit']) ?>">
                                <?= htmlspecialchars($chemical['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="package_volume" class="form-label">3. Volume da Embalagem Trocada</label>
                    <input type="number" step="0.01" class="form-control" id="package_volume" name="package_volume" placeholder="Ex: 25.00" required>
                    <small class="text-muted" id="unit-helper"></small>
                </div>

                <div class="text-end mt-4">
                    <a href="registos.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Registar Troca</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Pequeno script para mostrar a unidade do produto selecionado
document.getElementById('chemical_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const unit = selectedOption.dataset.unit;
    document.getElementById('unit-helper').textContent = unit ? `Unidade: ${unit}` : '';
});
</script>

<?php
require_once '../footer.php';
?>