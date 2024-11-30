<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];

// Verifica se o ID do ativo foi passado na URL
if (isset($_GET['id'])) {
    $asset_id = $_GET['id'];

    // Consulta para obter os detalhes do ativo
    $stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $asset = $result->fetch_assoc();
    $stmt->close();

    // Consulta para obter a categoria do ativo
    if ($asset && $asset['category_id']) {
        $category_id = $asset['category_id'];
        $categories = [];

        // Função para buscar categorias recursivamente
        function getCategoryHierarchy($conn, $category_id) {
            $categories = [];
            while ($category_id) {
                $stmt = $conn->prepare("SELECT id, name, parent_id FROM categories WHERE id = ?");
                $stmt->bind_param("i", $category_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $category = $result->fetch_assoc();
                $stmt->close();
                if ($category) {
                    $categories[] = $category;
                    $category_id = $category['parent_id'];
                } else {
                    break;
                }
            }
            return array_reverse($categories);
        }

        $categories = getCategoryHierarchy($conn, $category_id);
    }
} else {
    // Redireciona para a lista de ativos se nenhum ID foi passado
    header("Location: list_assets.php");
    exit;
}
?>


<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Ativo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h2 class="mb-4">Detalhes do Ativo</h2>
        <div class="col-md-12 mt-3 mb-3">
            <a href="list_asset_orders.php?asset_id=<?= $asset_id; ?>" class="btn btn-warning">Ver Ordens de Trabalho deste Ativo</a>
            <a href="list_assets.php" class="btn btn-secondary">Voltar à Lista de Ativos</a>
        </div>
    <?php if ($asset): ?>
        <div class="row">
            <?php if (!empty($categories)): ?>
                <div class="col-md-12 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-sitemap"></i> Categoria</h5>
                            <p class="card-text">
                                <?php foreach ($categories as $category): ?>
                                    <?= htmlspecialchars($category['name']); ?><?php if (end($categories) !== $category): ?> > <?php endif; ?>
                                <?php endforeach; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Card para cada detalhe -->                
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-pen-to-square"></i> Nome do Ativo</h5>
                        <p class="card-text"><?= htmlspecialchars($asset['name']); ?></p>
                    </div>
                </div>
            </div>            
            <?php if ($asset['manual']): ?>
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-book"></i> Manual</h5>
                            <?php if ($asset['manual']): ?>
                                <a href="uploads/<?= htmlspecialchars($asset['manual']); ?>" target="_blank" class="btn btn-primary">Ver Manual</a>
                            <?php else: ?>
                                <button class="btn btn-primary" disabled data-bs-toggle="tooltip" title="Manual indisponível">Ver Manual</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($asset['features']): ?>
                <div class="col-md-12 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-list-ol"></i> Características</h5>
                            <ul>
                                <?php foreach (explode(',', $asset['features']) as $feature): ?>
                                    <li><?= htmlspecialchars($feature); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-md-12 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-pencil"></i> Descrição</h5>
                        <p class="card-text"><?= htmlspecialchars($asset['description']); ?></p>
                    </div>
                </div>
            </div>
            <?php if ($asset['photo']): ?>
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5 class="card-title"><i class="fas fa-camera-retro"></i> Foto do Ativo</h5>
                            <img src="uploads/<?= htmlspecialchars($asset['photo']); ?>" alt="Foto do Ativo" class="img-fluid rounded" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#photoModal">
                        </div>
                    </div>
                </div>

                <!-- Modal para ampliar a imagem -->
                <div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-body text-center">
                                <img src="uploads/<?= htmlspecialchars($asset['photo']); ?>" alt="Foto do Ativo" class="img-fluid rounded">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>               
            <div class="col-md-6 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-qrcode"></i> QR Code</h5>
                        <img src="uploads/qrcode_<?= htmlspecialchars($asset['id']); ?>.png" alt="QR Code" class="img-fluid rounded mb-3">
                        <p class="card-text">Escaneie para acessar informações adicionais.</p>
                    </div>
                </div>
            </div>
            <?php if ($asset['updated_at']): ?>
                <div class="col-md-12 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-recycle"></i> Última Atualização</h5>
                            <p class="card-text"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($asset['updated_at']))); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>            
        </div>        
    <?php else: ?>
        <div class="alert alert-danger">Ativo não encontrado!</div>
    <?php endif; ?>
</div>
<script>
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
