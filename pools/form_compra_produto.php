<?php
require_once '../header.php';

// Busca todos os produtos químicos existentes para preencher o dropdown
$chemicals_stmt = $conn->query("SELECT id, name, unit FROM chemicals ORDER BY name ASC");
$chemicals = $chemicals_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Registar Compra de Produto</h3>
        </div>
        <div class="card-body">
            <form action="guardar_produto.php" method="POST">
                <input type="hidden" name="action" value="register_purchase">

                <div class="mb-3">
                    <label for="chemical_id" class="form-label">Produto Químico</label>
                    <select class="form-select" id="chemical_id" name="chemical_id" required>
                        <option value="">-- Selecione um produto --</option>
                        <?php foreach($chemicals as $chemical): ?>
                            <option value="<?= $chemical['id'] ?>">
                                <?= htmlspecialchars($chemical['name']) ?> (<?= htmlspecialchars($chemical['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="quantity" class="form-label">Quantidade Comprada</label>
                    <input type="number" step="0.01" class="form-control" id="quantity" name="quantity" required>
                </div>

                <div class="mb-3">
                    <label for="purchase_date" class="form-label">Data da Compra</label>
                    <input type="date" class="form-control" id="purchase_date" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="mb-3">
                    <label for="notes" class="form-label">Notas (opcional)</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Ex: Fatura nº 123, Fornecedor XYZ"></textarea>
                </div>
                
                <div class="text-end mt-4">
                    <a href="registos.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-success">Registar Entrada de Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>