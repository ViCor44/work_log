<?php
// Inclui o header, que já carrega a navbar
require_once 'header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Dashboard de Controlo de Equipamentos Remoto</h2>
        <a href="redirect_page.php" class="btn btn-outline-secondary">Voltar ao Início</a>
    </div>

    <!-- A <iframe> que irá mostrar o nosso dashboard com o design correto -->
    <iframe src="scada_content.php" style="width: 100%; height: 80vh; border: none; border-radius: 8px;"></iframe>

</div>

<?php
// Inclui o footer
require_once 'footer.php';
?>
