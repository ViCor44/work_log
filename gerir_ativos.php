<?php
require_once 'header.php'; // Inclui o header que já trata da sessão e BD

// Apenas administradores podem aceder a certas funcionalidades
$is_admin = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
?>

<style>
    /* ================================================= */
    /* ESTILOS PROFISSIONAIS - GESTÃO DE ATIVOS           */
    /* ================================================= */
	body {
        background-color: #1E2A44; /* Deep navy blue for a professional backdrop */
        color: #D3D8E0; /* Light gray text for readability */
        font-family: 'Arial', sans-serif;
    }

    .page-title {
        color: #ECF0F7;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        font-size: 1.8rem;
    }

    .card {
        background-color: #2A3F5F;
        border: 1px solid #2E4057;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        height: 100%;
    }

    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(74, 144, 226, 0.25);
    }

    .card-body {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
    }

    .card-title {
        color: #A9B7D0;
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
    }

    .card-text.text-muted {
        color: #8A9BA8 !important;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .btn {
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }

    .btn-primary {
        background-color: #4A90E2;
        border-color: #4A90E2;
        color: #fff;
    }
    .btn-primary:hover {
        background-color: #357ABD;
        border-color: #357ABD;
        transform: translateY(-2px);
    }

    .btn-info {
        background-color: #17a2b8;
        border-color: #17a2b8;
    }
    .btn-info:hover {
        background-color: #138496;
        border-color: #138496;
    }

    .btn-secondary {
        background-color: #6C757D;
        border-color: #6C757D;
    }
    .btn-secondary:hover {
        background-color: #5A6268;
        border-color: #5A6268;
    }

    .btn-success {
        background-color: #27AE60;
        border-color: #27AE60;
    }
    .btn-success:hover {
        background-color: #219653;
        border-color: #219653;
    }

    .dropdown-menu {
        background-color: #2A3F5F;
        border: 1px solid #2E4057;
    }

    .dropdown-item {
        color: #D3D8E0;
    }

    .dropdown-item:hover {
        background-color: #34495E;
        color: #ECF0F7;
    }

    .fa-3x {
        color: inherit;
    }

    .mt-auto .btn {
        width: 100%;
    }

    .dropdown-toggle::after {
        vertical-align: middle;
    }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">Gestão de Ativos</h1>
        <a href="redirect_page.php" class="btn btn-secondary">Voltar ao Início</a>
    </div>

    <div class="row">
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="mb-3">
                        <i class="fas fa-list-ul fa-3x text-primary"></i>
                    </div>
                    <h5 class="card-title">Listar Ativos</h5>
                    <p class="card-text text-muted">Ver, pesquisar e aceder aos detalhes de todos os ativos existentes.</p>
                    <div class="mt-auto">
                        <a href="list_assets.php" class="btn btn-primary stretched-link">Ver Lista</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="mb-3">
                        <i class="fas fa-cogs fa-3x text-info"></i>
                    </div>
                    <h5 class="card-title">Gerir Tipos de Ativo</h5>
                    <p class="card-text text-muted">Definir os tipos de ativos (ex: Motor, Bomba) e os seus campos personalizados.</p>
                    <div class="mt-auto">
                        <a href="gerir_tipos_ativo.php" class="btn btn-info stretched-link text-white">Gerir Tipos</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
		
		<?php if ($is_admin): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100">
                <div class="card-body text-center d-flex flex-column">
                    <div class="mb-3">
                        <i class="fas fa-sitemap fa-3x text-secondary"></i>
                    </div>
                    <h5 class="card-title">Gerir Categorias</h5>
                    <p class="card-text text-muted">Criar ou editar as categorias gerais para organizar os ativos.</p>
                    <div class="mt-auto">
                        <a href="create_category.php" class="btn btn-secondary stretched-link">Gerir Categorias</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-md-6 col-lg-4 mb-4">
		    <div class="card h-100">
		        <div class="card-body text-center d-flex flex-column">
		            <div class="mb-3">
		                <i class="fas fa-plus-circle fa-3x text-success"></i>
		            </div>
		            <h5 class="card-title">Criar Novo Ativo</h5>
		            <p class="card-text text-muted">Adicionar um novo equipamento, sensor LoRa ou Central de Medida.</p>
		            <div class="mt-auto">
		                <div class="dropdown">
		                    <button class="btn btn-success dropdown-toggle" type="button" id="dropdownCreateAsset" data-bs-toggle="dropdown" aria-expanded="false">
		                        Selecionar Tipo
		                    </button>
		                    <ul class="dropdown-menu" aria-labelledby="dropdownCreateAsset">
		                        <li><a class="dropdown-item" href="create_asset.php">Equipamento Padrão</a></li>
		                        <li><a class="dropdown-item" href="create_lora.php">Equipamento LoRaWAN</a></li>
		                        <li><a class="dropdown-item" href="create_medida.php">Central de Medida</a></li>
								<li><a class="dropdown-item" href="create_remote_equipment.php">Controlo de Equipamento Remoto</a></li>
		                    </ul>
		                </div>
		            </div>
		        </div>
		    </div>
		</div>
    </div>
</div>

<?php
require_once 'footer.php';
?>