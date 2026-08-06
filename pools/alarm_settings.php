<?php
require_once '../header.php';
require_once '../api/alarm_config_lib.php';
ensure_alarm_config_table($conn);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tankId = (int)($_POST['tank_id'] ?? 0);
    $cMin = (float)str_replace(',', '.', $_POST['chlorine_min'] ?? '1');
    $cMax = (float)str_replace(',', '.', $_POST['chlorine_max'] ?? '3');
    $pMin = (float)str_replace(',', '.', $_POST['ph_min'] ?? '7');
    $pMax = (float)str_replace(',', '.', $_POST['ph_max'] ?? '7.8');
    $modalCMax = (float)str_replace(',', '.', $_POST['modal_chlorine_max'] ?? '4');
    $modalPhMax = (float)str_replace(',', '.', $_POST['modal_ph_max'] ?? '8.2');
    $delay = max(0, min(1440, (int)($_POST['modal_delay_minutes'] ?? 5)));
    if ($tankId > 0 && $cMin < $cMax && $pMin < $pMax && $modalCMax >= $cMax && $modalPhMax >= $pMax) {
        $modal = isset($_POST['modal_enabled']) ? 1 : 0;
        $sound = isset($_POST['sound_enabled']) ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO controller_alarm_config (tank_id,chlorine_min,chlorine_max,ph_min,ph_max,modal_chlorine_max,modal_ph_max,modal_delay_minutes,modal_enabled,sound_enabled) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE chlorine_min=VALUES(chlorine_min),chlorine_max=VALUES(chlorine_max),ph_min=VALUES(ph_min),ph_max=VALUES(ph_max),modal_chlorine_max=VALUES(modal_chlorine_max),modal_ph_max=VALUES(modal_ph_max),modal_delay_minutes=VALUES(modal_delay_minutes),modal_enabled=VALUES(modal_enabled),sound_enabled=VALUES(sound_enabled)");
        $stmt->bind_param('idddddiiii', $tankId,$cMin,$cMax,$pMin,$pMax,$modalCMax,$modalPhMax,$delay,$modal,$sound);
        $stmt->execute(); $stmt->close();
        $message = 'Configuração guardada.';
    } else $message = 'Os mínimos têm de ser inferiores aos máximos e os limiares do modal não podem ser inferiores aos limites normais.';
}
$tanks = $conn->query("SELECT id,name FROM tanks WHERE has_controller=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="container py-4">
 <div class="d-flex justify-content-between align-items-start mb-4"><div><h1 class="h3 mb-1">Configuração de alarmes</h1><p class="text-muted mb-0">Limites e comportamento do aviso global por controlador.</p></div><a href="dashboard.php" class="btn btn-outline-secondary">Voltar ao dashboard</a></div>
 <?php if ($message): ?><div class="alert <?= str_contains($message,'guardada')?'alert-success':'alert-danger' ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
 <div class="row g-4">
 <?php if (!$tanks): ?><div class="col-12"><div class="alert alert-info">Não existem controladores configurados.</div></div><?php endif; ?>
 <?php foreach ($tanks as $tank): $cfg=get_alarm_config($conn,(int)$tank['id']); ?>
  <div class="col-12 col-xl-6"><form method="post" class="card shadow-sm h-100"><input type="hidden" name="tank_id" value="<?= (int)$tank['id'] ?>">
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
  </form></div>
 <?php endforeach; ?>
 </div>
</div>
<?php require_once '../footer.php'; ?>
