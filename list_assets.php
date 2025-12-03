<?php
// O header.php já deve tratar da sessão, conexão à BD e dados do utilizador.
require_once 'core.php';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Ativos</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/work_log/css/all.min.css">
    <style>
        .tree-view ul { list-style-type: none; padding-left: 20px; }
        .tree-view li { padding: 4px 0; }
        .tree-view .category-name { font-weight: bold; color: #0d6efd; }
        .tree-view .expandable { cursor: pointer; }
        .tree-view .expandable::before { content: "\f0da  "; font-family: 'Font Awesome 6 Free'; font-weight: 900; } /* Seta para a direita */
        .tree-view .expandable.expanded::before { content: "\f0d7  "; } /* Seta para baixo */
        .tree-view .asset-list { display: none; }
        .tree-view .asset-list.expanded { display: block; }
        .tree-view .asset-item { padding-left: 20px; color: #333; cursor: pointer; border-radius: 4px; padding: 5px; transition: background-color 0.2s; }
        .tree-view .asset-item:hover { background-color: #e9ecef; }
        .tree-view .asset-item.active { background-color: #0d6efd; color: white; }
        .asset-details { min-height: 400px; padding-top: 2rem; }
        .placeholder-glow { animation: placeholder-glow 2s ease-in-out infinite; }
    </style>
</head>
<body>

<?php include 'navbar.php'; // Caminho corrigido para consistência ?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gestão de Ativos</h1>
        <div>
            <a href="create_asset.php" class="btn btn-primary"><i class="fas fa-plus"></i> Novo Ativo</a>
            <a href="create_category.php" class="btn btn-info text-white"><i class="fas fa-sitemap"></i> Nova Categoria</a>
            <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Categorias e Ativos</h5>
                </div>
                <div class="card-body">
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="assetSearchInput" class="form-control" placeholder="Filtrar ativos...">
                    </div>
                    <div id="assetTree" class="tree-view">
                        </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body asset-details" id="assetDetails">
                    <div class="text-center text-muted p-5">
                        <i class="fas fa-arrow-left fa-3x mb-3"></i>
                        <h4>Selecione um ativo na árvore</h4>
                        <p>Os detalhes do ativo selecionado aparecerão aqui.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/work_log/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const assetTreeContainer = document.getElementById('assetTree');
    const assetDetailsContainer = document.getElementById('assetDetails');
    const searchInput = document.getElementById('assetSearchInput');
    let activeAssetItem = null;

    // Mostra um feedback de "a carregar"
    function showLoading(container, message) {
        container.innerHTML = `
            <div class="text-center text-muted p-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">${message}</p>
            </div>
        `;
    }

    // Carrega a árvore de ativos via fetch
    function loadAssetTree() {
        showLoading(assetTreeContainer, 'A carregar árvore...');
        fetch('get_asset_tree.php')
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(data => {
                assetTreeContainer.innerHTML = data;
            })
            .catch(error => {
                assetTreeContainer.innerHTML = '<div class="alert alert-danger">Erro ao carregar a árvore de ativos.</div>';
                console.error('Error loading asset tree:', error);
            });
    }

    // Carrega os detalhes de um ativo via fetch
    function loadAssetDetails(assetId) {
        showLoading(assetDetailsContainer, 'A carregar detalhes...');
        fetch('get_asset_details.php?id=' + assetId)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.text();
            })
            .then(data => {
                assetDetailsContainer.innerHTML = data;
            })
            .catch(error => {
                assetDetailsContainer.innerHTML = '<div class="alert alert-danger">Erro ao carregar os detalhes do ativo.</div>';
                console.error('Error loading asset details:', error);
            });
    }

    // "Escutador" de eventos para toda a área da árvore
    assetTreeContainer.addEventListener('click', function(event) {
        const target = event.target;
        
        // Clicar num item de ativo
        if (target.classList.contains('asset-item') && target.dataset.id) {
            if (activeAssetItem) {
                activeAssetItem.classList.remove('active');
            }
            target.classList.add('active');
            activeAssetItem = target;
            loadAssetDetails(target.dataset.id);
        } 
        // Clicar para expandir/colapsar uma categoria
        else if (target.classList.contains('expandable')) {
            const assetList = target.nextElementSibling;
            if (assetList && assetList.classList.contains('asset-list')) {
                assetList.classList.toggle('expanded');
                target.classList.toggle('expanded');
            }
        }
    });

    // "Escutador" de eventos para a pesquisa
    searchInput.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const assetItems = assetTreeContainer.querySelectorAll('.asset-item');
        
        assetItems.forEach(item => {
            const text = item.textContent.toLowerCase();
            if (text.includes(filter)) {
                item.style.display = ''; // Mostra
            } else {
                item.style.display = 'none'; // Esconde
            }
        });
    });

    // Carga inicial
    loadAssetTree();
});
</script>
</body>
</html>