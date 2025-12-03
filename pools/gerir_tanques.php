<?php
// 1. INCLUIR O CABEÇALHO (que trata de tudo: sessão, bd, css, navbar)
// Usamos '../header.php' porque estamos dentro da pasta 'pools'
require_once '../header.php';

// 2. LÓGICA ESPECÍFICA DA PÁGINA (obter a lista de tanques)
$tanks_stmt = $conn->query("SELECT * FROM tanks ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Gestão de Tanques</h1>
        <div>
            <a href="form_tanque.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Novo Tanque
            </a>
            <a href="../redirect_page.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
        <div class="alert alert-success">Tanque guardado com sucesso!</div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Contagem Água</th>
                            <th>Usa Hipoclorito?</th>
                            <th>Requer Análises?</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tanks) > 0): ?>
                            <?php foreach ($tanks as $tank): ?>
                                <tr>
                                    <td><?= htmlspecialchars($tank['name']) ?></td>
                                    <td><?= ucfirst($tank['type']) ?></td>
                                    <td>
                                        <?php
                                        switch ($tank['water_reading_frequency']) {
                                            case 0: echo 'Não'; break;
                                            case 1: echo '1x por dia'; break;
                                            case 2: echo '2x por dia'; break;
                                        }
                                        ?>
                                    </td>
                                    <td><?= $tank['uses_hypochlorite'] ? 'Sim' : 'Não' ?></td>
                                    <td><?= $tank['requires_analysis'] ? 'Sim' : 'Não' ?></td>
                                    <td class="text-end">
                                        <td class="text-end">
										    <a href="form_tanque.php?id=<?= $tank['id'] ?>" class="btn btn-sm btn-warning">
										        <i class="fas fa-edit"></i> Editar
										    </a>
										    
										    <a href="excluir_tanque.php?id=<?= $tank['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Tem a certeza que deseja excluir o tanque \'<?= htmlspecialchars($tank['name']) ?>\'? Esta ação é irreversível e irá apagar todos os seus registos associados.');">
										        <i class="fas fa-trash-alt"></i> Excluir
										    </a>
										</td>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">Nenhum tanque registado.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// 4. INCLUIR O RODAPÉ (que fecha a página e carrega os scripts)
require_once '../footer.php';
?>