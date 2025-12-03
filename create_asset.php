<?php
require_once 'header.php';

// Busca categorias e tipos de ativo para os dropdowns
$categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$asset_types = $conn->query("SELECT id, name FROM asset_types ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">Criar Novo Ativo</h1>
                <a href="list_assets.php" class="btn btn-secondary">Voltar à Lista</a>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form action="guardar_asset.php" method="POST" enctype="multipart/form-data">
                        
                        <h5 class="mb-3">Informação Geral</h5>
                        <div class="row">
                            <div class="col-md-8"><div class="mb-3"><label for="name" class="form-label">Nome do Ativo</label><input type="text" class="form-control" id="name" name="name" required></div></div>
                            <div class="col-md-4"><div class="mb-3"><label for="category_id" class="form-label">Categoria</label><select class="form-select" id="category_id" name="category_id" required><option value="">-- Selecione --</option><?php foreach($categories as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></div></div>
                        </div>
                        <div class="mb-3"><label for="description" class="form-label">Descrição</label><textarea class="form-control" id="description" name="description" rows="2"></textarea></div>

                        <hr class="my-4">
                        <h5 class="mb-3">Dados de Identificação e Aquisição</h5>
                        <div class="row">
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Fabricante</label><input type="text" class="form-control" name="manufacturer"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Modelo</label><input type="text" class="form-control" name="model"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Nº de Série</label><input type="text" class="form-control" name="serial_number"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Fornecedor</label><input type="text" class="form-control" name="supplier"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Data de Compra</label><input type="date" class="form-control" name="purchase_date"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label class="form-label">Fim da Garantia</label><input type="date" class="form-control" name="warranty_date"></div></div>
                        </div>

                        <hr class="my-4">
                        <h5 class="mb-3">Ficheiros</h5>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Foto do Ativo</label><input type="file" class="form-control" name="photo"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label class="form-label">Manual (PDF)</label><input type="file" class="form-control" name="manual"></div></div>
                        </div>

                        <hr class="my-4">
                        <h5 class="mb-3">Características Específicas</h5>
                        <div class="mb-3">
                            <label for="asset_type_id" class="form-label">Tipo de Ativo</label>
                            <select class="form-select" id="asset_type_id" name="asset_type_id">
                                <option value="">-- Selecione um tipo para ver os campos específicos --</option>
                                <?php foreach($asset_types as $type): ?>
                                    <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="custom-fields-container" class="ps-3 border-start"></div>

                        <div class="mt-4 text-end">
                            <a href="list_assets.php" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Criar Ativo</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('asset_type_id').addEventListener('change', function() {
    const typeId = this.value;
    const container = document.getElementById('custom-fields-container');
    
    if (typeId) {
        container.innerHTML = '<div class="text-muted">A carregar campos...</div>';
        fetch('get_custom_fields.php?type_id=' + typeId)
            .then(response => response.text())
            .then(html => {
                container.innerHTML = html;
            });
    } else {
        container.innerHTML = '';
    }
});
</script>

<?php require_once 'footer.php'; ?>       