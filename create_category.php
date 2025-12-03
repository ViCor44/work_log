<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];

$message = "";

// Função para buscar as categorias principais
function getMainCategories($conn) {
    $sql = "SELECT id, name FROM categories";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

$main_categories = getMainCategories($conn);


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Captura os dados do formulário
    $category_name = $_POST['category_name'];
    $parent_category = !empty($_POST['parent_category']) ? $_POST['parent_category'] : NULL;

    // Insere a nova categoria na tabela categories
    $stmt = $conn->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
    $stmt->bind_param("si", $category_name, $parent_category);
    if ($stmt->execute()) {
        $message = "Categoria criada com sucesso!";
    } else {
        $message = "Erro ao criar categoria: " . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Categoria</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <h2>Criar Categoria</h2>
    <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label for="category_name" class="form-label">Nome da Categoria</label>
            <input type="text" class="form-control" id="category_name" name="category_name" required>
        </div>
        <div class="mb-3">
            <label for="parent_category" class="form-label">Categoria Pai</label>
            <select class="form-control" id="parent_category" name="parent_category">
                <option value="">Nenhuma</option>
                <?php foreach ($main_categories as $category): ?>
                    <option value="<?= $category['id'] ?>"><?= $category['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>        
        <div class="d-flex">
            <a href="redirect_page.php" class="btn btn-secondary me-2">Voltar</a>
            <button type="submit" class="btn btn-primary">Criar Categoria</button>
        </div>
    </form>
</div>
<script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>
