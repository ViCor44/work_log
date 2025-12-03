<?php
require_once '../header.php'; // Usamos ../ para subir um nível de diretoria

// ======================================================
// == PASSO DE SEGURANÇA: SÓ ADMINS PODEM VER ESTA PÁGINA ==
// ======================================================
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    // Se o utilizador não for admin, redireciona para a página inicial com uma mensagem de erro
    $_SESSION['error_message'] = "Acesso negado. Apenas administradores podem ver os logs do sistema.";
    header("Location: ../index.php");
    exit;
}

// Busca todos os logs, ordenados do mais recente para o mais antigo
// Fazemos um JOIN com a tabela de users para ir buscar o nome do utilizador
$logs_stmt = $conn->query("
    SELECT l.log_datetime, l.action, l.description, u.first_name, u.last_name
    FROM logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.log_datetime DESC
");
$logs = $logs_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Registo de Atividades do Sistema (Logs)</h1>
        <div>
            <a href="../redirect_page.php" class="btn btn-secondary">Voltar ao Início</a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th style="width: 20%;">Data e Hora</th>
                        <th style="width: 15%;">Utilizador</th>
                        <th style="width: 15%;">Ação</th>
                        <th>Descrição Detalhada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i:s', strtotime($log['log_datetime'])) ?></td>
                                <td>
                                    <?php 
                                    // Se o utilizador foi apagado, o nome não aparecerá, mas o log permanece
                                    echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); 
                                    ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                <td><?= htmlspecialchars($log['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">Nenhum registo de atividade encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>