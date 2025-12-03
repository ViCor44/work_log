<?php
require_once '../header.php';

$chemicals_stmt = $conn->query("SELECT * FROM chemicals ORDER BY name ASC");
$chemicals = $chemicals_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gestão de Produtos Químicos</h1>
        <div>
            <a href="form_compra_produto.php" class="btn btn-success"><i class="fas fa-shopping-cart"></i> Registar Compra</a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                <i class="fas fa-plus"></i> Novo Produto
            </button>
            <a href="javascript:history.back()" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nome do Produto</th>
                        <th>Unidade</th>
                        <th>Volume Padrão (Emb.)</th> <th class="text-center">Stock Atual</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($chemicals) > 0): ?>
                        <?php foreach($chemicals as $chemical): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($chemical['name']) ?></strong></td>
                            <td><?= htmlspecialchars($chemical['unit']) ?></td>
                            <td><?= number_format($chemical['package_volume'], 2, ',', '.') ?></td> <td class="text-center fw-bold"><?= number_format($chemical['current_stock'], 2, ',', '.') ?></td>
                            <td class="text-center">
                                <a href="form_editar_produto.php?id=<?= $chemical['id'] ?>" class="btn btn-warning btn-sm">
								    <i class="fas fa-edit"></i>
								</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">Nenhum produto químico registado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="guardar_produto.php" method="POST">
                <input type="hidden" name="action" value="create_product">
                <div class="modal-header"><h5 class="modal-title">Novo Produto Químico</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label for="name" class="form-label">Nome do Produto</label><input type="text" class="form-control" id="name" name="name" required></div>
                    <div class="mb-3"><label for="unit" class="form-label">Unidade de Medida</label><input type="text" class="form-control" id="unit" name="unit" placeholder="Ex: Litros, Kg" required></div>
                    <div class="mb-3">
                        <label for="package_volume" class="form-label">Volume Padrão da Embalagem</label>
                        <input type="number" step="0.01" class="form-control" id="package_volume" name="package_volume" value="0.00" required>
                    </div>
                    <div class="mb-3"><label for="initial_stock" class="form-label">Stock Inicial</label><input type="number" step="0.01" class="form-control" id="initial_stock" name="initial_stock" value="0.00" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../footer.php'; ?>