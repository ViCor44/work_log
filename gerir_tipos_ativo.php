<?php
require_once 'header.php';

// Apenas administradores podem aceder
if ($_SESSION['user_type'] !== 'admin') {
    // Redireciona para o index se não for admin
    header("Location: ../index.php");
    exit;
}

// Busca todos os tipos de ativo existentes
$types_stmt = $conn->query("SELECT * FROM asset_types ORDER BY name ASC");
$asset_types = $types_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gerir Tipos de Ativo</h1>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#typeModal">
                <i class="fas fa-plus"></i> Novo Tipo de Ativo
            </button>
            <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Nome do Tipo</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($asset_types) > 0): ?>
                        <?php foreach($asset_types as $type): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($type['name']) ?></strong></td>
                            <td class="text-center">
                                <a href="gerir_campos_tipo.php?type_id=<?= $type['id'] ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-tasks"></i> Gerir Campos
                                </a>
                                </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="2" class="text-center text-muted">Nenhum tipo de ativo criado. Comece por criar um.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="typeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="guardar_tipo_ativo.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Novo Tipo de Ativo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_type">
                    <div class="mb-3">
                        <label for="type_name" class="form-label">Nome do Tipo</label>
                        <input type="text" class="form-control" id="type_name" name="type_name" placeholder="Ex: Motor Elétrico" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>