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
        <h1 class="h2 mb-0">Dashboard de Monitorização</h1>
        <div class="d-flex gap-2">
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
                        <div id="card-filtro-<?= $filter['id'] ?>" class="card scada-card h-100 border-secondary shadow-sm" data-type="filtro" data-ip="<?= htmlspecialchars($filter['ip_address']) ?>" data-slave-id="<?= (int)$filter['slave_id'] ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0 fw-bold">
                                    <i class="fas fa-filter me-2 opacity-75"></i><?= htmlspecialchars($filter['name']) ?>
                                </h5>
                                <span id="status-filtro-<?= $filter['id'] ?>" class="badge bg-secondary">Aguardando...</span>
                            </div>
                            <div class="filtro-metrics">
                                <div class="filtro-metric pin">
                                    <div class="metric-label">Pin</div>
                                    <div class="metric-value" id="filtro-pin-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                                <div class="filtro-metric pout">
                                    <div class="metric-label">Pout</div>
                                    <div class="metric-value" id="filtro-pout-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                                <div class="filtro-metric delta">
                                    <div class="metric-label">&Delta;P</div>
                                    <div class="metric-value" id="filtro-delta-<?= $filter['id'] ?>">--</div>
                                    <div class="metric-unit">bar</div>
                                </div>
                            </div>
                            <div class="filtro-state-panel">
                                <div class="metric-label">Estado</div>
                                <div class="metric-value" id="filtro-pump-state-<?= $filter['id'] ?>">--</div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {

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
                            ${device.last_rssi ?? 'N/A'} <span class="unit">dBm</span> /
                            ${device.last_snr ?? 'N/A'}
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

    async function updateFiltroCard(cardElement) {
        const filterId = cardElement.id.split('-')[2];
        const statusEl = document.getElementById(`status-filtro-${filterId}`);

        const pinEl   = document.getElementById(`filtro-pin-${filterId}`);
        const poutEl  = document.getElementById(`filtro-pout-${filterId}`);
        const deltaEl = document.getElementById(`filtro-delta-${filterId}`);

        const metricsEl  = cardElement.querySelector('.filtro-metrics');
        const footerEl   = cardElement.querySelector('.filtro-footer');
        const stateEl    = cardElement.querySelector('.filtro-state-panel');
        const alarmEl    = cardElement.querySelector('.alarm-content');

        try {
            const response = await fetch(`get_filter_modbus_data.php?id=${filterId}`);
            const data = await response.json();
            if (!response.ok || data.error) throw new Error(data.error || ('HTTP ' + response.status));

            cardElement.classList.remove('status-offline', 'border-danger', 'border-success', 'border-secondary', 'animate-pulse-red-bs');
            if (statusEl) statusEl.classList.remove('bg-danger', 'bg-success', 'bg-secondary', 'bg-warning');

            const pin    = (data.pin    !== null && data.pin    !== undefined) ? parseFloat(data.pin)    : null;
            const pout   = (data.pout   !== null && data.pout   !== undefined) ? parseFloat(data.pout)   : null;
            const deltaP = (data.delta_p !== null && data.delta_p !== undefined) ? parseFloat(data.delta_p)
                          : (pin !== null && pout !== null ? pin - pout : null);
            const pump_state = (data.pump_state !== null && data.pump_state !== undefined) ? parseFloat(data.pump_state) : null;
            const precoat_active = data.precoat_active === true || data.precoat_active === 1 || data.precoat_active === '1';

            let pumpState = '--';
            if (pump_state !== null && !isNaN(pump_state)) {
                if (pump_state === 0) pumpState = 'Parado';
                else if (precoat_active) pumpState = 'Pré-coat';
                else if (pump_state >= 90) pumpState = 'Em Filtração';
                else pumpState = 'Pré-coat';
            }

            if (data.activeFault) {
                cardElement.classList.add('border-danger', 'animate-pulse-red-bs');
                if (statusEl) { statusEl.classList.add('bg-danger'); statusEl.textContent = 'FALHA'; }
            } else {
                cardElement.classList.add('border-success');
                if (statusEl) { statusEl.classList.add('bg-success'); statusEl.textContent = 'ONLINE'; }
            }

            if (pinEl)   pinEl.textContent   = pin    !== null && !isNaN(pin)    ? pin.toFixed(2)    : '--';
            if (poutEl)  poutEl.textContent  = pout   !== null && !isNaN(pout)   ? pout.toFixed(2)   : '--';
            if (deltaEl) deltaEl.textContent = deltaP !== null && !isNaN(deltaP) ? deltaP.toFixed(2) : '--';

            const pumpStateBadge = document.getElementById(`filtro-pump-state-${filterId}`);
            if (pumpStateBadge) {
                pumpStateBadge.textContent = pumpState;
                pumpStateBadge.className = 'metric-value';
                if (pump_state !== null && !isNaN(pump_state)) {
                    if (pump_state === 0) pumpStateBadge.classList.add('parado');
                    else if (precoat_active) pumpStateBadge.classList.add('precoat');
                    else if (pump_state >= 90) pumpStateBadge.classList.add('filtracao');
                    else pumpStateBadge.classList.add('precoat');
                }
            }

            if (metricsEl) metricsEl.style.display = '';
            if (stateEl)   stateEl.style.display   = '';
            if (footerEl)  footerEl.style.display  = '';
            if (alarmEl)   alarmEl.style.display   = 'none';

        } catch (error) {
            console.error(`[filtro-${filterId}] updateFiltroCard error:`, error);

            cardElement.classList.remove('border-success', 'border-secondary', 'animate-pulse-red-bs');
            cardElement.classList.add('status-offline', 'border-danger');
            if (statusEl) {
                statusEl.classList.remove('bg-success', 'bg-secondary', 'bg-warning');
                statusEl.classList.add('bg-danger');
                statusEl.textContent = 'OFFLINE';
            }

            if (pinEl)   pinEl.textContent   = '--';
            if (poutEl)  poutEl.textContent  = '--';
            if (deltaEl) deltaEl.textContent = '--';

            const pumpStateBadgeErr = document.getElementById(`filtro-pump-state-${filterId}`);
            if (pumpStateBadgeErr) { pumpStateBadgeErr.textContent = '--'; pumpStateBadgeErr.className = 'metric-value'; }

            if (metricsEl) metricsEl.style.display = 'none';
            if (stateEl)   stateEl.style.display   = 'none';
            if (footerEl)  footerEl.style.display  = 'none';
            if (alarmEl)   alarmEl.style.display   = 'block';
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