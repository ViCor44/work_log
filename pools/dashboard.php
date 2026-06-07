<?php
require_once '../header.php';

$localeSet = setlocale(LC_TIME, 'pt_PT.utf8', 'pt_PT.UTF-8', 'Portuguese_Portugal.1252');
if ($localeSet === false) {
    // Em ambientes onde a localidade não está instalada
    setlocale(LC_TIME, 'C');
}

function fetch_all_safe($conn, $sql) {
    $result = $conn->query($sql);
    if ($result === false) {
        error_log("DB query failed: " . $conn->error . " -- SQL: " . $sql);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Busca todas as piscinas que têm um controlador
$pools = fetch_all_safe($conn, "SELECT id, name, controller_ip FROM tanks WHERE type = 'piscina' AND has_controller = 1 ORDER BY name ASC");

// Busca todas as Centrais de Medida
$power_meters = fetch_all_safe($conn, "SELECT id, local, ip_address FROM centrais_de_medida ORDER BY local ASC");

// Busca filtros defender (entidade dedicada)
$filters = fetch_all_safe(
    $conn,
    "SELECT id, name, ip_address, slave_id
     FROM filter_equipment
     ORDER BY name ASC"
);
?>

<style>
	body {
            background-color: #1E2A44; /* Deep navy blue for a professional backdrop */
            color: #D3D8E0; /* Light gray text for readability */
            font-family: 'Arial', sans-serif;
    }
    :root {
        --scada-card-bg: #212529;
        --scada-section-bg: #343a40;
        --scada-border-color: #495057;
        --scada-text-primary: #dee2e6;
        --scada-text-secondary: #adb5bd;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 25px;
    }

    .scada-card {
        background-color: var(--scada-card-bg);
        border: 1px solid var(--scada-border-color);
        border-top-width: 4px;
        color: var(--scada-text-primary);
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    }
    .scada-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0,0,0,0.3);
    }
    .scada-card .card-header {
        background-color: transparent;
        border-bottom: 1px solid var(--scada-border-color);
    }
    .scada-card .list-group-item {
        background-color: var(--scada-section-bg);
        border-color: var(--scada-border-color);
        color: var(--scada-text-secondary);
    }
    .scada-card .font-monospace {
        color: var(--scada-text-primary);
    }
    /* ALTERAÇÃO: Aumentado o tamanho da fonte da unidade */
    .scada-card .unit {
        font-size: 0.9rem; 
        color: var(--scada-text-secondary);
        margin-left: 4px;
    }
    
    .alarm-content { display: none; }
    .scada-card.status-alarm .list-group, .scada-card.status-alarm .card-footer { display: none; }
    .scada-card.status-alarm .alarm-content { display: block; }
    
    .scada-card a { color: inherit; text-decoration: none; }

    @keyframes pulse-red-bs { 
        0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); } 
        50% { box-shadow: 0 0 0 6px rgba(220, 53, 69, 0); } 
    }
    .animate-pulse-red-bs { animation: pulse-red-bs 2s infinite; }

    .nav-link.tab-alert {
        background-color: #dc3545 !important;
        color: white !important;
        font-weight: bold;
    }

    /* OFFLINE (erro comunicação) */
    .scada-card.status-offline .list-group {
        display: none;
    }

    .scada-card.status-offline .alarm-content {
        display: block;
    }

    /* Cards de filtros */
    .filtro-metrics {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0;
    }
    .filtro-metric {
        padding: 14px 10px;
        text-align: center;
        border-right: 1px solid var(--scada-border-color);
    }
    .filtro-metric:last-child { border-right: none; }
    .filtro-state-panel {
        padding: 12px 16px 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.04);
    }
    .filtro-state-panel .metric-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--scada-text-secondary);
        margin-bottom: 8px;
    }
    .filtro-state-panel .metric-value {
        font-size: 1.8rem;
        font-weight: 700;
        font-family: monospace;
        line-height: 1;
    }
    .filtro-metric .metric-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--scada-text-secondary);
        margin-bottom: 6px;
    }
    .filtro-metric .metric-value {
        font-size: 1.5rem;
        font-weight: 700;
        font-family: monospace;
        color: var(--scada-text-primary);
        line-height: 1;
    }
    .filtro-metric .metric-unit {
        font-size: 0.72rem;
        color: var(--scada-text-secondary);
        margin-top: 3px;
    }
    .filtro-metric.pin   .metric-value { color: #5bc8f5; }
    .filtro-metric.pout  .metric-value { color: #6ee0a0; }
    .filtro-metric.delta .metric-value { color: #f5a623; }
    .filtro-state-panel .metric-value.parado { color: #dc3545 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.precoat { color: #0d6efd !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.filtracao { color: #198754 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.interrompido { color: #ffc107 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.enchimento { color: #0dcaf0 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.bump { color: #6f42c1 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.arrancando { color: #20c997 !important; white-space: nowrap; }
    .filtro-state-panel .metric-value.inativo { color: var(--scada-text-secondary) !important; white-space: nowrap; }
    /* Painel de bombas */
    .filtro-pump-panel {
        display: flex;
        justify-content: space-around;
        padding: 10px 16px;
        border-top: 1px solid var(--scada-border-color);
    }
    .filtro-pump {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.8rem;
        color: var(--scada-text-secondary);
    }
    .pump-dot { font-size: 1.2rem; line-height: 1; }
    .pump-dot.pump-on  { color: #198754; }
    .pump-dot.pump-off { color: #495057; }
    /* Linha de info BUMP */
    .filtro-bump-info {
        padding: 6px 14px;
        background: rgba(111,66,193,0.15);
        border-top: 1px solid rgba(111,66,193,0.4);
        font-size: 0.78rem;
        color: #bf97f7;
        text-align: center;
        display: none;
    }
    .filtro-footer {
        background-color: var(--scada-section-bg);
        border-top: 1px solid var(--scada-border-color);
        font-size: 0.75rem;
        color: var(--scada-text-secondary);
        padding: 6px 14px;
    }
</style>


<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">Painel de Monitorização</h1>
        <div class="d-flex gap-2">
            <button type="button" id="btnGlobalHaToggle" class="btn btn-outline-warning" data-state="off" title="Liga/desliga Alta Afluência em todos os tanques com SP dinâmico ativo.">
                🏊 Alta afluência GLOBAL: <span id="globalHaState">OFF</span>
            </button>
            <a href="plano_pid.php?days=7" class="btn btn-warning">
                <i class="fas fa-file-pdf me-1"></i>Plano PID (ver e aceitar)
            </a>
            <a href="../redirect_page.php" class="btn btn-secondary">Voltar ao Início</a>
        </div>
    </div>

    <ul class="nav nav-tabs" id="dashboardTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="piscinas-tab" data-bs-toggle="tab" data-bs-target="#piscinas-pane" type="button" role="tab">
                <i class="fas fa-swimming-pool me-2"></i>Controladores das Piscinas
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="lora-tab" data-bs-toggle="tab" data-bs-target="#lora-pane" type="button" role="tab">
                <i class="fas fa-broadcast-tower me-2"></i>Equipamentos LoRa
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="medida-tab" data-bs-toggle="tab" data-bs-target="#medida-pane" type="button">
                <i class="fas fa-tachometer-alt me-2"></i>Centrais de Medida
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="filtros-tab" data-bs-toggle="tab" data-bs-target="#filtros-pane" type="button" role="tab">
                <i class="fas fa-filter me-2"></i>Filtros
            </button>
        </li>
    </ul>

    <div class="tab-content pt-4" id="dashboardTabsContent">
        
        <div class="tab-pane fade show active" id="piscinas-pane" role="tabpanel">
            <div class="dashboard-grid" id="dashboard-container-piscinas">
                <?php foreach ($pools as $pool): ?>
                    <a href="view_pool_details.php?id=<?= $pool['id'] ?>" class="text-decoration-none">
                        <div id="card-piscina-<?= $pool['id'] ?>" class="card scada-card h-100 border-secondary shadow-sm" data-type="piscina" data-ip="<?= $pool['controller_ip'] ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0 fw-bold"><?= htmlspecialchars($pool['name']) ?></h5>
                                <span id="status-piscina-<?= $pool['id'] ?>" class="badge bg-secondary">Aguardando...</span>
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50"><i class="fas fa-life-ring text-info"></i> Cloro Livre</span>
                                    <span id="cloro-<?= $pool['id'] ?>" class="font-monospace fw-bold fs-5"><i class="fas fa-spinner fa-spin fa-xs"></i></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50"><i class="fas fa-tint text-info"></i> pH</span>
                                    <span id="ph-<?= $pool['id'] ?>" class="font-monospace fw-bold fs-5"><i class="fas fa-spinner fa-spin fa-xs"></i></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50"><i class="fas fa-thermometer-half text-info"></i> Temperatura</span>
                                    <span id="temp-<?= $pool['id'] ?>" class="font-monospace fw-bold fs-5"><i class="fas fa-spinner fa-spin fa-xs"></i></span>
                                </li>
                            </ul>
                                <div class="card-body text-center alarm-content">
                                    <img src="../images/rj45.png" style="width:64px;height:64px;" alt="Erro de Comunicação">
                                    <div class="fw-bold mt-2">Erro de Comunicação</div>
                                </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="tab-pane fade" id="lora-pane" role="tabpanel">
            <div class="dashboard-grid" id="dashboard-container-lora">
                <div class="text-center p-4 text-muted"><i class="fas fa-spinner fa-spin"></i> A carregar estado da rede...</div>
            </div>
        </div>

        <div class="tab-pane fade" id="medida-pane" role="tabpanel">
            <div class="dashboard-grid" id="dashboard-container-medida">
                <?php foreach ($power_meters as $meter): ?>
                    <a href="view_meter_details.php?id=<?= $meter['id'] ?>" class="text-decoration-none">
                        <div id="card-medida-<?= $meter['id'] ?>" class="card scada-card h-100 border-secondary shadow-sm" data-type="medida" data-ip="<?= $meter['ip_address'] ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0 fw-bold"><?= htmlspecialchars($meter['local']) ?></h5>
                                <span id="status-medida-<?= $meter['id'] ?>" class="badge bg-secondary">Aguardando...</span>
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50">Tensão</span>
                                    <span id="voltage-<?= $meter['id'] ?>" class="font-monospace fw-bold fs-5">--</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50">Corrente</span>
                                    <span id="current-<?= $meter['id'] ?>" class="font-monospace fw-bold fs-5">--</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span class="text-white-50">Potência Ativa</span>
                                    <span id="power-<?= $meter['id'] ?>" class="font-monospace fw-bold fs-5">--</span>
                                </li>
                            </ul>
                            <div class="card-body text-center alarm-content">
                                <img src="../images/rj45.png" style="width: 64px; height: 64px;" alt="Erro de Comunicação">
                                <div class="fw-bold mt-2">Erro de Comunicação</div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="filtros-pane" role="tabpanel">
            <div class="dashboard-grid" id="dashboard-container-filtros">
                <?php if (!empty($filters)): ?>
                    <?php foreach ($filters as $filter): ?>
                        <div id="card-filtro-<?= $filter['id'] ?>" class="card scada-card h-100 border-secondary shadow-sm" style="cursor:pointer" data-type="filtro" data-ip="<?= htmlspecialchars($filter['ip_address']) ?>" data-slave-id="<?= (int)$filter['slave_id'] ?>" data-filter-id="<?= $filter['id'] ?>" onclick="openFiltroModal(<?= $filter['id'] ?>)">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-filter me-2 opacity-75"></i><?= htmlspecialchars($filter['name']) ?>
                                </h5>
                                <span id="status-filtro-<?= $filter['id'] ?>" class="badge bg-secondary">Aguardando...</span>
                            </div>
                            <div class="filtro-metrics">
                                <div class="filtro-metric pin">
                                    <div class="metric-label">P in</div>
                                    <div class="metric-value" id="filtro-pin-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                                <div class="filtro-metric pout">
                                    <div class="metric-label">P out</div>
                                    <div class="metric-value" id="filtro-pout-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                                <div class="filtro-metric delta">
                                    <div class="metric-label">&Delta;P</div>
                                    <div class="metric-value" id="filtro-delta-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                            </div>
                            <div class="filtro-state-panel d-flex justify-content-between align-items-end">
                                <div>
                                    <div class="metric-label">Estado</div>
                                    <div class="metric-value" id="filtro-pump-state-<?= $filter['id'] ?>">--</div>
                                </div>
                                <div class="text-end">
                                    <div class="metric-label">Caudal</div>
                                    <div id="filtro-flow-<?= $filter['id'] ?>" style="font-size:1.3rem;font-weight:700;font-family:monospace;color:#0dcaf0">--</div>
                                </div>
                            </div>
                            <div class="filtro-pump-panel">
                                <div class="filtro-pump">
                                    <span class="pump-dot pump-off" id="filtro-pump1-<?= $filter['id'] ?>">●</span>
                                    <span>Bomba 1</span>
                                    <span id="filtro-pump1-badge-<?= $filter['id'] ?>" class="badge bg-secondary" style="font-size:0.7rem">--</span>
                                </div>
                                <div class="filtro-pump">
                                    <span class="pump-dot pump-off" id="filtro-pump2-<?= $filter['id'] ?>">●</span>
                                    <span>Bomba 2</span>
                                    <span id="filtro-pump2-badge-<?= $filter['id'] ?>" class="badge bg-secondary" style="font-size:0.7rem">--</span>
                                </div>
                            </div>
                            <div class="filtro-bump-info" id="filtro-bump-info-<?= $filter['id'] ?>">
                                <i class="fas fa-redo-alt me-1"></i><strong>BUMP</strong> &mdash; Contra-lavagem em curso
                                <span id="filtro-bump-cycle-<?= $filter['id'] ?>" class="ms-2" style="opacity:0.8"></span>
                            </div>
                            <div class="filtro-footer d-flex justify-content-between">
                                <span><i class="fas fa-network-wired me-1"></i><?= htmlspecialchars($filter['ip_address']) ?></span>
                                <span>Slave <?= (int)$filter['slave_id'] ?></span>
                            </div>
                            <div class="card-body text-center alarm-content" style="display:none;">
                                <img src="../images/rj45.png" style="width: 64px; height: 64px;" alt="Erro de Comunicação">
                                <div class="fw-bold mt-2">Erro de Comunicação</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <p class="text-muted text-center">Nenhum ativo de filtro configurado.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal detalhe Filtro Defender -->
<div class="modal fade" id="filtroDetailModal" tabindex="-1" aria-labelledby="filtroDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background:#212529;color:#dee2e6;border:1px solid #495057">
            <div class="modal-header" style="border-bottom:1px solid #495057">
                <div>
                    <h5 class="modal-title mb-0" id="filtroDetailModalLabel">Filtro Defender</h5>
                    <div id="filtroModalSubtitle" class="text-secondary" style="font-size:0.8rem"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="filtroModalBody">
                <div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #495057">
                <span class="text-secondary me-auto" style="font-size:0.75rem" id="filtroModalTs"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
// ── Funções globais do painel de filtros (chamadas por onclick) ───────────────
const STATE_CSS = {
    'Em Filtração':        'filtracao',
    'Pré-coat':            'precoat',
    'Interrompido':        'interrompido',
    'Enchimento/Drenagem': 'enchimento',
    'Bump':                'bump',
    'Bomba a Arrancar':    'arrancando',
    'Parado':              'parado',
    'Inativo':             'inativo',
};

function fmtVal(v, dec = 2) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n.toFixed(dec) : '--';
}
function fmtDateTime(v) {
    if (!v) return '--';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '--';
    return d.toLocaleString('pt-PT');
}

function getPerliteDateLabel(data) {
    if (data.last_perlite_change_at) {
        return fmtDateTime(data.last_perlite_change_at);
    }
    if (data.estimated_perlite_change_at) {
        return fmtDateTime(data.estimated_perlite_change_at) + ' (estimada)';
    }
    return '--';
}

function lsIndicator(open, closed) {
    if (open)   return '<span class="badge bg-success">Aberta</span>';
    if (closed) return '<span class="badge bg-secondary">Fechada</span>';
    return '<span class="badge bg-warning text-dark">Indeterminado</span>';
}

function pumpStatusBadge(running, fault) {
    if (fault)   return '<span class="badge bg-danger">FALHA</span>';
    if (running) return '<span class="badge bg-success">Em Serviço</span>';
    return '<span class="badge bg-secondary">Parada</span>';
}

function buildServiceMessagesSection(data) {
    const sb = data.status_bits || {};
    const msgs = [];
    if (sb.filter_interruption) msgs.push({icon:'fa-pause-circle',   color:'#ffc107', text:'Filtro interrompido'});
    if (sb.filter_precoat)      msgs.push({icon:'fa-layer-group',    color:'#0dcaf0', text:'Pré-coat ativo'});
    if (sb.filter_fill_drain)   msgs.push({icon:'fa-fill-drip',      color:'#0dcaf0', text:'Enchimento/Drenagem em curso'});
    if (sb.filter_bump)         msgs.push({icon:'fa-redo-alt',       color:'#bf97f7', text:'Bump (contra-lavagem) em curso'});
    if (sb.pump1_start)         msgs.push({icon:'fa-bolt',           color:'#6ee0a0', text:'Sinal de arranque VFD – Bomba 1'});
    if (sb.pump2_start)         msgs.push({icon:'fa-bolt',           color:'#6ee0a0', text:'Sinal de arranque VFD – Bomba 2'});
    if (data.network_heartbeat) msgs.push({icon:'fa-heartbeat',      color:'#6ee0a0', text:'Heartbeat de rede ativo'});

    if (!msgs.length) return '';
    const rows = msgs.map(m =>
        `<div class="d-flex align-items-center gap-2 mb-1">
            <i class="fas ${m.icon}" style="color:${m.color};width:16px;text-align:center"></i>
            <span style="font-size:0.85rem">${m.text}</span>
        </div>`).join('');
    return `<div class="p-3 rounded mt-3" style="background:#2b3035;border:1px solid #495057">
        <h6 class="text-secondary mb-3"><i class="fas fa-info-circle me-1"></i>Mensagens de Serviço</h6>
        ${rows}
    </div>`;
}

function buildAlarmsSection(data) {
    const al = data.alarms || {};
    const alarms = [];
    if (al.power_failure)  alarms.push('Pane de corrente');
    if (al.pneumatic_low)  alarms.push('Pressão de ar pneumático baixa');
    if (al.pin_high)       alarms.push('Pressão filtro entrada alta');
    if (al.pout_high)      alarms.push('Pressão filtro saída alta');
    if (al.delta_p_high)   alarms.push('Pressão diferencial alta');
    if (al.pump1_fault)    alarms.push('Falha Bomba 1');
    if (al.pump2_fault)    alarms.push('Falha Bomba 2');
    ['bit5','bit6','bit7'].forEach((k,i) => {
        if (al[k]) alarms.push(`Alarme (sem descrição, bit ${i+5})`);
    });

    if (!alarms.length) {
        return `<div class="p-3 rounded mt-3" style="background:#1a2a1a;border:1px solid #2d5a2d">
            <h6 style="color:#6ee0a0" class="mb-2"><i class="fas fa-check-circle me-1"></i>Alarmes</h6>
            <span style="font-size:0.85rem;color:#6ee0a0">Sem alarmes ativos</span>
        </div>`;
    }
    const rows = alarms.map(a =>
        `<div class="d-flex align-items-center gap-2 mb-1">
            <i class="fas fa-exclamation-triangle" style="color:#dc3545;width:16px;text-align:center"></i>
            <span style="font-size:0.85rem;color:#f8d7da">${a}</span>
        </div>`).join('');
    return `<div class="p-3 rounded mt-3" style="background:#2d1a1a;border:1px solid #5a2d2d">
        <h6 style="color:#f5c6cb" class="mb-3"><i class="fas fa-exclamation-triangle me-1"></i>Alarmes Ativos</h6>
        ${rows}
    </div>`;
}

function buildFiltroModal(data) {
    const fb = data.feedback_bits  || {};
    const sb = data.status_bits    || {};
    const ls = data.limit_switches || {};

    // Setpoint VFD como indicador estável de qual bomba está activa
    const ACTIVE_STATES = ['Em Filtração','Pré-coat','Bump','Enchimento/Drenagem','Bomba a Arrancar'];
    const filterActive = ACTIVE_STATES.includes(data.filter_state);
    const sp1 = parseFloat(data.setpoint_vfd_p1) || 0;
    const sp2 = parseFloat(data.setpoint_vfd_p2) || 0;
    let m_p1on, m_p2on;
    if (!filterActive)        { m_p1on = m_p2on = false; }
    else if (sp1 > 0 || sp2 > 0) { m_p1on = sp1 > 0; m_p2on = sp2 > 0; }
    else                      { m_p1on = !!sb.pump1_start || !sb.pump2_start; m_p2on = !!sb.pump2_start; }

    const stateClass = STATE_CSS[data.filter_state] || '';
    const faultHtml  = data.activeFault
        ? '<span class="badge bg-danger ms-2"><i class="fas fa-exclamation-triangle me-1"></i>FALHA ATIVA</span>'
        : '<span class="badge bg-success ms-2">Online</span>';

    return `
    <div class="mb-3 p-3 rounded" style="background:#2b3035;border:1px solid #495057">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="filtro-state-panel metric-value ${stateClass}" style="font-size:1.6rem">${data.filter_state || '--'}</span>
            ${faultHtml}
        </div>
        <small class="text-secondary">${data.ip_address} &bull; Slave ${data.slave_id}</small>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:#2b3035;border:1px solid #495057">
                <h6 class="text-secondary mb-3"><i class="fas fa-tachometer-alt me-1"></i>Pressões</h6>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Entrada (Pin)</span>
                    <span class="font-monospace fw-bold" style="color:#5bc8f5">${fmtVal(data.pin)} bar</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Saída (Pout)</span>
                    <span class="font-monospace fw-bold" style="color:#6ee0a0">${fmtVal(data.pout)} bar</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Diferencial (ΔP)</span>
                    <span class="font-monospace fw-bold" style="color:#f5a623">${fmtVal(data.delta_p)} bar</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-white-50">Ar Pneumático</span>
                    <span class="font-monospace fw-bold">${fmtVal(data.pneumatic_air)} bar</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:#2b3035;border:1px solid #495057">
                <h6 class="text-secondary mb-3"><i class="fas fa-water me-1"></i>Caudal &amp; Setpoints</h6>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Caudal Filtrado</span>
                    <span class="font-monospace fw-bold" style="color:#0dcaf0">${fmtVal(data.flow, 1)} m³/h</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Setpoint VFD Ext.</span>
                    <span class="font-monospace fw-bold">${fmtVal(data.setpoint_vfd_ext, 1)} %</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Setpoint VFD Bomba 1</span>
                    <span class="font-monospace fw-bold">${fmtVal(data.setpoint_vfd_p1, 1)} %</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-white-50">Setpoint VFD Bomba 2</span>
                    <span class="font-monospace fw-bold">${fmtVal(data.setpoint_vfd_p2, 1)} %</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:#2b3035;border:1px solid #495057">
                <h6 class="text-secondary mb-3"><i class="fas fa-cogs me-1"></i>Bombas</h6>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50">Bomba 1</span>
                    ${pumpStatusBadge(m_p1on, fb.pump1_fault)}
                </div>
                <div class="d-flex justify-content-between mb-3">
                    <span class="text-white-50 ms-2" style="font-size:0.8rem">Horas de operação</span>
                    <span class="font-monospace">${fmtVal(data.op_hours_pump1, 1)} h</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50">Bomba 2</span>
                    ${pumpStatusBadge(m_p2on, fb.pump2_fault)}
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-white-50 ms-2" style="font-size:0.8rem">Horas de operação</span>
                    <span class="font-monospace">${fmtVal(data.op_hours_pump2, 1)} h</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded h-100" style="background:#2b3035;border:1px solid #495057">
                <h6 class="text-secondary mb-3"><i class="fas fa-hourglass-half me-1"></i>Manutenção</h6>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Horas Filtro Total</span>
                    <span class="font-monospace fw-bold">${fmtVal(data.op_hours_filter, 1)} h</span>
                </div>
                <hr style="border-color:#495057;margin:8px 0">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Intervalo Perlite</span>
                    <span class="font-monospace">${fmtVal(data.interval_perlite, 0)}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-white-50">Tempo Restante</span>
                    <span class="font-monospace fw-bold" style="color:${parseFloat(data.remaining_time) < 5 ? '#dc3545' : '#dee2e6'}">${fmtVal(data.remaining_time, 1)} dias</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-white-50">Ciclos de Carga</span>
                    <span class="font-monospace">${fmtVal(data.charging_cycles, 0)}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-white-50">Última troca Perlite</span>
                    <span class="font-monospace">${getPerliteDateLabel(data)}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="p-3 rounded" style="background:#2b3035;border:1px solid #495057">
        <h6 class="text-secondary mb-3"><i class="fas fa-sliders-h me-1"></i>Válvulas (Fins de Curso)</h6>
        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="mb-1 text-white-50" style="font-size:0.75rem">INFLUENTE</div>
                ${lsIndicator(ls.influent_open, ls.influent_closed)}
            </div>
            <div class="col-4">
                <div class="mb-1 text-white-50" style="font-size:0.75rem">EFLUENTE</div>
                ${lsIndicator(ls.effluent_open, ls.effluent_closed)}
            </div>
            <div class="col-4">
                <div class="mb-1 text-white-50" style="font-size:0.75rem">PRÉ-COAT</div>
                ${lsIndicator(ls.precoat_open, ls.precoat_closed)}
            </div>
        </div>
    </div>

    ${buildServiceMessagesSection(data)}
    ${buildAlarmsSection(data)}`;
}

function openFiltroModal(filterId) {
    const modal = new bootstrap.Modal(document.getElementById('filtroDetailModal'));
    const card  = document.getElementById(`card-filtro-${filterId}`);
    const data  = card ? card._filtroData : null;

    const titleEl    = document.getElementById('filtroDetailModalLabel');
    const subtitleEl = document.getElementById('filtroModalSubtitle');
    const bodyEl     = document.getElementById('filtroModalBody');
    const tsEl       = document.getElementById('filtroModalTs');

    if (data) {
        titleEl.textContent    = data.filter_name || 'Filtro Defender';
        subtitleEl.textContent = data.ip_address + ' · Slave ' + data.slave_id;
        bodyEl.innerHTML       = buildFiltroModal(data);
        tsEl.textContent       = 'Última atualização: ' + new Date().toLocaleTimeString('pt-PT');
    } else {
        titleEl.textContent = 'Filtro Defender';
        bodyEl.innerHTML    = '<div class="text-center p-4 text-secondary">Dados ainda não carregados. Aguarde o próximo ciclo de atualização.</div>';
    }

    modal.show();
}
// ─────────────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {

    // ── Botão GLOBAL Alta Afluência ───────────────────────────────────────────
    const btnHaGlobal = document.getElementById('btnGlobalHaToggle');
    const lblHaGlobal = document.getElementById('globalHaState');
    function setBtnState(on) {
        if (!btnHaGlobal || !lblHaGlobal) return;
        btnHaGlobal.dataset.state = on ? 'on' : 'off';
        lblHaGlobal.textContent = on ? 'ON' : 'OFF';
        btnHaGlobal.classList.toggle('btn-warning', on);
        btnHaGlobal.classList.toggle('btn-outline-warning', !on);
    }
    if (btnHaGlobal) {
        btnHaGlobal.addEventListener('click', async function () {
            const turningOn = btnHaGlobal.dataset.state !== 'on';
            const confirmMsg = turningOn
                ? 'Ativar Alta Afluência em TODOS os tanques com SP dinâmico ativo?'
                : 'Desativar Alta Afluência em TODOS os tanques?';
            if (!confirm(confirmMsg)) return;

            btnHaGlobal.disabled = true;
            try {
                const res = await fetch('../api/dynamic_setpoint_config.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: 'high_attendance_global', enabled: turningOn })
                });
                const data = await res.json();
                if (!data || !data.success) throw new Error((data && data.error) || 'Falha desconhecida');
                setBtnState(!!data.high_attendance);
                alert((turningOn ? 'Alta afluência ATIVADA' : 'Alta afluência DESATIVADA') +
                      ' em ' + (data.count || 0) + ' tanque(s).');
            } catch (err) {
                alert('Erro: ' + err.message);
            } finally {
                btnHaGlobal.disabled = false;
            }
        });
    }

    // ... (O resto do JavaScript não precisa de alterações)
    function getValueClass(type, value) {
        const numValue = parseFloat(String(value).replace(',', '.'));
        if (isNaN(numValue)) return '';
        if (type === 'ph') return (numValue < 7.0 || numValue > 7.8) ? 'text-danger' : 'text-success';
        if (type === 'cloro') return (numValue < 1.0 || numValue > 3.0) ? 'text-danger' : 'text-success';
        return 'text-light';
    }

    function getNumericCandidate(data, keys) {
        for (const key of keys) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                const value = parseFloat(String(data[key]).replace(',', '.'));
                if (Number.isFinite(value)) return value;
            }
        }

        if (data.readings && typeof data.readings === 'object') {
            for (const key of keys) {
                if (Object.prototype.hasOwnProperty.call(data.readings, key)) {
                    const value = parseFloat(String(data.readings[key]).replace(',', '.'));
                    if (Number.isFinite(value)) return value;
                }
            }
        }

        return null;
    }

    async function updatePoolDashboard(cardElement) {
        const ip = cardElement.dataset.ip;
        const poolId = cardElement.id.split('-')[2];
        const statusEl = document.getElementById(`status-piscina-${poolId}`);

        try {
            const response = await fetch(`get_controller_data.php?ip=${ip}`);
            if (!response.ok) throw new Error("HTTP " + response.status);
            
const data = await response.json();
if (data.error) throw new Error(data.error);

// RESTAURA VISUAL SE ESTAVA OFFLINE
cardElement.querySelector('.list-group').style.display = '';
cardElement.querySelector('.alarm-content').style.display = 'none';
            // Remove todas as classes de estado anteriores
            cardElement.classList.remove('status-alarm', 'status-offline', 'border-danger', 'border-success', 'border-secondary', 'animate-pulse-red-bs');
            statusEl.classList.remove('bg-danger', 'bg-success', 'bg-secondary');

            // Sucesso na comunicação → controlador respondeu
            const cloroValue = parseFloat(data.freeChlorine);
            const phValue   = parseFloat(data.pH);
            const tempValue = parseFloat(data.temperature);

            // Atualiza sempre os valores (não esconde)
            const cloroText = Number.isFinite(cloroValue)
                ? `${cloroValue.toFixed(2)} <span class="unit">mg/L</span>`
                : '--';
            const phText = Number.isFinite(phValue)
                ? `${phValue.toFixed(2)}`
                : '--';
            const tempText = Number.isFinite(tempValue)
                ? `${tempValue.toFixed(1)} <span class="unit">°C</span>`
                : '--';

            document.getElementById(`cloro-${poolId}`).innerHTML = cloroText;
            document.getElementById(`cloro-${poolId}`).className = `font-monospace fw-bold fs-5 ${getValueClass('cloro', cloroValue)}`;

            document.getElementById(`ph-${poolId}`).innerHTML = phText;
            document.getElementById(`ph-${poolId}`).className = `font-monospace fw-bold fs-5 ${getValueClass('ph', phValue)}`;

            document.getElementById(`temp-${poolId}`).innerHTML = tempText;

            // Decide o estado visual
            const temAlarmeQuimico =
    getValueClass('cloro', cloroValue) === 'text-danger' ||
    getValueClass('ph', phValue) === 'text-danger';

const alarmeControlador = (data.alarme == 0); // alarme interno

// 1️⃣ ALARME DO CONTROLADOR
if (alarmeControlador) {

    cardElement.classList.add('border-danger', 'animate-pulse-red-bs');
    statusEl.classList.add('bg-danger');
    statusEl.textContent = 'ALARME ATIVO';

// 2️⃣ PARÂMETROS FORA DOS LIMITES
} else if (temAlarmeQuimico) {

    cardElement.classList.add('border-warning');
    statusEl.classList.add('bg-warning');
    statusEl.textContent = 'FORA DOS LIMITES';

// 3️⃣ TUDO OK
} else {

    cardElement.classList.add('border-success');
    statusEl.classList.remove('bg-warning');
    statusEl.classList.add('bg-success');
    cardElement.classList.remove(
        'status-alarm',
        'status-offline',
        'border-warning',
        'border-danger',       
        'border-secondary',
        'animate-pulse-red-bs'
    );    
    statusEl.textContent = 'OK';

}

        } catch (error) {
            // Aqui sim é falha real de comunicação
            cardElement.classList.remove('border-success', 'animate-pulse-red-bs');
            cardElement.classList.add('status-offline', 'border-danger');
            statusEl.classList.remove('bg-success');
            statusEl.classList.add('bg-danger');
            statusEl.textContent = 'OFFLINE';

            // Mostra bloco de erro de comunicação
            cardElement.querySelector('.list-group').style.display = 'none';
            cardElement.querySelector('.alarm-content').style.display = 'block';
        }
    }
function createLoraCard(device) {

    const isOnline = device.status === 'On';

    let equipmentStatus = device.equipment_status || 'Unknown';

    // Se o dispositivo LoRa estiver offline,
    // o estado do equipamento passa automaticamente a Unknown
    if (device.status !== 'On') {
        equipmentStatus = 'Unknown';
    }

    // Badge depende APENAS da ligação à rede
    const badgeClass = isOnline ? 'success' : 'danger';
    const badgeText  = isOnline ? 'Online' : 'Offline';

    const lastSeen = device.last_seen
        ? new Date(device.last_seen).toLocaleString('pt-PT')
        : 'Nunca';

    // Estado do equipamento continua separado
    const equipmentClass =
        equipmentStatus === 'On' ? 'success' :
        equipmentStatus === 'Off' ? 'warning' :
        'secondary';

    const borderClass =
    !isOnline ? 'danger' :
    equipmentStatus === 'Off' ? 'danger' :
    'success';

    return `
        <a href="view_lora_details.php?id=${device.id}" class="text-decoration-none">
            <div class="card scada-card h-100 border-${borderClass} shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold">${device.name}</h5>
                    <span class="badge bg-${badgeClass}">LoRa ${badgeText}</span>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-white-50">Estado Equipamento</span>
                        <span class="font-monospace fw-bold fs-5 text-${equipmentClass}">
                            ${equipmentStatus}
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-white-50">Última Vez Visto</span>
                        <span class="font-monospace" style="font-size: 1rem;">${lastSeen}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-white-50">Sinal (RSSI/SNR)</span>
                        <span class="font-monospace fs-5">
                            ${(device.last_rssi != null ? device.last_rssi : 'N/A')} <span class="unit">dBm</span> /
                            ${(device.last_snr != null ? device.last_snr : 'N/A')}
                        </span>
                    </li>
                </ul>
            </div>
        </a>
    `;
}
    
    async function updateMedidaCard(cardElement) {
        const ip = cardElement.dataset.ip;
        const meterId = cardElement.id.split('-')[2];
        const statusEl = document.getElementById(`status-medida-${meterId}`);

        try {
            const response = await fetch(`get_controller_data.php?ip=${ip}`);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            cardElement.classList.remove('status-alarm', 'border-danger', 'border-success', 'border-secondary');
            statusEl.classList.remove('bg-danger', 'bg-success', 'bg-secondary');

            cardElement.classList.add('border-success');
            statusEl.classList.add('bg-success');
            statusEl.textContent = 'Online';

            const voltage = parseFloat(data.voltageLNAvg);
            const current = parseFloat(data.currentAvg);
            const power   = parseFloat(data.activePowerTotal);

            document.getElementById(`voltage-${meterId}`).innerHTML = Number.isFinite(voltage)
                ? `${voltage.toFixed(1)} <span class="unit">V</span>`
                : '--';
            document.getElementById(`current-${meterId}`).innerHTML = Number.isFinite(current)
                ? `${current.toFixed(2)} <span class="unit">A</span>`
                : '--';
            document.getElementById(`power-${meterId}`).innerHTML = Number.isFinite(power)
                ? `${power.toFixed(2)} <span class="unit">kW</span>`
                : '--';

        } catch (error) {
            cardElement.classList.add('status-alarm', 'border-danger');
            statusEl.classList.add('bg-danger');
            statusEl.textContent = 'OFFLINE';

            const listGroup = cardElement.querySelector('.list-group');
            if (listGroup) listGroup.style.display = 'none';
            const alarmContent = cardElement.querySelector('.alarm-content');
            if (alarmContent) alarmContent.style.display = 'block';
        }
    }

    const filtroFailCount = {};
    const FILTRO_FAIL_THRESHOLD = 2;

    // fmtVal / lsIndicator / pumpStatusBadge / buildFiltroModal / openFiltroModal → globais (acima do DOMContentLoaded)

    async function updateFiltroCard(cardElement) {
        const filterId = cardElement.id.split('-')[2];
        const statusEl  = document.getElementById(`status-filtro-${filterId}`);
        const pinEl     = document.getElementById(`filtro-pin-${filterId}`);
        const poutEl    = document.getElementById(`filtro-pout-${filterId}`);
        const deltaEl   = document.getElementById(`filtro-delta-${filterId}`);
        const flowEl    = document.getElementById(`filtro-flow-${filterId}`);
        const stateEl   = document.getElementById(`filtro-pump-state-${filterId}`);
        const pump1Dot    = document.getElementById(`filtro-pump1-${filterId}`);
        const pump2Dot    = document.getElementById(`filtro-pump2-${filterId}`);
        const pump1Badge  = document.getElementById(`filtro-pump1-badge-${filterId}`);
        const pump2Badge  = document.getElementById(`filtro-pump2-badge-${filterId}`);
        const bumpInfoEl  = document.getElementById(`filtro-bump-info-${filterId}`);
        const bumpCycleEl = document.getElementById(`filtro-bump-cycle-${filterId}`);

        const metricsEl   = cardElement.querySelector('.filtro-metrics');
        const statePanelEl = cardElement.querySelector('.filtro-state-panel');
        const pumpPanelEl = cardElement.querySelector('.filtro-pump-panel');
        const footerEl    = cardElement.querySelector('.filtro-footer');
        const alarmEl     = cardElement.querySelector('.alarm-content');

        try {
            const response = await fetch(`get_filter_modbus_data.php?id=${filterId}`);
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.error || ('HTTP ' + response.status));

            // Cache for modal
            cardElement._filtroData = data;

            cardElement.classList.remove('status-offline', 'border-danger', 'border-success', 'border-secondary', 'animate-pulse-red-bs');
            if (statusEl) statusEl.classList.remove('bg-danger', 'bg-success', 'bg-secondary', 'bg-warning');

            const pin    = data.pin    != null ? parseFloat(data.pin)    : null;
            const pout   = data.pout   != null ? parseFloat(data.pout)   : null;
            const deltaP = data.delta_p != null ? parseFloat(data.delta_p)
                         : (pin != null && pout != null ? pin - pout : null);
            const flow   = data.flow   != null ? parseFloat(data.flow)   : null;

            const fb = data.feedback_bits || {};
            const sb = data.status_bits    || {};

            // Indicador de bomba a correr: Setpoint VFD é um valor contínuo e estável.
            // Se o setpoint de uma bomba > 0 ela está activa. Muito mais fiável
            // que os bits de arranque VFD (que são sinais momentâneos).
            const ACTIVE_STATES = ['Em Filtração','Pré-coat','Bump','Enchimento/Drenagem','Bomba a Arrancar'];
            const filterActive = ACTIVE_STATES.includes(data.filter_state);
            const sp1 = parseFloat(data.setpoint_vfd_p1) || 0;
            const sp2 = parseFloat(data.setpoint_vfd_p2) || 0;
            // Se ambos os setpoints são 0 e filtro está activo, usar bit de arranque como fallback
            let p1on, p2on;
            if (!filterActive) {
                p1on = p2on = false;
            } else if (sp1 > 0 || sp2 > 0) {
                p1on = sp1 > 0;
                p2on = sp2 > 0;
            } else {
                // Fallback: setpoints ainda não lidos, usar bits de arranque
                p1on = !!sb.pump1_start || !sb.pump2_start; // default bomba 1
                p2on = !!sb.pump2_start;
            }
            const p1fault = !!fb.pump1_fault;
            const p2fault = !!fb.pump2_fault;

            if (data.activeFault || p1fault || p2fault) {
                cardElement.classList.add('border-danger', 'animate-pulse-red-bs');
                if (statusEl) { statusEl.classList.add('bg-danger'); statusEl.textContent = 'FALHA'; }
            } else {
                cardElement.classList.add('border-success');
                if (statusEl) { statusEl.classList.add('bg-success'); statusEl.textContent = 'ONLINE'; }
            }

            if (pinEl)   pinEl.textContent  = pin    != null ? pin.toFixed(2)    : '--';
            if (poutEl)  poutEl.textContent = pout   != null ? pout.toFixed(2)   : '--';
            if (deltaEl) deltaEl.textContent = deltaP != null ? deltaP.toFixed(2) : '--';
            if (flowEl)  flowEl.innerHTML   = flow   != null
                ? `${flow.toFixed(1)} <span style="font-size:0.75rem;color:var(--scada-text-secondary);font-family:sans-serif">m³/h</span>`
                : '--';

            if (stateEl) {
                const filterState = data.filter_state || '--';
                stateEl.textContent = filterState;
                stateEl.className   = 'metric-value ' + (STATE_CSS[filterState] || '');
            }

            const setPump = (dot, badge, running, fault) => {
                if (dot) {
                    dot.style.color = fault ? '#dc3545' : (running ? '#198754' : '#495057');
                    dot.title = fault ? 'Falha' : (running ? 'Em serviço' : 'Parada');
                }
                if (badge) {
                    if (fault) {
                        badge.className = 'badge bg-danger';
                        badge.textContent = 'FALHA';
                    } else if (running) {
                        badge.className = 'badge bg-success';
                        badge.textContent = 'Em Serviço';
                    } else {
                        badge.className = 'badge bg-secondary';
                        badge.textContent = 'Parada';
                    }
                    badge.style.fontSize = '0.7rem';
                }
            };
            setPump(pump1Dot, pump1Badge, p1on, p1fault);
            setPump(pump2Dot, pump2Badge, p2on, p2fault);

            // Info de BUMP: mostrar quando o bit de bump está activo
            const isBump = !!(sb.filter_bump);
            if (bumpInfoEl) bumpInfoEl.style.display = isBump ? '' : 'none';
            if (bumpCycleEl) {
                const cycles = data.charging_cycles != null ? parseFloat(data.charging_cycles) : null;
                bumpCycleEl.textContent = cycles != null ? `— Ciclo ${cycles}` : '';
            }

            if (metricsEl)    metricsEl.style.display    = '';
            if (statePanelEl) statePanelEl.style.display = '';
            if (pumpPanelEl)  pumpPanelEl.style.display  = '';
            if (footerEl)     footerEl.style.display     = '';
            if (alarmEl)      alarmEl.style.display      = 'none';

            filtroFailCount[filterId] = 0;

        } catch (error) {
            filtroFailCount[filterId] = (filtroFailCount[filterId] || 0) + 1;
            const failCount = filtroFailCount[filterId];
            console.error(`[filtro-${filterId}] updateFiltroCard error (${failCount}/${FILTRO_FAIL_THRESHOLD}):`, error);
            if (failCount < FILTRO_FAIL_THRESHOLD) return;

            cardElement.classList.remove('border-success', 'border-secondary', 'animate-pulse-red-bs');
            cardElement.classList.add('status-offline', 'border-danger');
            if (statusEl) {
                statusEl.classList.remove('bg-success', 'bg-secondary', 'bg-warning');
                statusEl.classList.add('bg-danger');
                statusEl.textContent = 'OFFLINE';
            }
            [pinEl, poutEl, deltaEl].forEach(el => { if (el) el.textContent = '--'; });
            if (flowEl)  flowEl.textContent  = '--';
            if (stateEl) { stateEl.textContent = '--'; stateEl.className = 'metric-value'; }
            if (pump1Dot) pump1Dot.style.color = '#495057';
            if (pump2Dot) pump2Dot.style.color = '#495057';
            [pump1Badge, pump2Badge].forEach(b => { if (b) { b.className = 'badge bg-secondary'; b.textContent = '--'; b.style.fontSize = '0.7rem'; } });
            if (bumpInfoEl) bumpInfoEl.style.display = 'none';

            if (metricsEl)    metricsEl.style.display    = 'none';
            if (statePanelEl) statePanelEl.style.display = 'none';
            if (pumpPanelEl)  pumpPanelEl.style.display  = 'none';
            if (footerEl)     footerEl.style.display     = 'none';
            if (alarmEl)      alarmEl.style.display      = 'block';
        }
    }

    function updateTabAlerts() {
        document.querySelectorAll('.nav-link[data-bs-toggle="tab"]').forEach(tab => {
            const paneId = tab.getAttribute('data-bs-target');
            const pane = document.querySelector(paneId);
            if (pane && pane.querySelector('.border-danger')) {
                tab.classList.add('tab-alert');
            } else {
                tab.classList.remove('tab-alert');
            }
        });
    }

    async function updateLoraDashboard() {
        const loraContainer = document.getElementById('dashboard-container-lora');
        try {
            const response = await fetch('../api/get_lorawan_status.php');
            const devices = await response.json();
            if (devices.error) throw new Error(devices.error);
            
            if (devices.length > 0) {
                loraContainer.innerHTML = devices.map(createLoraCard).join('');
            } else {
                loraContainer.innerHTML = '<div class="col-12"><p class="text-muted text-center">Nenhum equipamento LoRaWAN registado.</p></div>';
            }
        } catch (error) {
            console.error("Erro ao carregar dados LoRaWAN:", error);
            loraContainer.innerHTML = '<div class="col-12"><p class="text-danger text-center">Erro ao carregar estado da rede LoRaWAN.</p></div>';
        }
    }

    async function updateAllDashboards() {
        const poolCards = document.querySelectorAll('#dashboard-container-piscinas .scada-card');
        const medidaCards = document.querySelectorAll('#dashboard-container-medida .scada-card');
        const filtroCards = document.querySelectorAll('#dashboard-container-filtros .scada-card');

        // Pools, medidas e LoRa podem correr em paralelo (protocolos/dispositivos distintos)
        const parallelPromises = [
            ...Array.from(poolCards).map(card => updatePoolDashboard(card)),
            ...Array.from(medidaCards).map(card => updateMedidaCard(card)),
            updateLoraDashboard()
        ];

        // Filtros Modbus TCP são serializados: a maioria dos PLCs só aceita 1 ligação de cada vez.
        // Ligações simultâneas causam rejeição imediata e "Cabeçalho MBAP incompleto".
        const serialFiltros = async () => {
            for (const card of filtroCards) {
                await updateFiltroCard(card);
            }
        };

        await Promise.all([...parallelPromises, serialFiltros()]);

        updateTabAlerts();
    }

    let updating = false;

    async function safeUpdate() {

        if (updating) return;

        updating = true;

        try {
            await updateAllDashboards();
        } finally {
            updating = false;
        }

    }

    setInterval(safeUpdate, 10000);
    safeUpdate();});
</script>

<?php
require_once '../footer.php';
?>