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

// ── Filtros ──────────────────────────────────────────────────────────────
// Aceita 'YYYY-MM-DDTHH:MM' (datetime-local) ou 'YYYY-MM-DD HH:MM[:SS]'.
$normalizeDT = function (?string $raw): ?string {
    if ($raw === null) { return null; }
    $raw = trim($raw);
    if ($raw === '') { return null; }
    // datetime-local usa "T" — converte para espaço.
    $raw = str_replace('T', ' ', $raw);
    // Tenta várias formas.
    $ts = strtotime($raw);
    if ($ts === false) { return null; }
    return date('Y-m-d H:i:s', $ts);
};

$fromRaw = $_GET['from'] ?? '';
$toRaw   = $_GET['to']   ?? '';
$q       = trim((string)($_GET['q'] ?? ''));

$fromDT = $normalizeDT($fromRaw);
$toDT   = $normalizeDT($toRaw);
// Se o utilizador só indicou data (sem hora), estende o 'to' até ao fim do dia.
if ($toDT !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($toRaw))) {
    $toDT = substr($toDT, 0, 10) . ' 23:59:59';
}

// Constrói WHERE dinâmico e mantém arrays paralelos de types + valores.
$where  = [];
$types  = '';
$params = [];
if ($fromDT !== null) { $where[] = 'l.log_datetime >= ?'; $types .= 's'; $params[] = $fromDT; }
if ($toDT   !== null) { $where[] = 'l.log_datetime <= ?'; $types .= 's'; $params[] = $toDT;   }
if ($q !== '') {
    $where[] = '(l.action LIKE ? OR l.description LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Paginação ────────────────────────────────────────────────────────────
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Total de registos (com filtros aplicados)
$countSql = "SELECT COUNT(*) AS n FROM logs l LEFT JOIN users u ON l.user_id = u.id $whereSql";
$total = 0;
$stmtC = $conn->prepare($countSql);
if ($types !== '') { $stmtC->bind_param($types, ...$params); }
$stmtC->execute();
$resC = $stmtC->get_result()->fetch_assoc();
$total = $resC ? (int)$resC['n'] : 0;
$stmtC->close();

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

// Busca só a página atual, ordenada do mais recente para o mais antigo.
$sql = "
    SELECT l.log_datetime, l.action, l.description, u.first_name, u.last_name
    FROM logs l
    LEFT JOIN users u ON l.user_id = u.id
    $whereSql
    ORDER BY l.log_datetime DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$typesPage  = $types . 'ii';
$paramsPage = $params;
$paramsPage[] = $perPage;
$paramsPage[] = $offset;
$stmt->bind_param($typesPage, ...$paramsPage);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Valores devolvidos aos inputs (mantém o formato datetime-local).
$fromInput = $fromDT !== null ? date('Y-m-d\TH:i', strtotime($fromDT)) : '';
$toInput   = $toDT   !== null ? date('Y-m-d\TH:i', strtotime($toDT))   : '';

// Helper para gerar URL da página mantendo os filtros ativos.
$pageUrl = function (int $p) use ($fromInput, $toInput, $q): string {
    $qs = [];
    if ($fromInput !== '') { $qs['from'] = $fromInput; }
    if ($toInput   !== '') { $qs['to']   = $toInput; }
    if ($q         !== '') { $qs['q']    = $q; }
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

    <form method="get" class="card card-body shadow-sm mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-sm-6 col-md-3">
                <label for="f_from" class="form-label small mb-1">De</label>
                <input type="datetime-local" id="f_from" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($fromInput) ?>">
            </div>
            <div class="col-sm-6 col-md-3">
                <label for="f_to" class="form-label small mb-1">Até</label>
                <input type="datetime-local" id="f_to" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($toInput) ?>">
            </div>
            <div class="col-sm-8 col-md-4">
                <label for="f_q" class="form-label small mb-1">Pesquisar (ação, descrição, utilizador)</label>
                <input type="text" id="f_q" name="q" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($q) ?>" placeholder="Ex: SMS, login, osmose...">
            </div>
            <div class="col-sm-4 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filtrar</button>
                <a href="view_logs.php" class="btn btn-outline-secondary btn-sm" title="Limpar filtros">Limpar</a>
            </div>
        </div>
    </form>

    <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
        <div>
            <?= number_format($total, 0, ',', '.') ?> registos
            <?php if ($fromDT || $toDT || $q !== ''): ?>
                (filtrados)
            <?php else: ?>
                no total
            <?php endif; ?>
            — página <?= $page ?> de <?= $totalPages ?>
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