<?php
session_start();
include 'db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo '<p class="text-danger">Nenhum ativo selecionado.</p>';
    exit;
}

$asset_id = $_GET['id'];
$user_role = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'user';

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

// --- 3. BUSCAR ORDENS DE TRABALHO ASSOCIADAS ---
$stmt_wo = $conn->prepare("
    SELECT wo.id, wo.description, wo.status, wo.created_at, u.first_name, u.last_name
    FROM work_orders wo
    LEFT JOIN users u ON wo.assigned_user = u.id
    WHERE wo.asset_id = ? ORDER BY wo.created_at DESC
");
$stmt_wo->bind_param("i", $asset_id);
$stmt_wo->execute();
$work_orders = $stmt_wo->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_wo->close();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0 text-primary"><?= htmlspecialchars($asset['name']); ?></h3>
    <div>
        <a href="create_work_order.php?asset_id=<?= $asset['id']; ?>" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nova OT</a>
        <?php if ($user_role == 'admin'): ?>
            <a href="edit_asset.php?id=<?= $asset['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs" id="assetTab" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-pane" type="button">Informações</button></li>
    <?php if (!empty($custom_data)): ?>
        <li class="nav-item" role="presentation"><button class="nav-link" id="custom-tab" data-bs-toggle="tab" data-bs-target="#custom-pane" type="button">Características</button></li>
    <?php endif; ?>
    <li class="nav-item" role="presentation"><button class="nav-link" id="files-tab" data-bs-toggle="tab" data-bs-target="#files-pane" type="button">Ficheiros</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="wo-tab" data-bs-toggle="tab" data-bs-target="#wo-pane" type="button">Ordens de Trabalho <span class="badge bg-secondary"><?= count($work_orders) ?></span></button></li>
</ul>

<div class="tab-content p-3 border border-top-0 rounded-bottom" id="assetTabContent">
    
    <div class="tab-pane fade show active" id="info-pane">
        <div class="row">
            <div class="col-md-6">
                <h5>Dados de Identificação</h5>
                <table class="table table-sm">
                    <tr><th style="width: 40%;">Categoria</th><td><?= htmlspecialchars($asset['category_name']); ?></td></tr>
                    <tr><th>Fabricante</th><td><?= htmlspecialchars($asset['manufacturer']); ?></td></tr>
                    <tr><th>Modelo</th><td><?= htmlspecialchars($asset['model']); ?></td></tr>
                    <tr><th>Nº de Série</th><td><?= htmlspecialchars($asset['serial_number']); ?></td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h5>Dados de Aquisição</h5>
                <table class="table table-sm">
                    <tr><th style="width: 40%;">Fornecedor</th><td><?= htmlspecialchars($asset['supplier']); ?></td></tr>
                    <tr><th>Data de Compra</th><td><?= !empty($asset['purchase_date']) ? date('d/m/Y', strtotime($asset['purchase_date'])) : ''; ?></td></tr>
                    <tr><th>Fim da Garantia</th><td><?= !empty($asset['warranty_date']) ? date('d/m/Y', strtotime($asset['warranty_date'])) : ''; ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($custom_data)): ?>
    <div class="tab-pane fade" id="custom-pane">
        <h5>Características de "<?= htmlspecialchars($asset['asset_type_name']); ?>"</h5>
        <table class="table table-sm table-striped">
            <?php foreach($custom_data as $data): ?>
            <tr>
                <th style="width: 40%;"><?= htmlspecialchars($data['field_label']); ?></th>
                <td><?= htmlspecialchars($data['value']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="tab-pane fade" id="files-pane">
        <div class="row align-items-center">
            <div class="col-md-4 text-center">
                <h6>Foto do Ativo</h6>
                <?php if (!empty($asset['photo'])): ?>
                    <img src="<?= htmlspecialchars($asset['photo']); ?>" class="img-fluid img-thumbnail" alt="Foto do Ativo">
                <?php else: ?>
                    <p class="text-muted">Sem foto</p>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-center">
                <h6>Manual</h6>
                <?php if (!empty($asset['manual'])): ?>
                    <a href="<?= htmlspecialchars($asset['manual']); ?>" target="_blank" class="btn btn-outline-primary"><i class="fas fa-file-pdf"></i> Ver Manual</a>
                <?php else: ?>
                    <p class="text-muted">Sem manual</p>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-center">
                <h6>Código QR</h6>
                <?php if (!empty($asset['qrcode'])): ?>
                    <img src="<?= htmlspecialchars($asset['qrcode']); ?>" class="img-fluid" alt="Código QR">
                <?php else: ?>
                    <p class="text-muted">Sem código QR</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
	
	<div class="tab-pane fade" id="wo-pane" role="tabpanel">
        <h5>Ordens de Trabalho Associadas</h5>
        <?php if (count($work_orders) > 0): ?>
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Técnico</th>
                        <th>Data</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($work_orders as $wo): ?>
                    <tr>
                        <td><a href="view_work_order.php?id=<?= $wo['id'] ?>">#<?= $wo['id'] ?></a></td>
                        <td><?= htmlspecialchars($wo['description']) ?></td>
                        <td><?= htmlspecialchars($wo['first_name'] . ' ' . $wo['last_name']) ?></td>
                        <td><?= date('d/m/Y', strtotime($wo['created_at'])) ?></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars(ucfirst($wo['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted text-center mt-3">Nenhuma ordem de trabalho encontrada para este ativo.</p>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="wo-pane">
        </div>
</div>