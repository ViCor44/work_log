<?php
require_once 'header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Ativo inválido.");
}
$asset_id = $_GET['id'];

// --- 1. BUSCAR DETALHES COMPLETOS DO ATIVO ---
$stmt_asset = $conn->prepare("
    SELECT a.*, c.name AS category_name, at.name AS asset_type_name
    FROM assets a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN asset_types at ON a.asset_type_id = at.id
    WHERE a.id = ?
");
$stmt_asset->bind_param("i", $asset_id);
$stmt_asset->execute();
$asset = $stmt_asset->get_result()->fetch_assoc();
$stmt_asset->close();

if (!$asset) {
    echo '<p class="text-danger">Ativo não encontrado.</p>';
    exit;
}

// --- 2. BUSCAR OS DADOS PERSONALIZADOS DO ATIVO ---
$stmt_custom = $conn->prepare("
    SELECT atf.field_label, acd.value
    FROM asset_custom_data acd
    JOIN asset_type_fields atf ON acd.field_id = atf.id
    WHERE acd.asset_id = ?
    ORDER BY atf.id
");
$stmt_custom->bind_param("i", $asset_id);
$stmt_custom->execute();
$custom_data = $stmt_custom->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_custom->close();

?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><?= htmlspecialchars($asset['name']) ?></h1>
        <a href="list_assets.php" class="btn btn-secondary">Voltar à Lista</a>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <img src="<?= htmlspecialchars($asset['photo'] ?: 'assets/placeholder.png'); ?>" class="card-img-top" alt="Foto do Ativo">
                <div class="card-body text-center">
                     <?php if (!empty($asset['qrcode'])): ?>
                        <img src="<?= htmlspecialchars($asset['qrcode']); ?>" style="width: 120px;" alt="Código QR">
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header"><h5 class="mb-0">Informações</h5></div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><th style="width: 30%;">Categoria</th><td><?= htmlspecialchars($asset['category_name']); ?></td></tr>
                        <tr><th>Fabricante</th><td><?= htmlspecialchars($asset['manufacturer']); ?></td></tr>
                        <tr><th>Modelo</th><td><?= htmlspecialchars($asset['model']); ?></td></tr>
                        <tr><th>Nº de Série</th><td><?= htmlspecialchars($asset['serial_number']); ?></td></tr>
                    </table>
                </div>
            </div>
            
            <?php if (!empty($custom_data)): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header"><h5 class="mb-0">Características de "<?= htmlspecialchars($asset['asset_type_name']); ?>"</h5></div>
                <div class="card-body">
                    <table class="table table-sm">
                        <?php foreach($custom_data as $data): ?>
                        <tr>
                            <th style="width: 30%;"><?= htmlspecialchars($data['field_label']); ?></th>
                            <td><?= htmlspecialchars($data['value']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

             <div class="d-grid gap-2">
                <?php if (!empty($asset['manual_path'])): ?>
                    <a href="<?= htmlspecialchars($asset['manual_path']); ?>" target="_blank" class="btn btn-outline-primary"><i class="fas fa-file-pdf"></i> Ver Manual</a>
                <?php endif; ?>
                <a href="create_work_order.php?asset_id=<?= $asset['id']; ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Criar Ordem de Trabalho</a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>