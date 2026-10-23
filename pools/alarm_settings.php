<?php
require_once '../core.php';
require_once '../api/alarm_config_lib.php';
require_once '../api/alarm_log_lib.php';
require_once '../api/dosing_block_lib.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['dosing_config_csrf'])) {
    session_start();
    $_SESSION['dosing_config_csrf'] = bin2hex(random_bytes(32));
    session_write_close();
}
require_once '../header.php';
ensure_alarm_config_table($conn);
ensure_dosing_block_table($conn);

$tanks = $conn->query("SELECT id,name FROM tanks WHERE has_controller=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['config_action'] ?? '') === 'dosing') {
    $target = filter_var($_POST['tank_id'] ?? null, FILTER_VALIDATE_INT);
    $mode = $_POST['mode'] ?? '';
    $chlorineDelta = str_replace(',', '.', trim($_POST['chlorine_delta'] ?? ''));
    $phDelta = str_replace(',', '.', trim($_POST['ph_delta'] ?? ''));
    $validThresholds = is_numeric($chlorineDelta) && is_numeric($phDelta)
        && is_finite((float)$chlorineDelta) && is_finite((float)$phDelta)
        && (float)$chlorineDelta > 0 && (float)$phDelta > 0 && (float)$phDelta <= 14;
    if (!is_string($_POST['csrf_token'] ?? null)
        || !hash_equals($_SESSION['dosing_config_csrf'], $_POST['csrf_token'])) {
        $message = 'Token inválido. Atualize a página.';
    } elseif ($target === false || $target === null || ($target !== 0 && !in_array($target, array_map('intval', array_column($tanks, 'id')), true))
        || !in_array($mode, $target === 0 ? ['off', 'on'] : ['inherit', 'off', 'on'], true)
        || ($mode === 'on' && !$validThresholds)) {
        $message = 'Configuração inválida. Para ativar, indique variações positivas de cloro e pH (pH até 14).';
    } else {
        $locked = [];
        try {
            $ids = $target === 0 ? array_map('intval', array_column($tanks, 'id')) : [$target];
            sort($ids);
            foreach ($ids as $id) {
                dosing_lock($conn, $id);
                $locked[] = $id;
            }
            $conn->begin_transaction();
            $cDelta = $validThresholds ? (float)$chlorineDelta : null;
            $pDelta = $validThresholds ? (float)$phDelta : null;
            $stmt = $conn->prepare('INSERT INTO controller_dosing_block (tank_id,mode,chlorine_delta,ph_delta)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE mode=VALUES(mode),
                chlorine_delta=VALUES(chlorine_delta), ph_delta=VALUES(ph_delta),
                previous_chlorine=NULL, previous_ph=NULL');
            $stmt->bind_param('isdd', $target, $mode, $cDelta, $pDelta);
            if (!$stmt->execute()) {
                throw new RuntimeException('Falha ao guardar limites.');
            }
            $stmt->close();
            if ($target === 0 && !$conn->query("UPDATE controller_dosing_block
                SET previous_chlorine=NULL, previous_ph=NULL WHERE tank_id<>0 AND mode='inherit'")) {
                throw new RuntimeException('Falha ao atualizar as referências dos controladores.');
            }
            $conn->commit();
            if (!log_action($conn, (int)$_SESSION['user_id'], 'DOSING_BLOCK_CONFIG',
                "tank_id=$target | mode=$mode | chlorine_delta=$chlorineDelta | ph_delta=$phDelta")) {
                throw new RuntimeException('Configuração gravada, mas falhou o registo de auditoria.');
            }
            $message = 'Configuração de bloqueio automático guardada.';
        } catch (mysqli_sql_exception | RuntimeException $error) {
            $conn->rollback();
            error_log('Dosing configuration error: ' . $error->getMessage());
            $message = 'Erro na configuração de bloqueio: ' . $error->getMessage();
        } finally {
            foreach (array_reverse($locked) as $id) {
                dosing_unlock($conn, $id);
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['config_action'] ?? '') !== 'dosing') {
    $applyToAll = ($_POST['config_action'] ?? '') === 'all';
    $targetTankIds = $applyToAll
        ? array_map('intval', array_column($tanks, 'id'))
        : [(int)($_POST['tank_id'] ?? 0)];
    $cMin = (float)str_replace(',', '.', $_POST['chlorine_min'] ?? '1');
    $cMax = (float)str_replace(',', '.', $_POST['chlorine_max'] ?? '3');
    $pMin = (float)str_replace(',', '.', $_POST['ph_min'] ?? '7');
    $pMax = (float)str_replace(',', '.', $_POST['ph_max'] ?? '7.8');
    $modalCMax = (float)str_replace(',', '.', $_POST['modal_chlorine_max'] ?? '4');
    $modalPhMax = (float)str_replace(',', '.', $_POST['modal_ph_max'] ?? '8.2');
    $delay = max(0, min(1440, (int)($_POST['modal_delay_minutes'] ?? 5)));
    if ($targetTankIds && min($targetTankIds) > 0 && $cMin < $cMax && $pMin < $pMax && $modalCMax >= $cMax && $modalPhMax >= $pMax) {
        $modal = isset($_POST['modal_enabled']) ? 1 : 0;
        $sound = isset($_POST['sound_enabled']) ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO controller_alarm_config (tank_id,chlorine_min,chlorine_max,ph_min,ph_max,modal_chlorine_max,modal_ph_max,modal_delay_minutes,modal_enabled,sound_enabled) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE chlorine_min=VALUES(chlorine_min),chlorine_max=VALUES(chlorine_max),ph_min=VALUES(ph_min),ph_max=VALUES(ph_max),modal_chlorine_max=VALUES(modal_chlorine_max),modal_ph_max=VALUES(modal_ph_max),modal_delay_minutes=VALUES(modal_delay_minutes),modal_enabled=VALUES(modal_enabled),sound_enabled=VALUES(sound_enabled)");
        if (!$stmt) {
            error_log('Failed to prepare alarm configuration update: ' . $conn->error);
            $message = 'Não foi possível preparar a gravação da configuração.';
        } else {
            $conn->begin_transaction();
            $saved = true;
            foreach ($targetTankIds as $tankId) {
                $stmt->bind_param('iddddddiii', $tankId,$cMin,$cMax,$pMin,$pMax,$modalCMax,$modalPhMax,$delay,$modal,$sound);
                if (!$stmt->execute()) {
                    error_log('Failed to save alarm configuration for tank ' . $tankId . ': ' . $stmt->error);
                    $saved = false;
                    break;
                }
            }
            $stmt->close();
            if ($saved && $conn->commit()) {
                foreach ($targetTankIds as $tankId) {
                    log_alarm_event($conn, $tankId, (int)$_SESSION['user_id'], 'configuracao', 'config_updated', null, null,
                        ['chlorine_min'=>$cMin,'chlorine_max'=>$cMax,'ph_min'=>$pMin,'ph_max'=>$pMax,
                         'modal_chlorine_max'=>$modalCMax,'modal_ph_max'=>$modalPhMax,'modal_delay_minutes'=>$delay,
                         'modal_enabled'=>$modal,'sound_enabled'=>$sound]);
                }
                $message = $applyToAll
                    ? 'Configuração aplicada a todos os ' . count($targetTankIds) . ' controladores.'
                    : 'Configuração guardada.';
            } else {
                $conn->rollback();
                $message = 'Não foi possível guardar a configuração. Nenhum controlador foi alterado.';
            }
        }
    } else $message = 'Os mínimos têm de ser inferiores aos máximos e os limiares do modal não podem ser inferiores aos limites normais.';
}
$bulkConfig = $tanks ? get_alarm_config($conn, (int)$tanks[0]['id']) : default_alarm_config();
$dosingRows = dosing_block_rows($conn);
?>
<div class="container py-4">
 <div class="d-flex justify-content-between align-items-start mb-4"><div><h1 class="h3 mb-1">Configuração de alarmes</h1><p class="text-muted mb-0">Limites e comportamento do aviso global por controlador.</p></div><div class="d-flex gap-2"><a href="alarm_history.php" class="btn btn-outline-primary"><i class="fas fa-history me-1"></i>Histórico</a><a href="dashboard.php" class="btn btn-outline-secondary">Voltar ao dashboard</a></div></div>
 <?php if ($message): ?><div class="alert <?= str_contains($message,'guardada') || str_contains($message,'aplicada')?'alert-success':'alert-danger' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
 <?php $renderDosingForm = function (int $dosingId) use ($dosingRows) { ?>
  <div class="card-body border-top">
   <h2 class="h6">Bloqueio automático por discrepância entre leituras</h2>
   <p class="text-muted small">Se a variação absoluta de cloro ou pH exceder o limite, bloqueia a dosagem até Reset manual. Desligado por defeito; requer firmware compatível. A primeira leitura válida serve de referência. Desativar não remove bloqueios nem tentativas pendentes.</p>
   <?php
       $dosingCfg = $dosingRows[$dosingId] ?? ['mode'=>$dosingId === 0 ? 'off' : 'inherit','chlorine_delta'=>null,'ph_delta'=>null]; ?>
    <form method="post">
     <input type="hidden" name="config_action" value="dosing">
     <input type="hidden" name="tank_id" value="<?= $dosingId ?>">
     <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['dosing_config_csrf'], ENT_QUOTES, 'UTF-8') ?>">
     <div class="row g-2 align-items-end">
      <div class="col-12"><label class="form-label">Modo</label><select name="mode" class="form-select">
       <?php foreach (($dosingId === 0 ? ['off'=>'Desligado','on'=>'Ativado'] : ['inherit'=>'Usar configuração global','off'=>'Desligado','on'=>'Limites próprios']) as $value=>$label): ?>
        <option value="<?= $value ?>" <?= $dosingCfg['mode'] === $value ? 'selected' : '' ?>><?= $label ?></option>
       <?php endforeach; ?>
      </select></div>
      <div class="col-6"><label class="form-label">Variação máxima de cloro (mg/L)</label><input name="chlorine_delta" class="form-control" type="number" step="0.01" min="0.01" value="<?= htmlspecialchars((string)$dosingCfg['chlorine_delta']) ?>"></div>
      <div class="col-6"><label class="form-label">Variação máxima de pH</label><input name="ph_delta" class="form-control" type="number" step="0.01" min="0.01" max="14" value="<?= htmlspecialchars((string)$dosingCfg['ph_delta']) ?>"></div>
      <div class="col-12 text-end"><button class="btn btn-primary" type="submit">Guardar bloqueio automático</button></div>
     </div>
    </form>
  </div>
 <?php }; ?>
 <div class="row g-4">
 <?php if (!$tanks): ?><div class="col-12"><div class="alert alert-info">Não existem controladores configurados.</div></div><?php endif; ?>
 <?php if ($tanks): ?>
  <div class="col-12"><div class="card border-primary shadow-sm"><form method="post" onsubmit="return confirm('Esta ação vai substituir a configuração individual dos alarmes de todos os controladores. Continuar?');">
   <input type="hidden" name="config_action" value="all">
   <div class="card-header bg-primary text-white"><strong><i class="fas fa-clone me-2"></i>Aplicar configuração a todos os controladores</strong></div>
   <div class="card-body"><p class="text-muted">Os campos começam com os valores do primeiro controlador da lista. Ao guardar, todos os controladores receberão esta configuração, substituindo os valores individuais existentes.</p><div class="row g-3">
    <div class="col-6 col-lg-3"><label class="form-label">Cloro mínimo (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="chlorine_min" value="<?= htmlspecialchars($bulkConfig['chlorine_min']) ?>" required></div>
    <div class="col-6 col-lg-3"><label class="form-label">Cloro máximo (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="chlorine_max" value="<?= htmlspecialchars($bulkConfig['chlorine_max']) ?>" required></div>
    <div class="col-6 col-lg-3"><label class="form-label">pH mínimo</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="ph_min" value="<?= htmlspecialchars($bulkConfig['ph_min']) ?>" required></div>
    <div class="col-6 col-lg-3"><label class="form-label">pH máximo</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="ph_max" value="<?= htmlspecialchars($bulkConfig['ph_max']) ?>" required></div>
    <div class="col-12"><hr class="my-1"><div class="fw-semibold">Limiares críticos do modal</div></div>
    <div class="col-6 col-lg-3"><label class="form-label">Cloro máximo para modal (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="modal_chlorine_max" value="<?= htmlspecialchars($bulkConfig['modal_chlorine_max']) ?>" required></div>
    <div class="col-6 col-lg-3"><label class="form-label">pH máximo para modal</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="modal_ph_max" value="<?= htmlspecialchars($bulkConfig['modal_ph_max']) ?>" required></div>
    <div class="col-12 col-lg-6"><label class="form-label">Mostrar modal após alarme ativo durante</label><div class="input-group"><input class="form-control" type="number" min="0" max="1440" name="modal_delay_minutes" value="<?= (int)$bulkConfig['modal_delay_minutes'] ?>"><span class="input-group-text">minutos</span></div></div>
    <div class="col-12 d-flex gap-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="modal_enabled" id="modal-all" <?= $bulkConfig['modal_enabled']?'checked':'' ?>><label class="form-check-label" for="modal-all">Modal global</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="sound_enabled" id="sound-all" <?= $bulkConfig['sound_enabled']?'checked':'' ?>><label class="form-check-label" for="sound-all">Aviso sonoro</label></div></div>
   </div></div><div class="card-footer text-end"><button type="submit" class="btn btn-primary"><i class="fas fa-clone me-1"></i>Aplicar a todos</button></div>
  </form>
  <?php $renderDosingForm(0); ?>
  </div></div>
 <?php endif; ?>
 <?php foreach ($tanks as $tank): $cfg=get_alarm_config($conn,(int)$tank['id']); ?>
  <div class="col-12 col-xl-6"><div class="card shadow-sm h-100"><form method="post"><input type="hidden" name="config_action" value="single"><input type="hidden" name="tank_id" value="<?= (int)$tank['id'] ?>">
   <div class="card-header"><strong><i class="fas fa-swimming-pool me-2"></i><?= htmlspecialchars($tank['name']) ?></strong></div>
   <div class="card-body"><div class="row g-3">
    <div class="col-6"><label class="form-label">Cloro mínimo (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="chlorine_min" value="<?= htmlspecialchars($cfg['chlorine_min']) ?>" required></div>
    <div class="col-6"><label class="form-label">Cloro máximo (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="chlorine_max" value="<?= htmlspecialchars($cfg['chlorine_max']) ?>" required></div>
    <div class="col-6"><label class="form-label">pH mínimo</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="ph_min" value="<?= htmlspecialchars($cfg['ph_min']) ?>" required></div>
    <div class="col-6"><label class="form-label">pH máximo</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="ph_max" value="<?= htmlspecialchars($cfg['ph_max']) ?>" required></div>
    <div class="col-12"><hr class="my-1"><div class="fw-semibold">Limiares críticos do modal</div><div class="small text-muted">A indicação “fora dos limites” não abre o modal. O modal químico aparece apenas a partir destes valores.</div></div>
    <div class="col-6"><label class="form-label">Cloro máximo para modal (mg/L)</label><input class="form-control" type="number" step="0.01" min="0" name="modal_chlorine_max" value="<?= htmlspecialchars($cfg['modal_chlorine_max']) ?>" required></div>
    <div class="col-6"><label class="form-label">pH máximo para modal</label><input class="form-control" type="number" step="0.01" min="0" max="14" name="modal_ph_max" value="<?= htmlspecialchars($cfg['modal_ph_max']) ?>" required></div>
    <div class="col-12"><label class="form-label">Mostrar modal após alarme ativo durante</label><div class="input-group"><input class="form-control" type="number" min="0" max="1440" name="modal_delay_minutes" value="<?= (int)$cfg['modal_delay_minutes'] ?>"><span class="input-group-text">minutos</span></div></div>
    <div class="col-12 d-flex gap-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="modal_enabled" id="modal<?= (int)$tank['id'] ?>" <?= $cfg['modal_enabled']?'checked':'' ?>><label class="form-check-label" for="modal<?= (int)$tank['id'] ?>">Modal global</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="sound_enabled" id="sound<?= (int)$tank['id'] ?>" <?= $cfg['sound_enabled']?'checked':'' ?>><label class="form-check-label" for="sound<?= (int)$tank['id'] ?>">Aviso sonoro</label></div></div>
   </div></div><div class="card-footer text-end"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Guardar alterações</button></div>
  </form>
  <?php $renderDosingForm((int)$tank['id']); ?>
  </div></div>
 <?php endforeach; ?>
 </div>
</div>
<?php require_once '../footer.php'; ?>
