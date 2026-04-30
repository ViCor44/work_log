<?php
require_once '../header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { die("Tanque inválido."); }
$tank_id = $_GET['id'];

// ALTERAÇÃO: Busca o nome e o IP do controlador do tanque
$stmt = $conn->prepare("SELECT name, controller_ip FROM tanks WHERE id = ?");
$stmt->bind_param("i", $tank_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) { die("Tanque não encontrado."); }
$tank_info = $result->fetch_assoc();
$tank_name = $tank_info['name'];
$controller_ip = $tank_info['controller_ip']; // Guardamos o IP
$stmt->close();
?>
<script src="/work_log/js/Chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="/work_log/js/chartjs-gauge.min.js"></script>
<script src="/work_log/js/hammer.min.js"></script>
<script src="/work_log/js/chartjs-plugin-zoom.min.js"></script>
<style>
    .gauge-card {
        background: linear-gradient(135deg, #2c3e50, #34495e); /* Gradiente industrial */
        color: #ecf0f1;
        padding: 1rem;
        border: 2px solid #bdc3c7;
        border-radius: 6px;
        text-align: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        position: relative;
        font-family: 'Courier New', Courier, monospace; /* Fonte técnica */
    }
    .gauge-card h5 {
        font-weight: bold;
        margin-bottom: 1rem;
        color: #adb5bd;
        text-transform: uppercase;
        font-size: 1.1rem;
        border-bottom: 1px dashed #bdc3c7;
    }
    .chart-container {
        background: linear-gradient(135deg, #2c3e50, #34495e);
        padding: 1.5rem;
        border: 2px solid #bdc3c7;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }
    .details-box {
        background-color: #495057;
        color: #ecf0f1;
        padding: 10px;
        border-radius: 5px;
        margin-top: 1rem;
        font-size: 0.85rem;
    }
    .details-box .detail-row {
        display: flex;
        justify-content: space-between;
    }
    /* Indicador de estado (luz SCADA) */
    .status-indicator {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background-color: #7f8c8d;
    }
    /* Estilo para os botões */
    .btn-warning, .btn-secondary {
        background-color: #f39c12;
        border-color: #e08e0b;
        color: #fff;
    }
    .btn-warning:hover, .btn-secondary:hover {
        background-color: #e08e0b;
        border-color: #d39e00;
    }
    .btn-secondary {
        background-color: #6c757d;
        border-color: #5a6268;
    }
    .btn-secondary:hover {
        background-color: #5a6268;
        border-color: #545b62;
    }
    /* Ajustes no formulário */
    .form-label {
        color: #ecf0f1;
    }
    .form-control {
        background-color: #495057;
        color: #ecf0f1;
        border: 1px solid #6c757d;
    }
    .form-control:focus {
        background-color: #495057;
        color: #ecf0f1;
        border-color: #80bdff;
        box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
    }
    h5.text-center {
        color: #ecf0f1;
        font-weight: bold;
    }
    .gauge-setpoint-form {
        margin-top: 0.75rem;
        padding: 0.65rem;
        border: 1px solid #6c757d;
        border-radius: 5px;
        background-color: rgba(33, 37, 41, 0.35);
    }
    .gauge-setpoint-form .form-label {
        font-size: 0.8rem;
        margin-bottom: 0.35rem;
    }
    .gauge-setpoint-status {
        margin-top: 0.5rem;
        margin-bottom: 0;
        padding: 0.35rem 0.5rem;
        font-size: 0.78rem;
    }
    .dynamic-switch-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.45rem;
        padding: 0.25rem 0.4rem;
        background: rgba(108, 117, 125, 0.2);
        border-radius: 4px;
    }
    .dynamic-switch-row .form-check {
        margin-bottom: 0;
    }
    .dynamic-switch-row .form-check-label {
        font-size: 0.78rem;
    }
</style>

<div class="container-fluid mt-4">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Monitorização Detalhada: <?= htmlspecialchars($tank_name) ?></h1>
	    <div>
                <a href="advanced_settings.php?id=<?= $tank_id ?>" class="btn btn-warning" id="btn-pid-analysis">Análise PID Inteligente</a>
	        <a href="dashboard.php" class="btn btn-secondary">Voltar ao Dashboard</a>
	    </div>
	</div>

    <div class="row">
        <div class="col-lg-3">
            <div class="row">
			    <div class="col-12 mb-3">
                    <div class="gauge-card">
                        <h5>Cloro Livre (mg/L)</h5>
                        <canvas id="cloroGauge" height="150"></canvas>
                        <div id="cloro-details" class="details-box"></div>
                        <?php if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'viewer'): ?>
                        <form id="cloro-setpoint-form" class="gauge-setpoint-form">
                            <div class="dynamic-switch-row">
                                <span class="small">Setpoint dinâmico</span>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cloro-dynamic-toggle">
                                    <label class="form-check-label" for="cloro-dynamic-toggle">Ativo</label>
                                </div>
                            </div>
                            <label for="cloro-setpoint-val" class="form-label">Setpoint remoto (Controlador 1)</label>
                            <div class="d-flex gap-2">
                                <input type="number" step="0.01" class="form-control form-control-sm" id="cloro-setpoint-val" placeholder="Ex.: 1.50" required>
                                <button type="submit" class="btn btn-warning btn-sm" id="cloro-setpoint-submit">Aplicar</button>
                            </div>
                            <div id="cloro-setpoint-status" class="alert d-none gauge-setpoint-status" role="alert"></div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 mb-3">
                    <div class="gauge-card">
                        <h5>pH</h5>
                        <canvas id="phGauge" height="150"></canvas>
                        <div id="ph-details" class="details-box"></div>
                        <?php if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'viewer'): ?>
                        <form id="ph-setpoint-form" class="gauge-setpoint-form">
                            <label for="ph-setpoint-val" class="form-label">Setpoint remoto (Controlador 2)</label>
                            <div class="d-flex gap-2">
                                <input type="number" step="0.01" class="form-control form-control-sm" id="ph-setpoint-val" placeholder="Ex.: 7.20" required>
                                <button type="submit" class="btn btn-warning btn-sm" id="ph-setpoint-submit">Aplicar</button>
                            </div>
                            <div id="ph-setpoint-status" class="alert d-none gauge-setpoint-status" role="alert"></div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 mb-3">
                    <div class="gauge-card">
                        <h5>Temperatura (°C)</h5>
                        <canvas id="tempGauge" height="150"></canvas>
                        <div id="temp-details" class="details-box"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-9 mb-4">
            <div class="chart-container">
                <form class="row g-3 mb-3" id="date-range-form">
                    <div class="col-md-4">
                        <label for="start_date" class="form-label">Data Início</label>
                        <input type="date" class="form-control" id="start_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="end_date" class="form-label">Data Fim</label>
                        <input type="date" class="form-control" id="end_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">Filtrar Histórico</button>
                    </div>
                </form>
                <div id="history-stale-warning" class="alert alert-warning py-2 px-3 d-none" role="alert"></div>
               <div class="row">
                   <div class="col-12">
                       <h5 class="text-center">Histórico de Cloro Livre (mg/L)</h5>
                       <canvas id="cloroHistoryChart" height="350" style="cursor:pointer;"></canvas>
                   </div>
                   <div class="col-12 mb-4">
                       <h5 class="text-center">Histórico de pH</h5>
                       <canvas id="phHistoryChart" height="350"></canvas>
                   </div>
               </div>
            <!-- Modal para adicionar nota ao ponto do gráfico -->
            <div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content bg-dark text-light">
                        <div class="modal-header">
                            <h5 class="modal-title" id="noteModalLabel">Adicionar Nota ao Ponto Selecionado</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="selectedPointInfo" class="mb-2 text-info small"></div>
                            <form id="noteForm">
                                <div class="mb-3">
                                    <label for="noteText" class="form-label">Nota</label>
                                    <textarea class="form-control" id="noteText" rows="3" required></textarea>
                                </div>
                                <input type="hidden" id="noteIndex">
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" form="noteForm" class="btn btn-warning" id="btnGuardarNota">Guardar Nota</button>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tankId = <?= $tank_id ?>;
    const controllerIp = '<?= $controller_ip ?>';
    const defaultHistoryDate = '<?= date('Y-m-d') ?>';
    const pidAnalysisBtn = document.getElementById('btn-pid-analysis');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const cloroSetpointForm = document.getElementById('cloro-setpoint-form');
    const cloroSetpointInput = document.getElementById('cloro-setpoint-val');
    const cloroSetpointBtn = document.getElementById('cloro-setpoint-submit');
    const cloroSetpointStatus = document.getElementById('cloro-setpoint-status');
    const phSetpointForm = document.getElementById('ph-setpoint-form');
    const phSetpointInput = document.getElementById('ph-setpoint-val');
    const phSetpointBtn = document.getElementById('ph-setpoint-submit');
    const phSetpointStatus = document.getElementById('ph-setpoint-status');
    const cloroDynamicToggle = document.getElementById('cloro-dynamic-toggle');
    const historyStaleWarning = document.getElementById('history-stale-warning');

    if (pidAnalysisBtn) {
        pidAnalysisBtn.addEventListener('click', function(event) {
            const startDate = startDateInput ? startDateInput.value : '';
            const endDate = endDateInput ? endDateInput.value : '';
            const usingDefaultTodayRange = startDate === defaultHistoryDate && endDate === defaultHistoryDate;
            if (startDate && endDate && !usingDefaultTodayRange) {
                event.preventDefault();
                window.location.href = `advanced_settings.php?id=${tankId}&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
            }
        });
    }

    function showGaugeSetpointStatus(statusEl, message, ok) {
        if (!statusEl) return;
        statusEl.classList.remove('d-none', 'alert-success', 'alert-danger');
        statusEl.classList.add(ok ? 'alert-success' : 'alert-danger');
        statusEl.textContent = message;
    }

    async function readApiResponse(response) {
        const raw = await response.text();
        if (!raw || raw.trim() === '') {
            throw new Error(`Servidor respondeu vazio (HTTP ${response.status}).`);
        }

        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error(`Resposta invalida do servidor (HTTP ${response.status}).`);
        }

        if (!response.ok) {
            throw new Error(data.error || `Erro HTTP ${response.status}.`);
        }

        return data;
    }

    function setManualSetpointEnabled(ctrl, enabled) {
        if (ctrl === 1) {
            if (cloroSetpointInput) cloroSetpointInput.disabled = !enabled;
            if (cloroSetpointBtn) cloroSetpointBtn.disabled = !enabled;
        }
    }

    function updateHistoryFreshnessWarning(historyRows) {
        if (!historyStaleWarning) return;
        if (!Array.isArray(historyRows) || historyRows.length === 0) {
            historyStaleWarning.classList.remove('d-none');
            historyStaleWarning.textContent = 'Sem registos no histórico para o período selecionado.';
            return;
        }

        const latestTs = historyRows[historyRows.length - 1].log_datetime;
        const latestDate = latestTs ? new Date(String(latestTs).replace(' ', 'T')) : null;
        if (!latestDate || Number.isNaN(latestDate.getTime())) {
            historyStaleWarning.classList.remove('d-none');
            historyStaleWarning.textContent = 'Não foi possível validar a data do último registo do histórico.';
            return;
        }

        const now = new Date();
        const ageMinutes = Math.floor((now.getTime() - latestDate.getTime()) / 60000);

        if (ageMinutes > 15) {
            historyStaleWarning.classList.remove('d-none');
            historyStaleWarning.textContent = `Histórico desatualizado: último registo há ${ageMinutes} min (${latestTs}). O gauge mostra dados ao vivo.`;
            return;
        }

        historyStaleWarning.classList.add('d-none');
        historyStaleWarning.textContent = '';
    }

    async function loadDynamicSetpointStatus() {
        if (!tankId) return;

        try {
            const response = await fetch(`../api/dynamic_setpoint_config.php?tank_id=${tankId}`);
            const data = await readApiResponse(response);
            if (!data.success) {
                throw new Error(data.error || 'Falha ao carregar estado do setpoint dinâmico.');
            }

            const ctrl1Enabled = !!(data.states && data.states['1']);
            cloroManualTargetSetpoint = (data.manual_base_setpoint !== null && data.manual_base_setpoint !== undefined && Number.isFinite(parseFloat(data.manual_base_setpoint)))
                ? parseFloat(data.manual_base_setpoint)
                : null;

            if (cloroDynamicToggle) cloroDynamicToggle.checked = ctrl1Enabled;

            setManualSetpointEnabled(1, !ctrl1Enabled);
            updateGauges();
        } catch (error) {
            console.error(error.message);
        }
    }

    async function saveDynamicSetpointStatus(ctrl, enabled, statusEl) {
        try {
            const response = await fetch('../api/dynamic_setpoint_config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tank_id: tankId,
                    ctrl,
                    enabled
                })
            });
            const data = await readApiResponse(response);

            if (!data.success) {
                throw new Error(data.error || 'Falha ao alterar modo dinâmico.');
            }

            setManualSetpointEnabled(ctrl, !enabled);
            showGaugeSetpointStatus(statusEl, enabled ? 'Setpoint dinâmico ativado.' : 'Setpoint dinâmico desativado.', true);
        } catch (error) {
            setManualSetpointEnabled(ctrl, true);
            if (ctrl === 1 && cloroDynamicToggle) cloroDynamicToggle.checked = false;
            showGaugeSetpointStatus(statusEl, error.message, false);
        }
    }

    async function applyRemoteSetpoint(ctrl, inputEl, buttonEl, statusEl, idleLabel) {
        const valInput = inputEl ? inputEl.value.trim().replace(',', '.') : '';
        const val = parseFloat(valInput);

        if (!Number.isFinite(val)) {
            showGaugeSetpointStatus(statusEl, 'Valor invalido. Introduza um numero valido.', false);
            return;
        }

        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.textContent = 'A aplicar...';
        }

        try {
            const response = await fetch('../api/set_controller_setpoint.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tank_id: tankId,
                    ctrl,
                    val
                })
            });

            const data = await readApiResponse(response);
            if (!data.success) {
                throw new Error(data.error || 'Falha ao aplicar setpoint remoto.');
            }

            if (ctrl === 1) {
                cloroManualTargetSetpoint = val;
            }

            showGaugeSetpointStatus(statusEl, data.message || 'Setpoint aplicado com sucesso.', true);
            updateGauges();
        } catch (error) {
            showGaugeSetpointStatus(statusEl, error.message, false);
        } finally {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.textContent = idleLabel;
            }
        }
    }

    if (cloroSetpointForm) {
        cloroSetpointForm.addEventListener('submit', function(event) {
            event.preventDefault();
            applyRemoteSetpoint(1, cloroSetpointInput, cloroSetpointBtn, cloroSetpointStatus, 'Aplicar');
        });
    }

    if (phSetpointForm) {
        phSetpointForm.addEventListener('submit', function(event) {
            event.preventDefault();
            applyRemoteSetpoint(2, phSetpointInput, phSetpointBtn, phSetpointStatus, 'Aplicar');
        });
    }

    if (cloroDynamicToggle) {
        cloroDynamicToggle.addEventListener('change', function() {
            saveDynamicSetpointStatus(1, cloroDynamicToggle.checked, cloroSetpointStatus);
        });
    }
    let phHistoryChart, cloroHistoryChart;
    let tempGauge, phGauge, cloroGauge;
    let cloroNotes = [];
    let cloroHistoryTimestamps = [];
    let cloroHistoryValues = [];
    let cloroManualTargetSetpoint = null;

    function formatNumberOrNA(value, decimals = 2) {
        const n = parseFloat(value);
        if (!Number.isFinite(n)) return 'N/A';
        return n.toFixed(decimals);
    }

    // Função para criar um manómetro (gauge)
    function createGauge(ctx, label, min, max, lim_min, lim_max, value) {
		const displayValue = Math.min(value, max);
        return new Chart(ctx, {
            type: 'gauge',
            data: {
                datasets: [{
                    value: displayValue,
                    minValue: min,
					maxValue: max,
                    data: [min + lim_min, lim_max, max],
                    backgroundColor: ['#dc3545', '#198754', '#dc3545'], // Verde, Amarelo, Vermelho
                }]
            },
            options: {
            responsive: true,
            title: { display: false },
            layout: { padding: { bottom: 20 } },
            needle: { radiusPercentage: 2, widthPercentage: 3, lengthPercentage: 80, color: 'rgba(0, 0, 0, 1)' },
            // Mostra o valor real (sem o corte) no rótulo, para não esconder a informação
            valueLabel: { 
                display: true, 
                fontSize: 20, 
                color: 'white',
                formatter: function(val) {
                    return parseFloat(value).toFixed(2);
                }
            }
        }
        });
    }

    // Função para criar o gráfico de histórico
    function createDualAxisHistoryChart(ctx, datasets) {
        return new Chart(ctx, {
            type: 'bar',
            data: {
                datasets: datasets // Passa o array de datasets diretamente
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    xAxes: [{
                        type: 'time',
                        time: {
                            displayFormats: {
                                hour: 'dd/MM/yy HH:mm',
                                minute: 'dd/MM/yy HH:mm',
                                second: 'dd/MM/yy HH:mm:ss'
                            },
                            tooltipFormat: 'dd/MM/yy HH:mm:ss'
                        },
                        scaleLabel: {
                            display: true,
                            labelString: 'Data/Hora'
                        }
                    }],
                    yAxes: [
                        { id: 'y-axis-line', type: 'linear', position: 'left' },
                        { id: 'y-axis-bar', type: 'linear', position: 'right', ticks: { min: 0, max: 100, callback: function(value) { return value + "%" } } }
                    ]
                },
                plugins: {
                    zoom: {
                        pan: { enabled: true, mode: 'x' },
                        zoom: { enabled: true, mode: 'x' }
                    }
                }
            }
        });
    }
	
    // NOVA FUNÇÃO PARA ATUALIZAR OS GAUGES EM TEMPO REAL
	// This function replaces the existing updateGauges function in your script
	async function updateGauges() {
	    if (!controllerIp) return; // Does nothing if the tank has no IP
	    try {
	        const response = await fetch(`get_controller_data.php?ip=${controllerIp}`);
	        const data = await response.json();
	        if (data.error) throw new Error(data.error);
	
	        // ATTENTION: The keys here ('ph', 'cloro_livre', 'temperatura', 'ph_setpoint', 
	        // 'estado', and 'disturbio') must exactly match your XML/JSON file from the controller.
	
	        // Update Temperature Gauge
	        const tempValue = data.temperature;
	        if (!tempGauge) {
	            tempGauge = createGauge(document.getElementById('tempGauge').getContext('2d'), 'Temp', 0, 40, 0, 33, tempValue);
	        } else {
				tempGauge.data.datasets[0].value = tempValue;
                tempGauge.options.valueLabel.formatter = () => parseFloat(tempValue).toFixed(1);
                    tempGauge.update();	        }
	        
	        // Update pH Gauge and its Details Box
	        const phValue = data.pH;
	        const phSetpoint = data.C2SetPoint;
	        const ph_controllerState = data.C2Value;
	        const ph_disturbance = data.C2Disturbance;
			
			let ph_formattedState = 'N/A';
            if (ph_controllerState !== null && !isNaN(parseFloat(ph_controllerState))) {
                ph_formattedState = parseFloat(ph_controllerState).toFixed(0) + '%';
            }
	        
	        if (!phGauge) {
	            phGauge = createGauge(document.getElementById('phGauge').getContext('2d'), 'pH', 6, 9, 1, 7.8, phValue);
	        } else {
				phGauge.data.datasets[0].value = phValue;
                phGauge.options.valueLabel.formatter = () => parseFloat(phValue).toFixed(2);
                phGauge.update();
	        }
	        document.getElementById('ph-details').innerHTML = `
	            <div class="detail-row"><span>Setpoint:</span> <strong>${phSetpoint || 'N/A'}</strong></div>
	            <div class="detail-row"><span>Dosagem:</span> <strong>${ph_formattedState}</strong></div>
	            <div class="detail-row"><span>Distúrbio:</span> <strong>${ph_disturbance || 'N/A'}</strong></div>
	        `;

            if (phSetpointInput && document.activeElement !== phSetpointInput && phSetpointInput.value.trim() === '' && phSetpoint !== null && phSetpoint !== undefined) {
                phSetpointInput.value = phSetpoint;
            }
	        
	        // Update Chlorine Gauge and its Details Box
	        const cloroValue = data.freeChlorine;
	        const cloroSetpoint = data.C1SetPoint;
			const cl_controllerState = data.C1Value;
	        const cl_disturbance = data.C1Disturbance;
			
			let cl_formattedState = 'N/A';
            if (cl_controllerState !== null && !isNaN(parseFloat(cl_controllerState))) {
                cl_formattedState = parseFloat(cl_controllerState).toFixed(0) + '%';
            }

	        
	        if (!cloroGauge) {
	            cloroGauge = createGauge(document.getElementById('cloroGauge').getContext('2d'), 'Cloro', 0, 5, 1, 3, cloroValue);
	        } else {
				cloroGauge.data.datasets[0].value = cloroValue;
                cloroGauge.options.valueLabel.formatter = () => parseFloat(cloroValue).toFixed(2);
                cloroGauge.update();
	        }
	        // This will be updated with PID data from the database by the fetchHistory function
	        document.getElementById('cloro-details').innerHTML = `
                <div class="detail-row"><span>Setpoint (controlador):</span> <strong>${formatNumberOrNA(cloroSetpoint)}</strong></div>
                <div class="detail-row"><span>SP alvo (manual):</span> <strong>${formatNumberOrNA(cloroManualTargetSetpoint)}</strong></div>
	            <div class="detail-row"><span>Dosagem:</span> <strong>${cl_formattedState}</strong></div>
	            <div class="detail-row"><span>Distúrbio:</span> <strong>${cl_disturbance || 'N/A'}</strong></div>
	        `;

            if (cloroSetpointInput && document.activeElement !== cloroSetpointInput && cloroSetpointInput.value.trim() === '' && cloroSetpoint !== null && cloroSetpoint !== undefined) {
                cloroSetpointInput.value = cloroSetpoint;
            }
	
	    } catch (error) {
	        console.error("Error updating gauges:", error);
	        // Add a visual error indicator here if you want
	    }
	}

    // Função para ir buscar o histórico
        async function fetchHistory(startDate, endDate) {
        try {
            // 1. Buscar histórico
            const url = `../api/get_pool_history.php?id=${tankId}&start_date=${startDate}&end_date=${endDate}`;
            const response = await fetch(url);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            updateHistoryFreshnessWarning(data.history);

            // Prepara os dados para os gráficos
            cloroHistoryTimestamps = data.history.map(rec => rec.log_datetime);
            cloroHistoryValues = data.history.map(rec => rec.chlorine_value);
            // Datasets com x = log_datetime
            const phDatasetLine = data.history.map(rec => ({ x: rec.log_datetime, y: rec.ph_value }));
            const phDatasetDosagem = data.history.map(rec => ({ x: rec.log_datetime, y: parseFloat(rec.ph_controller_state) || 0 }));
            const phDatasetSetpoint = data.history.map(rec => ({ x: rec.log_datetime, y: rec.ph_setpoint }));
            const cloroDatasetLine = data.history.map(rec => ({ x: rec.log_datetime, y: rec.chlorine_value }));
            const cloroDatasetDosagem = data.history.map(rec => ({ x: rec.log_datetime, y: parseFloat(rec.cl_controller_state) || 0 }));
            const cloroDatasetSetpoint = data.history.map(rec => ({ x: rec.log_datetime, y: rec.chlorine_setpoint }));

            // 2. Buscar notas deste tanque
            const notesResp = await fetch(`../api/get_controller_notes.php?tank_id=${tankId}`);
            const notesData = await notesResp.json();
            cloroNotes = notesData.notes || [];

            // 3. Destruir gráficos antigos
            if (phHistoryChart) phHistoryChart.destroy();
            if (cloroHistoryChart) cloroHistoryChart.destroy();

            // 4. Criar datasets
            const phDatasets = [
                { type: 'line', label: 'pH (Valor)', data: phDatasetLine, borderColor: 'rgba(54, 162, 235, 1)', yAxisID: 'y-axis-line', fill: false, tension: 0.1 },
                { type: 'bar', label: 'Dosagem', data: phDatasetDosagem, backgroundColor: 'rgba(54, 162, 235, 0.2)', yAxisID: 'y-axis-bar' },
                { type: 'line', label: 'Setpoint', data: phDatasetSetpoint, borderColor: 'rgba(255, 99, 132, 0.8)', borderWidth: 2, yAxisID: 'y-axis-line', fill: false, pointRadius: 0 }
            ];
            // Adiciona notas ao gráfico de Cloro (exibe ponto destacado se houver nota)
            // Mapeia as notas para os índices corretos do histórico usando log_datetime (NÃO usar history_index)
            function findIndexByDatetime(target, arr) {
                const targetTime = new Date(target).getTime();
                for (let i = 0; i < arr.length; i++) {
                    if (Math.abs(new Date(arr[i]).getTime() - targetTime) < 1000) return i;
                }
                return -1;
            }
            // Só usa log_datetime para desenhar as bolas amarelas
            const notePointIndices = cloroNotes.map(n => n.log_datetime ? findIndexByDatetime(n.log_datetime, cloroHistoryTimestamps) : -1).filter(idx => idx !== -1);
            const notePointData = notePointIndices.map(idx => ({ x: idx, y: cloroHistoryValues[idx] }));
            const cloroDatasets = [
                { type: 'line', label: 'Cloro (Valor)', data: cloroDatasetLine, borderColor: 'rgba(75, 192, 192, 1)', yAxisID: 'y-axis-line', fill: false, tension: 0.1 },
                { type: 'bar', label: 'Dosagem', data: cloroDatasetDosagem, backgroundColor: 'rgba(75, 192, 192, 0.2)', yAxisID: 'y-axis-bar' },
                { type: 'line', label: 'Setpoint', data: cloroDatasetSetpoint, borderColor: 'rgba(255, 99, 132, 0.8)', borderWidth: 2, yAxisID: 'y-axis-line', fill: false, pointRadius: 0 }
            ];
            // Adiciona o dataset das notas por último para garantir que fique na frente
            cloroDatasets.push({
                type: 'scatter',
                label: 'Notas',
                data: notePointIndices.map(idx => ({ x: cloroHistoryTimestamps[idx], y: cloroHistoryValues[idx] })),
                backgroundColor: 'yellow',
                borderColor: 'orange',
                pointRadius: 7,
                yAxisID: 'y-axis-line',
                showLine: false,
                hidden: notePointIndices.length === 0
            });

            phHistoryChart = createDualAxisHistoryChart(document.getElementById('phHistoryChart').getContext('2d'), phDatasets);
            cloroHistoryChart = createDualAxisHistoryChart(document.getElementById('cloroHistoryChart').getContext('2d'), cloroDatasets);

            // 5. Clique no gráfico de Cloro para selecionar ponto
            document.getElementById('cloroHistoryChart').onclick = function(evt) {
                if (!cloroHistoryChart) return;
                // Compatível com Chart.js 2.x
                const points = cloroHistoryChart.getElementAtEvent(evt);
                if (points && points.length > 0) {
                    const idx = points[0]._index !== undefined ? points[0]._index : points[0].index;
                    const datasetIndex = points[0]._datasetIndex !== undefined ? points[0]._datasetIndex : points[0].datasetIndex;
                    const ds = cloroHistoryChart.data.datasets[datasetIndex];
                    if ((ds && ds.label && ds.label.toLowerCase().includes('cloro') && ds.type === 'line') || (ds && ds.label === 'Notas')) {
                        showNoteModal(idx);
                    }
                }
            };

        } catch (error) {
            console.error("Erro ao carregar histórico:", error);
        }
    }
    
    document.getElementById('date-range-form').addEventListener('submit', function(e) {
        e.preventDefault();
        fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    });

    // Função para mostrar o modal de nota
    window.showNoteModal = function(idx) {
        // Mostra info do ponto
        const ts = cloroHistoryTimestamps[idx];
        const val = cloroHistoryValues[idx];
        // Procura nota pelo timestamp (robusto)
        const noteObj = cloroNotes.find(n => Math.abs(new Date(n.log_datetime).getTime() - new Date(ts).getTime()) < 1000);
        // Formata data/hora completa para exibir no modal
        const tsDate = new Date(ts);
        const tsStr = tsDate.toLocaleString('pt-PT', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('selectedPointInfo').innerHTML =
            `<b>Data/hora:</b> ${tsStr}<br><b>Valor:</b> ${val}` + (noteObj ? `<br><b>Nota existente:</b> <span class='text-warning'>${noteObj.note}</span>` : '');
        document.getElementById('noteText').value = noteObj ? noteObj.note : '';
        document.getElementById('noteIndex').value = idx;

        // Elementos do modal
        const btnGuardar = document.getElementById('btnGuardarNota');
        const btnCancelar = document.querySelector('#noteModal .btn-secondary');
        const modalTitle = document.getElementById('noteModalLabel');

        if (noteObj) {
            // Se já existe nota: muda título, esconde Guardar, muda Cancelar para Sair
            if (modalTitle) modalTitle.textContent = 'Nota do Ponto Selecionado';
            if (btnGuardar) btnGuardar.style.display = 'none';
            if (btnCancelar) btnCancelar.textContent = 'Sair';
        } else {
            // Se não existe nota: título padrão, mostra Guardar, Cancelar normal
            if (modalTitle) modalTitle.textContent = 'Adicionar Nota ao Ponto Selecionado';
            if (btnGuardar) {
                btnGuardar.style.display = '';
                btnGuardar.disabled = false;
                btnGuardar.title = '';
            }
            if (btnCancelar) btnCancelar.textContent = 'Cancelar';
        }

        // Abre modal
        const modal = new bootstrap.Modal(document.getElementById('noteModal'));
        modal.show();
    };

    // Submeter nota
    document.getElementById('noteForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const idx = parseInt(document.getElementById('noteIndex').value);
        const note = document.getElementById('noteText').value.trim();
        if (!note) return;
        const log_datetime = cloroHistoryTimestamps[idx];
        // Não precisa mais verificar, pois o botão já está desabilitado se existir nota
        // POST para API
        const resp = await fetch('../api/add_controller_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tank_id: tankId, log_datetime, note })
        });
        const data = await resp.json();
        if (data.success) {
            // Fecha modal e recarrega histórico para atualizar notas
            bootstrap.Modal.getInstance(document.getElementById('noteModal')).hide();
            fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
        } else {
            alert('Erro ao guardar nota: ' + (data.error || 'Erro desconhecido'));
        }
    });

    // Carga inicial
    loadDynamicSetpointStatus();
    updateGauges();
    fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    // Inicia o ciclo de atualização para os gauges a cada 10 segundos
    setInterval(updateGauges, 10000);
    // Atualiza também o histórico periodicamente para reduzir discrepância visual com os gauges.
    setInterval(() => {
        fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    }, 60000);
});
</script>



<?php
require_once '../footer.php';
?>


