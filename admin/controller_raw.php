<?php
require_once dirname(__DIR__) . '/core.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    $_SESSION['error_message'] = "Acesso negado.";
    header("Location: ../index.php");
    exit;
}

require_once '../header.php';

$tanks = [];
$res = $conn->query("SELECT id, name, controller_ip FROM tanks WHERE has_controller = 1 AND controller_ip IS NOT NULL AND controller_ip <> '' ORDER BY name");
if ($res) { $tanks = $res->fetch_all(MYSQLI_ASSOC); }

$selectedIp = trim((string)($_GET['ip'] ?? ''));
$selectedName = '';
foreach ($tanks as $t) {
    if ($t['controller_ip'] === $selectedIp) { $selectedName = $t['name']; break; }
}

$raw = null;
$parsed = null;
$httpCode = null;
$curlError = null;
$alarmesTags = [];
$alarmsSubtags = [];

if ($selectedIp !== '') {
    $url = 'http://' . $selectedIp;
    if (strpos($selectedIp, '192.') !== 0 && strpos($selectedIp, '191.') !== 0) {
        $url .= '/ajax_inputs';
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw !== false && $raw !== null) {
        // Tenta JSON
        $parsed = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Tenta XML
            if (strpos(trim((string)$raw), '<?xml') === 0) {
                try {
                    $xml = new SimpleXMLElement((string)$raw);
                    $parsed = json_decode(json_encode($xml), true);
                } catch (Exception $e) {
                    $parsed = ['__erro_xml' => $e->getMessage()];
                }
            }
        }

        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                if (stripos($k, 'alarm') !== false || stripos($k, 'alarme') !== false) {
                    $alarmesTags[$k] = $v;
                }
            }
            if (isset($parsed['alarms']) && is_array($parsed['alarms'])) {
                $alarmsSubtags = $parsed['alarms'];
            }
        }
    }
}
?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">XML crú do controlador</h1>
        <a href="alarm_state.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <form method="get" class="card mb-3">
        <div class="card-body row g-2 align-items-end">
            <div class="col-md-8">
                <label class="form-label small">Controlador (IP)</label>
                <select name="ip" class="form-select form-select-sm">
                    <option value="">— escolher —</option>
                    <?php foreach ($tanks as $t): ?>
                        <option value="<?= htmlspecialchars($t['controller_ip']) ?>" <?= $t['controller_ip'] === $selectedIp ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?> — <?= htmlspecialchars($t['controller_ip']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button class="btn btn-primary btn-sm" type="submit">Ir buscar XML agora</button>
            </div>
        </div>
    </form>

    <?php if ($selectedIp !== ''): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="small mb-2">
                    <strong><?= htmlspecialchars($selectedName ?: '(desconhecido)') ?></strong>
                    — IP <code><?= htmlspecialchars($selectedIp) ?></code>
                    — HTTP <?= (int)$httpCode ?>
                    <?php if ($curlError): ?><span class="text-danger">cURL: <?= htmlspecialchars($curlError) ?></span><?php endif; ?>
                </div>

                <h6>Tags que contêm "alarm/alarme"</h6>
                <?php if (empty($alarmesTags)): ?>
                    <div class="text-danger small mb-2">Nenhuma tag com "alarm/alarme" encontrada no payload. Provavelmente é por isso que o SMS não dispara.</div>
                <?php else: ?>
                    <pre class="bg-light p-2 border small mb-2" style="max-height:200px;overflow:auto"><?= htmlspecialchars(json_encode($alarmesTags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>

                <?php if (!empty($alarmsSubtags)): ?>
                    <h6>Subtags dentro de &lt;alarms&gt;</h6>
                    <pre class="bg-light p-2 border small mb-2" style="max-height:200px;overflow:auto"><?= htmlspecialchars(json_encode($alarmsSubtags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>

                <h6>Chaves de topo do payload</h6>
                <?php if (is_array($parsed)): ?>
                    <pre class="bg-light p-2 border small mb-2" style="max-height:150px;overflow:auto"><?= htmlspecialchars(json_encode(array_keys($parsed), JSON_PRETTY_PRINT)) ?></pre>
                <?php endif; ?>

                <h6>Resposta crua</h6>
                <pre class="bg-dark text-light p-2 small" style="max-height:400px;overflow:auto"><?= htmlspecialchars((string)$raw) ?></pre>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../footer.php'; ?>
