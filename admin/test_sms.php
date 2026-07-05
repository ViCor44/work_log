<?php
require_once '../header.php';
require_once dirname(__DIR__) . '/api/sms_client.php';
require_once dirname(__DIR__) . '/api/sms_alarm_notifier.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['error_message'] = "Acesso negado.";
    header("Location: ../index.php");
    exit;
}

$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to  = trim((string)($_POST['to'] ?? ''));
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($to === '' || $msg === '') {
        $feedback = ['type' => 'warning', 'text' => 'Preenche o número e a mensagem.'];
    } else {
        $client = new TeltonikaSmsClient();
        $res = $client->send($to, $msg);
        $status = $res['ok'] ? 'sent' : 'failed';
        $respTxt = $res['ok']
            ? (is_string($res['response']) ? $res['response'] : json_encode($res['response']))
            : ($res['error'] ?? '');
        log_sms($conn, $to, $msg, $status, $respTxt, null, 'teste_manual');
        $feedback = [
            'type' => $res['ok'] ? 'success' : 'danger',
            'text' => $res['ok'] ? 'SMS enviado com sucesso.' : 'Falha: ' . htmlspecialchars($res['error'] ?? 'erro desconhecido'),
        ];
    }
}

// Estado do token em cache
$client = $client ?? new TeltonikaSmsClient();
$tokenStatus = $client->getTokenStatus();
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">Enviar SMS de teste</h1>
        <div>
            <a href="sms_log.php" class="btn btn-outline-secondary btn-sm">Ver log</a>
            <a href="../redirect_page.php" class="btn btn-secondary btn-sm">Voltar</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Estado do modem</h6>
            <ul class="mb-0 small">
                <li>SMS_ENABLED: <strong><?= (defined('SMS_ENABLED') && SMS_ENABLED) ? 'sim' : 'não' ?></strong></li>
                <li>Modem: <code><?= htmlspecialchars((defined('MODEM_SCHEME') ? MODEM_SCHEME : 'http') . '://' . (defined('MODEM_HOST') ? MODEM_HOST : '?')) ?></code></li>
                <li>User: <code><?= htmlspecialchars(defined('MODEM_USER') ? MODEM_USER : '?') ?></code></li>
                <li>Password configurada: <strong><?= (defined('MODEM_PASS') && MODEM_PASS !== '') ? 'sim' : 'NÃO (edita config.php)' ?></strong></li>
                <li>Token em cache:
                    <?php if (!empty($tokenStatus['cached'])): ?>
                        sim — expira em <strong><?= (int)$tokenStatus['expires_in_sec'] ?> s</strong>
                    <?php else: ?>
                        não
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>

    <?php if ($feedback): ?>
        <div class="alert alert-<?= htmlspecialchars($feedback['type']) ?>"><?= $feedback['text'] ?></div>
    <?php endif; ?>

    <form method="post" class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Número (formato internacional, ex. <code>+351912345678</code>)</label>
                <input type="tel" class="form-control" name="to" required
                       value="<?= htmlspecialchars($_POST['to'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Mensagem</label>
                <textarea class="form-control" name="message" rows="3" maxlength="160" required><?= htmlspecialchars($_POST['message'] ?? 'Teste SMS a partir do CMMS.') ?></textarea>
                <small class="text-muted">Máx. 160 caracteres GSM-7. Evita acentos para não reduzir para 70.</small>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane me-1"></i>Enviar
            </button>
        </div>
    </form>
</div>

<?php require_once '../footer.php'; ?>
