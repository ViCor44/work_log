<?php
require_once 'header.php';

if (!isset($_GET['type_id']) || !is_numeric($_GET['type_id'])) {
    die("ID de Tipo de Ativo inválido.");
}
$type_id = $_GET['type_id'];

// Busca o nome do tipo para o título
$stmt_type = $conn->prepare("SELECT name FROM asset_types WHERE id = ?");
$stmt_type->bind_param("i", $type_id);
$stmt_type->execute();
$type = $stmt_type->get_result()->fetch_assoc();
if (!$type) { die("Tipo de Ativo não encontrado."); }
$stmt_type->close();

// Busca os campos existentes para este tipo
$stmt_fields = $conn->prepare("SELECT * FROM asset_type_fields WHERE asset_type_id = ? ORDER BY id ASC");
$stmt_fields->bind_param("i", $type_id);
$stmt_fields->execute();
$fields = $stmt_fields->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_fields->close();
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gerir Campos para: <span class="text-primary"><?= htmlspecialchars($type['name']) ?></span></h1>
        <a href="gerir_tipos_ativo.php" class="btn btn-secondary">Voltar</a>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0">Campos Existentes</h5></div>
                <div class="card-body">
                    <table class="table">
                        <thead><tr><th>Nome do Campo</th><th>Tipo</th><th>Ações</th></tr></thead>
                        <tbody>
                            <?php foreach($fields as $field): ?>
                            <tr>
                                <td><?= htmlspecialchars($field['field_label']) ?></td>
                                <td><span class="badge bg-secondary"><?= ucfirst($field['field_type']) ?></span></td>
                                <td>
                                    <a href="guardar_tipo_ativo.php?action=delete_field&id=<?= $field['id'] ?>&type_id=<?= $type_id ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem a certeza?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0">Adicionar Novo Campo</h5></div>
                <div class="card-body">
                    <form action="guardar_tipo_ativo.php" method="POST">
                        <input type="hidden" name="action" value="create_field">
                        <input type="hidden" name="type_id" value="<?= $type_id ?>">
                        <div class="mb-3">
                            <label for="field_label" class="form-label">Nome do Campo</label>
                            <input type="text" class="form-control" name="field_label" placeholder="Ex: Potência (kW)" required>
                        </div>
                        <div class="mb-3">
                            <label for="field_type" class="form-label">Tipo de Campo</label>
                            <select class="form-select" name="field_type" required>
                                <option value="text">Texto</option>
                                <option value="number">Número</option>
                                <option value="date">Data</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Adicionar Campo</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>