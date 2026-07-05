<?php
require_once '../header.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['error_message'] = "Acesso negado.";
    header("Location: ../index.php");
    exit;
}

// Últimos 500 envios.
$rows = [];
$res = $conn->query(
    "SELECT l.ts, l.to_number, l.message, l.status, l.response, l.tank_id, l.alarm_type, t.name AS tank_name
     FROM sms_log l
     LEFT JOIN tanks t ON t.id = l.tank_id
     ORDER BY l.id DESC
     LIMIT 500"
);
if ($res) { $rows = $res->fetch_all(MYSQLI_ASSOC); }

// Contadores rápidos (últimas 24h)
$stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
$res2 = $conn->query(
    "SELECT status, COUNT(*) AS n
     FROM sms_log
     WHERE ts >= DATE_SUB(NOW(), INTERVAL 1 DAY)
     GROUP BY status"
);
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        $stats[$r['status']] = (int)$r['n'];
    }
}
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Registo de SMS enviados</h1>
        <div>
            <a href="alarm_state.php" class="btn btn-outline-warning btn-sm">
                <i class="fas fa-bell me-1"></i>Estado de alarmes
            </a>
            <a href="test_sms.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-paper-plane me-1"></i>Enviar SMS de teste
            </a>
            <a href="../redirect_page.php" class="btn btn-secondary btn-sm">Voltar</a>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4"><div class="card text-bg-success"><div class="card-body py-2"><strong>Enviados (24h):</strong> <?= (int)$stats['sent'] ?></div></div></div>
        <div class="col-md-4"><div class="card text-bg-danger"><div class="card-body py-2"><strong>Falhas (24h):</strong> <?= (int)$stats['failed'] ?></div></div></div>
        <div class="col-md-4"><div class="card text-bg-secondary"><div class="card-body py-2"><strong>Sem destinatários (24h):</strong> <?= (int)$stats['skipped'] ?></div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-striped table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Destino</th>
                        <th>Estado</th>
                        <th>Tanque</th>
                        <th>Alarme</th>
                        <th>Mensagem</th>
                        <th>Resposta / Erro</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted">Nenhum SMS registado.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $badge = 'secondary';
                        if ($r['status'] === 'sent')    { $badge = 'success'; }
                        elseif ($r['status'] === 'failed') { $badge = 'danger'; }
                    ?>
                        <tr>
                            <td><?= date('d/m/Y H:i:s', strtotime($r['ts'])) ?></td>
                            <td><?= htmlspecialchars($r['to_number']) ?></td>
                            <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            <td><?= htmlspecialchars($r['tank_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['alarm_type'] ?? '-') ?></td>
                            <td><small><?= htmlspecialchars($r['message']) ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars(mb_strimwidth((string)$r['response'], 0, 220, '…')) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../footer.php'; ?>
