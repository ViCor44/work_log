<?php
session_start();
require_once 'db.php'; // conexão com o banco

// Consulta para pegar categorias e seus ativos
$sql = "SELECT c.id AS category_id, c.name AS category_name, a.id AS asset_id, a.name AS asset_name
        FROM categories c
        LEFT JOIN assets a ON a.category_id = c.id
        ORDER BY c.name, a.name";
$result = $conn->query($sql) or die("Erro na consulta: " . $conn->error);

// Organizar em estrutura de árvore
$tree = [];

while ($row = $result->fetch_assoc()) {
    $cat_name = trim($row['category_name']);

    // Normaliza o nome (para evitar duplicados com espaços diferentes)
    if (!isset($tree[$cat_name])) {
        $tree[$cat_name] = [];
    }

    if ($row['asset_id']) {
        $tree[$cat_name][] = [
            'id' => $row['asset_id'],
            'name' => $row['asset_name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Árvore de Ativos</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .tree ul {
            list-style: none;
            padding-left: 1rem;
        }
        .tree-toggle {
            cursor: pointer;
        }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <h2 class="mb-4">Ativos por Categoria</h2>

        <div class="tree">
            <ul>
                <?php foreach ($tree as $category_name => $assets): ?>
                    <li>
                        <span class="tree-toggle fw-bold" data-bs-toggle="collapse" data-bs-target="#cat-<?= md5($category_name) ?>" aria-expanded="false">
                            ▶ <?= htmlspecialchars($category_name) ?>
                        </span>
                        <ul class="collapse" id="cat-<?= md5($category_name) ?>">
                            <?php foreach ($assets as $asset): ?>
                                <li>
                                    <a class="text-decoration-none text-primary" onclick="updateDetails(<?= $asset['id'] ?>)">
                                        🔧 <?= htmlspecialchars($asset['name']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <script src="/work_log/js/bootstrap.bundle.min.js"></script>
    <script>
    // Alterna o ícone ▶ / ▼
    document.querySelectorAll('.tree-toggle').forEach(el => {
        el.addEventListener('click', () => {
            const current = el.textContent.trim();
            const isClosed = current.startsWith('▶');
            const newIcon = isClosed ? '▼' : '▶';
            const label = current.slice(2).trim();
            el.textContent = newIcon + ' ' + label;
        });
    });
    </script>
    <script>
    // Função para atualizar o iframe de detalhes
    function updateDetails(assetId) {
        // Atualiza o src do iframe de detalhes na página principal
        parent.document.getElementById("detailsFrame").src = "view_asset.php?id=" + assetId;
    }
</script>



</body>
</html>
