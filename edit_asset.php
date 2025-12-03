<?php
session_start();

// Verificar se o usuário está logado e é admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Verificar se o ID do ativo foi passado na URL
if (isset($_GET['id'])) {
    $asset_id = $_GET['id'];

    // Consultar os dados do ativo para preencher o formulário, incluindo características
    $stmt = $conn->prepare("SELECT name, description, photo, manual, features, category_id FROM assets WHERE id = ?");
    if (!$stmt) {
        die("Erro na consulta: " . $conn->error);
    }
    $stmt->bind_param("i", $asset_id);
    $stmt->execute();
    $stmt->bind_result($asset_name, $asset_description, $asset_photo, $asset_manual, $asset_features, $category_id);
    $stmt->fetch();
    $stmt->close();

    $asset_photo = "uploads/" . $asset_photo;
    $asset_manual = "uploads/" . $asset_manual;

    // Buscar categorias do banco de dados
    $categories = [];
    $stmt = $conn->prepare("SELECT id, name FROM categories");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmt->close();

    // Verificar se o formulário foi enviado
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $features = $_POST['features'];
        $category_id = $_POST['category_id']; // Captura a nova categoria

        // Upload da foto do ativo
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $photo_path = 'uploads/' . basename($_FILES['photo']['name']);
            move_uploaded_file($_FILES['photo']['tmp_name'], $photo_path);
        } else {
            $photo_path = $asset_photo;  // Manter a foto existente se não for carregada uma nova
        }

        // Upload do manual do ativo
        if (isset($_FILES['manual']) && $_FILES['manual']['error'] == 0) {
            $manual_path = 'uploads/' . basename($_FILES['manual']['name']);
            move_uploaded_file($_FILES['manual']['tmp_name'], $manual_path);
        } else {
            $manual_path = $asset_manual;  // Manter o manual existente se não for carregado um novo
        }

        // Atualizar os dados do ativo no banco de dados, incluindo características
        $stmt = $conn->prepare("UPDATE assets SET name = ?, description = ?, photo = ?, manual = ?, features = ?, category_id = ? WHERE id = ?");
        if (!$stmt) {
            die("Erro na atualização: " . $conn->error);
        }
        $stmt->bind_param("ssssssi", $name, $description, $photo_path, $manual_path, $features, $category_id, $asset_id);

        if ($stmt->execute()) {
            $message = "Ativo atualizado com sucesso!";
        } else {
            $message = "Erro ao atualizar o ativo: " . $stmt->error;
        }

        $stmt->close();
    }
} else {
    // Se não houver ID, redirecionar de volta para a lista de ativos
    header("Location: list_assets.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Ativo</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h1>Editar Ativo</h1>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="post" action="edit_asset.php?id=<?= $asset_id; ?>" enctype="multipart/form-data">
        <div class="mb-3">
            <label for="name" class="form-label">Nome do Ativo</label>
            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($asset_name); ?>" required>
        </div>
        <div class="mb-3">
            <label for="category_id" class="form-label">Categoria</label>
            <select class="form-control" id="category_id" name="category_id" required>
                <option value="">Selecione uma categoria</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= htmlspecialchars($category['id']); ?>" <?= ($category['id'] == $category_id) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label">Descrição do Ativo</label>
            <textarea class="form-control" id="description" name="description" rows="3" required><?= htmlspecialchars($asset_description); ?></textarea>
        </div>
        <div class="mb-3">
            <label for="features" class="form-label">Características</label>
            <textarea class="form-control" id="features" name="features" rows="3" required><?= htmlspecialchars($asset_features); ?></textarea>
        </div>        
        <div class="mb-3">
            <label for="photo" class="form-label">Foto do Ativo</label><br>
            <?php if ($asset_photo && file_exists($asset_photo)): ?>
                <img src="<?= htmlspecialchars($asset_photo); ?>" alt="Foto do Ativo" class="img-thumbnail mb-3" style="max-width: 150px;">
            <?php else: ?>
                <p>Nenhuma foto disponível</p>
            <?php endif; ?>
            <input type="file" class="form-control" id="photo" name="photo">
        </div>

        <div class="mb-3">
            <label for="manual" class="form-label">Manual do Ativo</label><br>
            <?php if ($asset_manual && file_exists($asset_manual)): ?>
                <a href="<?= htmlspecialchars($asset_manual); ?>" target="_blank">Visualizar Manual Atual</a>
            <?php else: ?>
                <p>Nenhum manual disponível</p>
            <?php endif; ?>
            <input type="file" class="form-control" id="manual" name="manual">
        </div>
        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
        <a href="list_assets.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
