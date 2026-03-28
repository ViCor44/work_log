<?php
require_once '../header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID de produto inválido.");
}
$product_id = $_GET['id'];

// Busca os dados do produto a editar
$stmt = $conn->prepare("SELECT * FROM chemicals WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Produto não encontrado.");
}
$product = $result->fetch_assoc();
$stmt->close();
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Editar Produto Químico</h3>
        </div>
        <div class="card-body">
            <form action="guardar_produto.php" method="POST">
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="product_id" value="<?= $product_id ?>">

                <div class="mb-3">
                    <label for="name" class="form-label">Nome do Produto</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="unit" class="form-label">Unidade de Medida</label>
                    <input type="text" class="form-control" id="unit" name="unit" value="<?= htmlspecialchars($product['unit']) ?>" required>
                </div>
                <div class="mb-3">
                    <label for="package_volume" class="form-label">Volume Padrão da Embalagem</label>
                    <input type="number" step="0.01" class="form-control" id="package_volume" name="package_volume" value="<?= htmlspecialchars($product['package_volume']) ?>" required>
                </div>
                
                <div class="text-end mt-4">
                    <a href="gerir_produtos.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-success">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>