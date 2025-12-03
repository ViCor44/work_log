<?php
require_once '../header.php';
?>

<div class="container mt-4">
    <div class="text-center">
        <h1 class="h3 mb-2">Painel de Relatórios</h1>
        <p class="lead text-muted">Selecione o relatório que deseja consultar.</p>
    </div>
	<div>
        <a href="registos.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Voltar ao Menu Principal
        </a>
    </div>

    <hr class="my-4">

    <h5 class="mb-3">Relatórios de Consumo de Água</h5>
    <div class="row">
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="d-grid">
                <a href="relatorio_semanal_agua.php" class="btn btn-primary btn-lg p-3">Relatório Semanal Geral</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
             <div class="d-grid">
                <a href="list_rede.php" class="btn btn-info btn-lg p-3 text-white">Relatório da Rede</a>
            </div>
        </div>
		<div class="col-md-6 col-lg-3 mb-3">
             <div class="d-grid">
                <a href="list_agua_piscinas.php" class="btn btn-success btn-lg p-3 text-white">Relatório de Piscinas</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <div class="d-grid">
                <a href="list_agua_outros.php" class="btn btn-secondary btn-lg p-3">Relatório de Outros Tanques</a>
            </div>
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
             <div class="d-grid">
                <a href="list_edificio.php" class="btn btn-warning btn-lg p-3 text-dark">Relatório de Água Quente</a>
            </div>
        </div>
    </div>

    <hr class="my-4">

   <h5 class="mb-3">Relatórios de Análises e Produtos</h5>
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="d-grid">
                <a href="list_hipoclorito.php" class="btn btn-dark btn-lg p-3">Relatório de Hipoclorito</a>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="d-grid">
                <a href="relatorio_analises.php" class="btn btn-danger btn-lg p-3">Boletim de Análises</a>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="d-grid">
                <a href="compare_tanques.php" class="btn btn-success btn-lg p-3">
                    <i class="fas fa-balance-scale me-2"></i>Comparar Tanques
                </a>
            </div>
        </div>
    </div>

</div>

<?php
require_once '../footer.php';
?>