<?php
require_once '../header.php';

setlocale(LC_TIME, 'pt_PT.utf8', 'pt_PT.UTF-8', 'Portuguese_Portugal.1252');

// Busca todas as piscinas que têm um controlador
$stmt = $conn->query("SELECT id, name, controller_ip FROM tanks WHERE type = 'piscina' AND has_controller = 1 ORDER BY name ASC");
$pools = $stmt->fetch_all(MYSQLI_ASSOC);

$stmt_lora = $conn->query("SELECT id, name, dev_eui FROM lorawan_devices ORDER BY name ASC");
$lora_devices = $stmt_lora->fetch_all(MYSQLI_ASSOC);

// Busca todas as Centrais de Medida
$stmt_medida = $conn->query("SELECT id, local, ip_address FROM centrais_de_medida ORDER BY local ASC");
$power_meters = $stmt_medida->fetch_all(MYSQLI_ASSOC);
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
</style>


<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">Dashboard de Monitorização</h1>
        <div><a href="../redirect_page.php" class="btn btn-secondary">Voltar ao Início</a></div>
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
                                <img src="../images/alarm.gif" width="80" alt="Alarme Ativo">
                                <div class="fw-bold mt-2">Verifique o Controlador!</div>
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

    async function updatePoolDashboard(cardElement) {
        const ip = cardElement.dataset.ip;
        const poolId = cardElement.id.split('-')[2];
        const statusEl = document.getElementById(`status-piscina-${poolId}`);

        try {
            const response = await fetch(`get_controller_data.php?ip=${ip}`);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            cardElement.classList.remove('status-alarm', 'border-danger', 'border-success', 'border-secondary', 'animate-pulse-red-bs');
            statusEl.classList.remove('bg-danger', 'bg-success', 'bg-secondary');

            if (data.alarme == 0) {
                cardElement.classList.add('status-alarm', 'border-danger', 'animate-pulse-red-bs');
                statusEl.classList.add('bg-danger');
                statusEl.textContent = 'ALARME';
            } else {
                const cloroValue = data.freeChlorine;
                const phValue = data.pH;
                const tempValue = data.temperature;
                
                document.getElementById(`cloro-${poolId}`).innerHTML = `${parseFloat(cloroValue).toFixed(2)} <span class="unit">mg/L</span>`;
                document.getElementById(`cloro-${poolId}`).className = `font-monospace fw-bold fs-5 ${getValueClass('cloro', cloroValue)}`;
                
                document.getElementById(`ph-${poolId}`).innerHTML = `${parseFloat(phValue).toFixed(2)}`;
                document.getElementById(`ph-${poolId}`).className = `font-monospace fw-bold fs-5 ${getValueClass('ph', phValue)}`;

                document.getElementById(`temp-${poolId}`).innerHTML = `${parseFloat(tempValue).toFixed(1)} <span class="unit">°C</span>`;

                if (getValueClass('cloro', cloroValue) === 'text-danger' || getValueClass('ph', phValue) === 'text-danger') {
                    cardElement.classList.add('border-danger');
                    statusEl.classList.add('bg-danger');
                    statusEl.textContent = 'Fora dos Limites';
                } else {
                    cardElement.classList.add('border-success');
                    statusEl.classList.add('bg-success');
                    statusEl.textContent = 'OK';
                }
            }
        } catch (error) {
            cardElement.classList.add('status-alarm', 'border-danger', 'animate-pulse-red-bs');
            statusEl.classList.add('bg-danger');
            statusEl.textContent = 'OFFLINE';
        }
    }

    function createLoraCard(device) {
        const isOnline = device.status === 'On';
        const equipmentStatus = isOnline ? (device.equipment_status || 'Unknown') : 'Offline';
        const statusClass = equipmentStatus === 'On' && isOnline ? 'success' : 'danger';
        const lastSeen = device.last_seen ? new Date(device.last_seen).toLocaleString('pt-PT') : 'Nunca';

        return `
            <a href="view_lora_details.php?id=${device.id}" class="text-decoration-none">
                <div class="card scada-card h-100 border-${statusClass} shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0 fw-bold">${device.name}</h5>
                        <span class="badge bg-${statusClass}">${isOnline ? 'Online' : 'Offline'}</span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-white-50">Estado Equipamento</span>
                            <span class="font-monospace fw-bold fs-5 text-${statusClass}">${equipmentStatus}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-white-50">Última Vez Visto</span>
                            <span class="font-monospace" style="font-size: 1rem;">${lastSeen}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="text-white-50">Sinal (RSSI/SNR)</span>
                            <span class="font-monospace fs-5">${device.last_rssi || 'N/A'} <span class="unit">dBm</span> / ${device.last_snr || 'N/A'}</span>
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

            document.getElementById(`voltage-${meterId}`).innerHTML = `${parseFloat(data.voltageLNAvg).toFixed(1)} <span class="unit">V</span>`;
            document.getElementById(`current-${meterId}`).innerHTML = `${parseFloat(data.currentAvg).toFixed(2)} <span class="unit">A</span>`;
            document.getElementById(`power-${meterId}`).innerHTML = `${parseFloat(data.activePowerTotal).toFixed(2)} <span class="unit">kW</span>`;

        } catch (error) {
            cardElement.classList.add('status-alarm', 'border-danger');
            statusEl.classList.add('bg-danger');
            statusEl.textContent = 'OFFLINE';
            const alarmContent = cardElement.querySelector('.alarm-content');
            if (alarmContent) alarmContent.style.display = 'block';
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

        const allPromises = [
            ...Array.from(poolCards).map(card => updatePoolDashboard(card)),
            ...Array.from(medidaCards).map(card => updateMedidaCard(card)),
            updateLoraDashboard()
        ];
        
        await Promise.all(allPromises);
        
        updateTabAlerts();
    }

    updateAllDashboards();
    setInterval(updateAllDashboards, 10000);
});
</script>

<?php
require_once '../footer.php';
?>