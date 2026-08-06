<?php
require_once '../header.php';
require_once '../api/alarm_log_lib.php';
ensure_alarm_event_log_table($conn);
$rows = $conn->query("SELECT l.*,t.name tank_name,CONCAT(u.first_name,' ',u.last_name) user_name FROM alarm_event_log l LEFT JOIN tanks t ON t.id=l.tank_id LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 500")->fetch_all(MYSQLI_ASSOC);
$eventLabels=['alarm_activated'=>'Alarme ativado','alarm_cleared'=>'Alarme normalizado','modal_shown'=>'Modal apresentado','modal_ignored'=>'Modal ignorado','modal_opened'=>'Controlador aberto','config_updated'=>'Configuração alterada'];
?>
<div class="container-fluid py-4 px-lg-5">
 <div class="d-flex justify-content-between align-items-start mb-4"><div><h1 class="h3 mb-1">Histórico de alarmes</h1><p class="text-muted mb-0">Últimos 500 eventos, incluindo ações efetuadas no modal.</p></div><a href="alarm_settings.php" class="btn btn-outline-secondary">Configuração</a></div>
 <div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
 <thead><tr><th>Data</th><th>Controlador</th><th>Alarme</th><th>Evento</th><th>Valor</th><th>Utilizador</th><th>Detalhes</th></tr></thead><tbody>
 <?php foreach($rows as $row): ?><tr><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i:s',strtotime($row['created_at']))) ?></td><td><?= htmlspecialchars($row['tank_name'] ?: '—') ?></td><td><?= htmlspecialchars($row['alarm_type']) ?></td><td><?= htmlspecialchars($eventLabels[$row['event_type']] ?? $row['event_type']) ?></td><td><?= $row['current_value']!==null ? htmlspecialchars(number_format((float)$row['current_value'],2,',','')) : '—' ?></td><td><?= htmlspecialchars(trim($row['user_name'] ?? '') ?: 'Sistema') ?></td><td><details><summary>Ver</summary><pre class="small mb-0" style="white-space:pre-wrap;max-width:32rem"><?= htmlspecialchars($row['details_json'] ?: '{}') ?></pre></details></td></tr><?php endforeach; ?>
 <?php if(!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Ainda não existem eventos registados.</td></tr><?php endif; ?>
 </tbody></table></div></div>
</div>
<?php require_once '../footer.php'; ?>
