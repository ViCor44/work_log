<?php
require_once '../header.php';
?>

<style>
    /* ================================================= */
    /* ESTILOS PROFISSIONAIS - MÓDULO PISCINAS            */
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
        background-color: rgba(42, 63, 95, 0.95);
        border: 1px solid #2E4057;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        margin-bottom: 1.5rem;
    }

    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(74, 144, 226, 0.25);
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

    .btn-lg {
        padding: 1rem 1.5rem;
        font-size: 1.1rem;
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

    .btn-warning {
        background-color: #F1C40F;
        border-color: #F1C40F;
        color: #1E2A44;
    }
    .btn-warning:hover {
        background-color: #D4A017;
        border-color: #D4A017;
        color: #1E2A44;
    }

    .btn-success {
        background-color: #27AE60;
        border-color: #27AE60;
    }
    .btn-success:hover {
        background-color: #219653;
        border-color: #219653;
    }

    .btn-secondary {
        background-color: #6C757D;
        border-color: #6C757D;
    }
    .btn-secondary:hover {
        background-color: #5A6268;
        border-color: #5A6268;
    }

    .btn-dark {
        background-color: #2C3E50;
        border-color: #2C3E50;
    }
    .btn-dark:hover {
        background-color: #1A252F;
        border-color: #1A252F;
    }

    .d-grid .btn {
        width: 100%;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .container {
        max-width: 1400px;
    }

    .btn-secondary.back-btn {
        background-color: #34495E;
        border-color: #2E4057;
        font-size: 0.95rem;
    }
    .btn-secondary.back-btn:hover {
        background-color: #2C3E50;
        border-color: #1A252F;
    }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">Módulo de Piscinas e Tanques</h1>
        <a href="../redirect_page.php" class="btn btn-secondary back-btn">Voltar ao Início</a>
    </div>

    <!-- Entrada de Dados -->
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Entrada de Dados</h5>
            <p class="card-text text-muted">Selecione o tipo de registo que pretende inserir no sistema.</p>
            <div class="row">
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="d-grid">
                        <a href="form_analise.php" class="btn btn-primary btn-lg">Registar Análises</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="d-grid">
                        <a href="form_agua_manha.php" class="btn btn-info btn-lg text-white">Leituras Manhã</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="d-grid">
                        <a href="form_agua_tarde.php" class="btn btn-info btn-lg text-white">Leituras Tarde</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="d-grid">
                        <a href="form_hipoclorito.php" class="btn btn-warning btn-lg text-dark">Registar Hipoclorito</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gestão de Produtos e Stock -->
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Gestão de Produtos e Stock</h5>
            <p class="card-text text-muted">Crie novos produtos, registe compras ou consulte o histórico de consumos.</p>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="d-grid">
                        <a href="gerir_produtos.php" class="btn btn-primary btn-lg">Gerir Produtos</a>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-grid">
                        <a href="form_consumiveis.php" class="btn btn-primary btn-lg">Gerir Consumo de Produto</a>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-grid">
                        <a href="form_compra_produto.php" class="btn btn-success btn-lg">Registar Compra</a>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-grid">
                        <a href="list_consumiveis.php" class="btn btn-secondary btn-lg">Relatório de Stock</a>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-grid">
                        <a href="historico_consumiveis.php" class="btn btn-secondary btn-lg">Histórico de Consumos</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Análise e Monitorização -->
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Análise e Monitorização</h5>
            <p class="card-text text-muted">Consulte os relatórios históricos ou veja o estado dos controladores em tempo real.</p>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="d-grid">
                        <a href="menu_relatorios.php" class="btn btn-success btn-lg">Consultar Relatórios</a>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="d-grid">
                        <a href="dashboard.php" class="btn btn-dark btn-lg">Dashboard Tempo Real</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>