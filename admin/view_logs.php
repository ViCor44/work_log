<?php
require_once dirname(__DIR__) . '/core.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
// ======================================================
// == PASSO DE SEGURANÇA: SÓ ADMINS PODEM VER ESTA PÁGINA ==
// ======================================================
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    // Se o utilizador não for admin, redireciona para a página inicial com uma mensagem de erro
    $_SESSION['error_message'] = "Acesso negado. Apenas administradores podem ver os logs do sistema.";
    header("Location: ../index.php");
    exit;
}

require_once '../header.php'; // Usamos ../ para subir um nível de diretoria

// ── Paginação ────────────────────────────────────────────────────────────
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Total de registos (para calcular nº de páginas)
$totalRow = $conn->query("SELECT COUNT(*) AS n FROM logs")->fetch_assoc();
$total = $totalRow ? (int)$totalRow['n'] : 0;
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// Busca só a página atual, ordenada do mais recente para o mais antigo.
$stmt = $conn->prepare("
    SELECT l.log_datetime, l.action, l.description, u.first_name, u.last_name
    FROM logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.log_datetime DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helper para gerar URL da página mantendo o path atual.
$pageUrl = function (int $p): string {
    $qs = $_GET;
    $qs['page'] = $p;
    return '?' . http_build_query($qs);
};
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Registo de Atividades do Sistema (Logs)</h1>
        <div>
            <a href="../redirect_page.php" class="btn btn-secondary">Voltar ao Início</a>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
        <div>
            <?= number_format($total, 0, ',', '.') ?> registos no total —
            página <?= $page ?> de <?= $totalPages ?>
            (a mostrar <?= count($logs) ?>)
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
                            <?php
                            // Melhora a legibilidade: separa ações compostas por " | " com uma linha em branco.
                            $formattedDescription = htmlspecialchars($log['description']);
                            $formattedDescription = str_replace(' | ', '<br><br>', $formattedDescription);
                            ?>
                            <tr>
                                <td><?= date('d/m/Y H:i:s', strtotime($log['log_datetime'])) ?></td>
                                <td>
                                    <?php 
                                    // Se o utilizador foi apagado, o nome não aparecerá, mas o log permanece
                                    echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); 
                                    ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                <td><?= $formattedDescription ?></td>
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

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Paginação dos logs" class="mt-3">
        <ul class="pagination pagination-sm justify-content-center flex-wrap">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page > 1 ? $pageUrl(1) : '#' ?>" aria-label="Primeira">&laquo;&laquo;</a>
            </li>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page > 1 ? $pageUrl($page - 1) : '#' ?>" aria-label="Anterior">&laquo;</a>
            </li>

            <?php
            // Mostra até 7 páginas à volta da atual, com "..." nas extremidades.
            $window = 3;
            $start = max(1, $page - $window);
            $end   = min($totalPages, $page + $window);
            if ($start > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= $pageUrl(1) ?>">1</a></li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $pageUrl($p) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page < $totalPages ? $pageUrl($page + 1) : '#' ?>" aria-label="Seguinte">&raquo;</a>
            </li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page < $totalPages ? $pageUrl($totalPages) : '#' ?>" aria-label="Última">&raquo;&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php
require_once '../footer.php';
?>