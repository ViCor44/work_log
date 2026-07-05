<?php
require_once '../header.php';
require_once dirname(__DIR__) . '/api/sms_alarm_notifier.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['error_message'] = "Acesso negado.";
    header("Location: ../index.php");
    exit;
}

$feedback = null;

// --- Ações ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'simulate') {
        $tankId   = (int)($_POST['tank_id'] ?? 0);
        $tankName = trim((string)($_POST['tank_name'] ?? ('tanque_' . $tankId)));
        $type     = trim((string)($_POST['alarm_type'] ?? 'controlador_interno'));

        // Forçar transição: apagar estado anterior para garantir edge OK->ALARME
        $stmt = $conn->prepare("DELETE FROM controller_alarm_state WHERE tank_id = ? AND alarm_type = ?");
        $stmt->bind_param('is', $tankId, $type);
        $stmt->execute();
        $stmt->close();

        // Construir payload falso — simula XML já convertido
        $fakeData = ['alarms' => []];
        if ($type === 'controlador_interno') {
            $fakeData['alarme'] = 0; // 0 = alarme ativo
        } else {
            $fakeData['alarms'][$type] = '1';
        }

        process_controller_alarms($conn, ['id' => $tankId, 'name' => $tankName], $fakeData);
        $feedback = ['type' => 'success', 'text' => 'Alarme simulado. Verifica o log abaixo.'];
    }
    elseif ($action === 'reset') {
        $tankId = (int)($_POST['tank_id'] ?? 0);
        $type   = trim((string)($_POST['alarm_type'] ?? ''));
        if ($tankId > 0 && $type !== '') {
            $stmt = $conn->prepare("DELETE FROM controller_alarm_state WHERE tank_id = ? AND alarm_type = ?");
            $stmt->bind_param('is', $tankId, $type);
            $stmt->execute();
            $stmt->close();
            $feedback = ['type' => 'info', 'text' => 'Estado limpo para esse alarme (próximo alarme será considerado nova transição).'];
        }
    }
    elseif ($action === 'reset_all') {
        $conn->query("TRUNCATE TABLE controller_alarm_state");
        $feedback = ['type' => 'warning', 'text' => 'Todo o estado de alarmes foi limpo.'];
    }
}

// --- Estado atual ---
$states = [];
$res = $conn->query(
    "SELECT cas.tank_id, t.name AS tank_name, cas.alarm_type, cas.is_active,
            cas.first_active_at, cas.last_seen_at, cas.last_sms_at, cas.last_cleared_at
     FROM controller_alarm_state cas
     LEFT JOIN tanks t ON t.id = cas.tank_id
     ORDER BY cas.is_active DESC, cas.last_seen_at DESC"
);
if ($res) { $states = $res->fetch_all(MYSQLI_ASSOC); }

// --- Últimos 15 SMS ---
$smsRecent = [];
$res = $conn->query(
    "SELECT ts, to_number, message, status, tank_id, alarm_type
     FROM sms_log
     ORDER BY id DESC
     LIMIT 15"
);
if ($res) { $smsRecent = $res->fetch_all(MYSQLI_ASSOC); }

// --- Lista de tanques com controlador (para o dropdown de simulação) ---
$tanks = [];
$res = $conn->query("SELECT id, name FROM tanks WHERE has_controller = 1 AND controller_ip IS NOT NULL AND controller_ip <> '' ORDER BY name");
if ($res) { $tanks = $res->fetch_all(MYSQLI_ASSOC); }

$alarmTypes = [
    'controlador_interno' => 'Alarme interno do controlador',
    'power_failure'       => 'Pane de corrente',
    'pneumatic_low'       => 'Pressão pneumática baixa',
    'pin_high'            => 'Pressão entrada filtro alta',
    'pout_high'           => 'Pressão saída filtro alta',
    'delta_p_high'        => 'Pressão diferencial alta',
    'pump1_fault'         => 'Falha Bomba 1',
    'pump2_fault'         => 'Falha Bomba 2',
];

// --- Destinatários atuais ---
$recipients = get_sms_recipients($conn);
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Estado de alarmes / SMS</h1>
        <div>
            <a href="sms_log.php" class="btn btn-outline-secondary btn-sm">Log completo</a>
            <a href="test_sms.php" class="btn btn-outline-secondary btn-sm">Enviar teste</a>
            <a href="../redirect_page.php" class="btn btn-secondary btn-sm">Voltar</a>
        </div>
    </div>

    <?php if ($feedback): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type']) ?>"><?= htmlspecialchars($feedback['text']) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Destinatários ativos (<?= count($recipients) ?>)</h6>
            <?php if (empty($recipients)): ?>
                <div class="text-danger small">
                    Nenhum utilizador com <code>receive_sms_alarms=1</code> e telefone preenchido.
                    Configura em <em>Gerir Utilizadores → Editar</em>.
                </div>
            <?php else: ?>
                <ul class="mb-0 small">
                    <?php foreach ($recipients as $r): ?>
                        <li>
                            <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
                            — <code><?= htmlspecialchars($r['phone']) ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Simular alarme (teste end-to-end)</h6>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="simulate">
                <div class="col-md-4">
                    <label class="form-label small">Tanque</label>
                    <select name="tank_id" class="form-select form-select-sm" required
                            onchange="document.getElementById('tank_name_field').value = this.options[this.selectedIndex].dataset.name || ''">
                        <option value="">— escolher —</option>
                        <?php foreach ($tanks as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" data-name="<?= htmlspecialchars($t['name']) ?>">
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="tank_name" id="tank_name_field" value="">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Tipo de alarme</label>
                    <select name="alarm_type" class="form-select form-select-sm">
                        <?php foreach ($alarmTypes as $k => $lbl): ?>
                            <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button class="btn btn-warning btn-sm" type="submit">
                        <i class="fas fa-bell me-1"></i>Simular
                    </button>
                    <small class="text-muted d-block">Vai limpar o estado prévio para garantir edge OK→ALARME e envia SMS aos destinatários.</small>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="card-title mb-0">Estado atual (<?= count($states) ?>)</h6>
                <form method="post" onsubmit="return confirm('Limpar TODO o estado de alarmes? Próximos alarmes serão considerados nova transição.')">
                    <input type="hidden" name="action" value="reset_all">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Limpar tudo</button>
                </form>
            </div>
            <?php if (empty($states)): ?>
                <div class="text-muted small">Ainda sem estado guardado. Aparecerá aqui após a primeira leitura do worker.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Tanque</th>
                                <th>Alarme</th>
                                <th>Ativo?</th>
                                <th>Início</th>
                                <th>Últ. visto</th>
                                <th>Últ. SMS</th>
                                <th>Últ. clear</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($states as $s): ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['tank_name'] ?? ('#' . $s['tank_id'])) ?></td>
                                    <td><code><?= htmlspecialchars($s['alarm_type']) ?></code></td>
                                    <td>
                                        <?php if ((int)$s['is_active'] === 1): ?>
                                            <span class="badge bg-danger">ATIVO</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars((string)$s['first_active_at']) ?></td>
                                    <td class="small"><?= htmlspecialchars((string)$s['last_seen_at']) ?></td>
                                    <td class="small"><?= htmlspecialchars((string)$s['last_sms_at']) ?></td>
                                    <td class="small"><?= htmlspecialchars((string)$s['last_cleared_at']) ?></td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="reset">
                                            <input type="hidden" name="tank_id" value="<?= (int)$s['tank_id'] ?>">
                                            <input type="hidden" name="alarm_type" value="<?= htmlspecialchars($s['alarm_type']) ?>">
                                            <button class="btn btn-link btn-sm p-0" type="submit" title="Limpar estado deste alarme">reset</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h6 class="card-title">Últimos 15 SMS</h6>
            <?php if (empty($smsRecent)): ?>
                <div class="text-muted small">Sem envios registados.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th>Quando</th><th>Para</th><th>Estado</th><th>Tanque</th><th>Alarme</th><th>Mensagem</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($smsRecent as $s): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars($s['ts']) ?></td>
                                    <td class="small"><code><?= htmlspecialchars($s['to_number']) ?></code></td>
                                    <td>
                                        <?php
                                            $badge = $s['status'] === 'sent' ? 'success' : ($s['status'] === 'failed' ? 'danger' : 'secondary');
                                        ?>
                                        <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($s['status']) ?></span>
                                    </td>
                                    <td class="small">#<?= (int)$s['tank_id'] ?></td>
                                    <td class="small"><?= htmlspecialchars((string)$s['alarm_type']) ?></td>
                                    <td class="small"><?= htmlspecialchars($s['message']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../footer.php'; ?>
