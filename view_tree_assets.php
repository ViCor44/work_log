<?php
session_start();

include('db.php');
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Ativos - Árvore e Detalhes</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tree-container {
            height: 100vh;
            overflow-y: auto;
            border-right: 1px solid #ccc;
        }
        .content-container {
            height: 100vh;
            overflow-y: auto;
        }
        iframe {
            border: none;
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<h1 class="mb-4">Lista de Ativos</h1>
<div class="d-flex mb-3">
    <a href="create_asset.php" class="btn btn-primary me-3">Criar Novo Ativo</a>
    <a href="create_category.php" class="btn btn-primary me-3">Criar Nova Categoria</a>
    <a href="statistics.php" class="btn btn-primary me-3">Estatísticas</a>
    <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-3 tree-container">
            <!-- iframe da árvore de ativos, fixo -->
            <iframe id="treeFrame" src="tree_assets.php"></iframe>
        </div>
        <div class="col-md-9 content-container">
            <!-- iframe para os detalhes do ativo, com id dinâmico -->
            <iframe id="detailsFrame" src="view_asset.php?id=<?= htmlspecialchars($_GET['id']) ?>"></iframe>
        </div>
    </div>
</div>

<script>
    // Função para atualizar o iframe de detalhes
    function updateDetails(assetId) {
        // Atualiza o src do iframe de detalhes
        document.getElementById("detailsFrame").src = "view_asset.php?id=" + assetId;
    }
</script>

</body>
</html>