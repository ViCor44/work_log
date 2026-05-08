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
                <a href="dynamic_setpoint_params.php?id=<?= $tank_id ?>" class="btn btn-outline-info" title="Configurar parâmetros do setpoint dinâmico">⚙ Parâmetros Dinâmicos</a>
	        <a href="dashboard.php" class="btn btn-secondary">Voltar ao Dashboard</a>
	    </div>
	</div>

    <div class="row">
        <div class="col-lg-3">
            <div class="row">
			    <div class="col-12 mb-3">
                    <div class="gauge-card">
                        <h5 class="d-flex justify-content-between align-items-center">Cloro Livre (mg/L)
                            <button type="button" class="btn btn-sm btn-outline-info py-0 px-1" style="font-size:0.7rem" onclick="openControllerModal()" title="Ver detalhes do controlador"><i class="fas fa-info-circle"></i></button>
                        </h5>
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
                            <div class="dynamic-switch-row" id="ha-toggle-row" style="display:none;">
                                <span class="small" title="Ativa parâmetros mais agressivos (offsets maiores) para dias com maior afluência de banhistas.">🏊 Alta afluência</span>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cloro-ha-toggle">
                                    <label class="form-check-label" for="cloro-ha-toggle" id="cloro-ha-label">Normal</label>
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3" id="history-filter-bar">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Intervalo rápido">
                        <button type="button" class="btn btn-info" id="range-24h">24h</button>
                        <button type="button" class="btn btn-outline-info" id="range-7d">7 dias</button>
                        <button type="button" class="btn btn-outline-info" id="range-30d">30 dias</button>
                    </div>
                    <form class="d-flex align-items-center gap-2" id="date-range-form">
                        <input type="date" class="form-control form-control-sm" id="start_date" value="<?= date('Y-m-d') ?>" style="width:145px">
                        <span class="text-secondary">—</span>
                        <input type="date" class="form-control form-control-sm" id="end_date" value="<?= date('Y-m-d') ?>" style="width:145px">
                        <button type="submit" class="btn btn-sm btn-info" title="Pesquisar"><i class="fas fa-search"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear-range" title="Limpar filtro"><i class="fas fa-times"></i></button>
                    </form>
                </div>
                <div id="history-stale-warning" class="alert alert-warning py-2 px-3 d-none" role="alert"></div>

                <!-- Cards estatísticos Cloro Livre -->
                <div class="row g-2 mb-3" id="cloro-stats-row" style="display:none!important">
                    <div class="col-4">
                        <div class="card text-center py-2" style="background:#1a2a35;border:1px solid #2a4a5a">
                            <div class="text-white-50" style="font-size:0.7rem;letter-spacing:1px">MÁX</div>
                            <div id="stat-cloro-max" class="fw-bold" style="font-size:1.3rem;color:#6ee0a0">--</div>
                            <div class="text-white-50" style="font-size:0.7rem">mg/L</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card text-center py-2" style="background:#1a2a35;border:1px solid #2a4a5a">
                            <div class="text-white-50" style="font-size:0.7rem;letter-spacing:1px">MÉD</div>
                            <div id="stat-cloro-avg" class="fw-bold" style="font-size:1.3rem;color:#4bc8c8">--</div>
                            <div class="text-white-50" style="font-size:0.7rem">mg/L</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card text-center py-2" style="background:#1a2a35;border:1px solid #2a4a5a">
                            <div class="text-white-50" style="font-size:0.7rem;letter-spacing:1px">MÍN</div>
                            <div id="stat-cloro-min" class="fw-bold" style="font-size:1.3rem;color:#f08060">--</div>
                            <div class="text-white-50" style="font-size:0.7rem">mg/L</div>
                        </div>
                    </div>
                </div>

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

<!-- Modal detalhe Controlador Grundfos DID -->
<div class="modal fade" id="controllerDetailModal" tabindex="-1" aria-labelledby="controllerDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="background:#212529;color:#dee2e6;border:1px solid #495057">
            <div class="modal-header" style="border-bottom:1px solid #495057">
                <div>
                    <h5 class="modal-title mb-0" id="controllerDetailModalLabel">Detalhe do Controlador</h5>
                    <div id="controllerModalSubtitle" class="text-secondary" style="font-size:0.8rem"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="controllerModalBody">
                <div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer" style="border-top:1px solid #495057">
                <span class="text-secondary me-auto" style="font-size:0.75rem" id="controllerModalTs"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
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

            const haEnabled = !!(data.high_attendance);
            const haToggle = document.getElementById('cloro-ha-toggle');
            const haLabel = document.getElementById('cloro-ha-label');
            const haRow = document.getElementById('ha-toggle-row');
            if (haToggle) haToggle.checked = haEnabled;
            if (haLabel) haLabel.textContent = haEnabled ? 'Alta afluência' : 'Normal';
            if (haRow) haRow.style.display = ctrl1Enabled ? 'flex' : 'none';

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

            if (data.manual_base_setpoint !== null && data.manual_base_setpoint !== undefined && Number.isFinite(parseFloat(data.manual_base_setpoint))) {
                cloroManualTargetSetpoint = parseFloat(data.manual_base_setpoint);
            }

            setManualSetpointEnabled(ctrl, !enabled);
            const haRow = document.getElementById('ha-toggle-row');
            if (ctrl === 1 && haRow) haRow.style.display = enabled ? 'flex' : 'none';
            showGaugeSetpointStatus(statusEl, data.message || (enabled ? 'Setpoint dinâmico ativado.' : 'Setpoint dinâmico desativado.'), true);
            updateGauges();
        } catch (error) {
            setManualSetpointEnabled(ctrl, true);
            if (ctrl === 1 && cloroDynamicToggle) cloroDynamicToggle.checked = false;
            showGaugeSetpointStatus(statusEl, error.message, false);
        }
    }

    async function saveHighAttendance(enabled, statusEl) {
        try {
            const response = await fetch('../api/dynamic_setpoint_config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tank_id: tankId, type: 'high_attendance', enabled })
            });
            const data = await readApiResponse(response);
            if (!data.success) throw new Error(data.error || 'Erro ao alterar modo de alta afluência.');
            const haLabel = document.getElementById('cloro-ha-label');
            if (haLabel) haLabel.textContent = enabled ? 'Alta afluência' : 'Normal';
            showGaugeSetpointStatus(statusEl, enabled ? '🏊 Modo alta afluência ativado.' : 'Modo normal restaurado.', true);
        } catch (error) {
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

    const cloroHaToggle = document.getElementById('cloro-ha-toggle');
    if (cloroHaToggle) {
        cloroHaToggle.addEventListener('change', function() {
            saveHighAttendance(cloroHaToggle.checked, cloroSetpointStatus);
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
                        ticks: { fontColor: '#ecf0f1', fontSize: 11 },
                        gridLines: { color: 'rgba(255,255,255,0.1)' },
                        scaleLabel: {
                            display: true,
                            labelString: 'Data/Hora',
                            fontColor: '#ecf0f1',
                            fontSize: 12
                        }
                    }],
                    yAxes: [
                        {
                            id: 'y-axis-line',
                            type: 'linear',
                            position: 'left',
                            ticks: { fontColor: '#ecf0f1', fontSize: 12, fontStyle: 'bold' },
                            gridLines: { color: 'rgba(255,255,255,0.12)' },
                            scaleLabel: { display: true, labelString: 'Valor', fontColor: '#ecf0f1', fontSize: 12 }
                        },
                        {
                            id: 'y-axis-bar',
                            type: 'linear',
                            position: 'right',
                            ticks: { min: 0, max: 100, fontColor: '#7ecfff', fontSize: 12, fontStyle: 'bold', callback: function(value) { return value + '%' } },
                            gridLines: { drawOnChartArea: false, color: 'rgba(126,207,255,0.15)' },
                            scaleLabel: { display: true, labelString: 'Dosagem (%)', fontColor: '#7ecfff', fontSize: 12, fontStyle: 'bold' }
                        }
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
	let lastControllerData = null;
	async function updateGauges() {
	    if (!controllerIp) return; // Does nothing if the tank has no IP
	    try {
	        const response = await fetch(`get_controller_data.php?ip=${controllerIp}`);
	        const data = await response.json();
	        if (data.error) throw new Error(data.error);
	        lastControllerData = data;
	        // The keys here ('ph', 'cloro_livre', 'temperatura', 'ph_setpoint',
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
	        const cloroValueRaw = data.freeChlorine;
	        const cloroValue = (cloroValueRaw !== null && parseFloat(cloroValueRaw) <= -1) ? null : cloroValueRaw;
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
        async function fetchHistory(startDate, endDate, extraParams) {
        extraParams = extraParams || {};
        try {
            // 1. Buscar histórico
            let url = `../api/get_pool_history.php?id=${tankId}&start_date=${startDate}&end_date=${endDate}`;
            var _epKeys = Object.keys(extraParams); for (var _i = 0; _i < _epKeys.length; _i++) { url += '&' + _epKeys[_i] + '=' + encodeURIComponent(extraParams[_epKeys[_i]]); }
            const response = await fetch(url);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            updateHistoryFreshnessWarning(data.history);

            // Prepara os dados para os gráficos
            cloroHistoryTimestamps = data.history.map(rec => rec.log_datetime);
            cloroHistoryValues = data.history.map(rec => rec.chlorine_value);

            // Calcular e mostrar estatísticas Cloro Livre (-1.00 = defeito do dispositivo, ignorado)
            const cloroNums = cloroHistoryValues.map(v => parseFloat(v)).filter(v => !isNaN(v) && v !== null && v > -1);
            const statsRow = document.getElementById('cloro-stats-row');
            if (cloroNums.length > 0) {
                const cMax = Math.max(...cloroNums);
                const cMin = Math.min(...cloroNums);
                const cAvg = cloroNums.reduce((a, b) => a + b, 0) / cloroNums.length;
                document.getElementById('stat-cloro-max').textContent = cMax.toFixed(2);
                document.getElementById('stat-cloro-avg').textContent = cAvg.toFixed(2);
                document.getElementById('stat-cloro-min').textContent = cMin.toFixed(2);
                statsRow.style.removeProperty('display');
            } else {
                statsRow.style.setProperty('display', 'none', 'important');
            }

            // Datasets com x = log_datetime
            const phDatasetLine = data.history.map(rec => ({ x: rec.log_datetime, y: rec.ph_value }));
            const phDatasetDosagem = data.history.map(rec => ({ x: rec.log_datetime, y: parseFloat(rec.ph_controller_state) || 0 }));
            const phDatasetSetpoint = data.history.map(rec => ({ x: rec.log_datetime, y: rec.ph_setpoint }));
            const cloroDatasetLine = data.history.map(rec => ({ x: rec.log_datetime, y: parseFloat(rec.chlorine_value) <= -1 ? null : rec.chlorine_value }));
            const cloroDatasetDosagem = data.history.map(rec => ({ x: rec.log_datetime, y: parseFloat(rec.cl_controller_state) || 0 }));
            const cloroDatasetSetpoint = data.history.map(rec => ({ x: rec.log_datetime, y: rec.chlorine_setpoint }));
            const cloroDatasetBaseSetpoint = (cloroManualTargetSetpoint !== null)
                ? data.history.map(rec => ({ x: rec.log_datetime, y: cloroManualTargetSetpoint }))
                : [];

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
                { type: 'bar', label: 'Dosagem (%)', data: phDatasetDosagem, backgroundColor: 'rgba(54, 162, 235, 0.55)', borderColor: 'rgba(54, 162, 235, 0.85)', borderWidth: 1, yAxisID: 'y-axis-bar' },
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
                { type: 'bar', label: 'Dosagem (%)', data: cloroDatasetDosagem, backgroundColor: 'rgba(75, 192, 192, 0.55)', borderColor: 'rgba(75, 192, 192, 0.85)', borderWidth: 1, yAxisID: 'y-axis-bar' },
                { type: 'line', label: 'Setpoint', data: cloroDatasetSetpoint, borderColor: 'rgba(255, 99, 132, 0.8)', borderWidth: 2, yAxisID: 'y-axis-line', fill: false, pointRadius: 0 },
                {
                    type: 'line',
                    label: 'SP base (manual)',
                    data: cloroDatasetBaseSetpoint,
                    borderColor: 'rgba(255, 214, 10, 1)',
                    backgroundColor: 'rgba(255, 214, 10, 0.15)',
                    borderWidth: 3,
                    borderDash: [8, 4],
                    yAxisID: 'y-axis-line',
                    fill: false,
                    pointRadius: 0,
                    hidden: cloroDatasetBaseSetpoint.length === 0
                }
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
    
    // ── Modal detalhe Controlador ─────────────────────────────────────────────
    function ctrlStatusText(raw) {
        const v = parseInt(raw);
        if (isNaN(v)) return '<span class="badge bg-secondary">N/D</span>';
        if (v === 0xFFFF) return '<span class="badge bg-secondary">Desativado</span>';
        if (v === 0) return '<span class="badge bg-success">Sem erros</span>';
        const errs = [];
        if (v & 0x0001) errs.push('Erro geral');
        if (v & 0x0002) errs.push('Erro de entrada');
        if (v & 0x0004) errs.push('Erro de saída');
        if (v & 0x0008) errs.push('Erro de perturbação');
        return '<span class="badge bg-danger">' + errs.join(', ') + '</span>';
    }
    function ctrlRunStatusText(raw) {
        const map = { 0: ['Parado','secondary'], 1: ['Em funcionamento','success'], 2: ['Hold / Manual','warning'] };
        const v = parseInt(raw);
        if (isNaN(v) || !(v in map)) return '<span class="badge bg-secondary">N/D</span>';
        return `<span class="badge bg-${map[v][1]}">${map[v][0]}</span>`;
    }
    function sensorStatusText(raw) {
        const v = parseInt(raw);
        if (isNaN(v)) return '<span class="badge bg-secondary">N/D</span>';
        if (v === 0) return '<span class="badge bg-success">OK</span>';
        const errs = [];
        if (v & 0x0001) errs.push('Erro geral');
        if (v & 0x0002) errs.push('Calibração');
        if (v & 0x0004) errs.push('Sinal baixo');
        if (v & 0x0008) errs.push('Sinal alto');
        if (errs.length === 0) errs.push('Erro 0x' + v.toString(16).toUpperCase());
        return '<span class="badge bg-warning text-dark">' + errs.join(', ') + '</span>';
    }
    function ctrlRow(label, value, unit) {
        const v = (value !== null && value !== undefined && !isNaN(parseFloat(value)))
            ? parseFloat(value).toFixed(2) + (unit ? ' ' + unit : '')
            : 'N/D';
        return `<div class="d-flex justify-content-between mb-2"><span class="text-white-50">${label}</span><span class="font-monospace fw-bold">${v}</span></div>`;
    }
    function buildControllerSection(title, color, processVal, processUnit, setpoint, output, disturbance, status, runStatus, sensorStatus) {
        const sensorRow = (sensorStatus !== null && sensorStatus !== undefined)
            ? `<div class="d-flex justify-content-between mb-2"><span class="text-white-50">Estado sensor</span><span>${sensorStatusText(sensorStatus)}</span></div>`
            : '';
        return `
        <div class="p-3 rounded mb-3" style="background:#2b3035;border:1px solid #495057">
            <h6 style="color:${color}" class="mb-3"><i class="fas fa-sliders-h me-1"></i>${title}</h6>
            ${ctrlRow('Valor processo', processVal, processUnit)}
            ${ctrlRow('Setpoint', setpoint, processUnit)}
            ${ctrlRow('Saída / Dosagem', output, '%')}
            ${ctrlRow('Perturbação', disturbance, '')}
            ${sensorRow}
            <div class="d-flex justify-content-between mb-2"><span class="text-white-50">Estado controlador</span><span>${ctrlStatusText(status)}</span></div>
            <div class="d-flex justify-content-between"><span class="text-white-50">Operacional</span><span>${ctrlRunStatusText(runStatus)}</span></div>
        </div>`;
    }
    function openControllerModal() {
        const modal = new bootstrap.Modal(document.getElementById('controllerDetailModal'));
        const bodyEl = document.getElementById('controllerModalBody');
        const tsEl   = document.getElementById('controllerModalTs');
        const subEl  = document.getElementById('controllerDetailModalLabel');

        subEl.textContent = '<?= htmlspecialchars($tank_name) ?> — Grundfos DID';
        document.getElementById('controllerModalSubtitle').textContent = '<?= $controller_ip ?>';

        if (!lastControllerData) {
            bodyEl.innerHTML = '<div class="text-center p-4 text-secondary">Dados ainda não carregados. Aguarde o próximo ciclo.</div>';
            modal.show();
            return;
        }
        const d = lastControllerData;
        const alarmeBadge = (d.alarme == 0)
            ? '<span class="badge bg-danger ms-2"><i class="fas fa-exclamation-triangle me-1"></i>ALARME ATIVO</span>'
            : '<span class="badge bg-success ms-2"><i class="fas fa-check-circle me-1"></i>Online</span>';

        bodyEl.innerHTML = `
            <div class="mb-3 p-3 rounded" style="background:#2b3035;border:1px solid #495057">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold">Estado geral</span>${alarmeBadge}
                </div>
                <small class="text-secondary"><?= $controller_ip ?></small>
            </div>
            ${buildControllerSection(
                'Controlador 1 — Cloro Livre', '#5bc8f5',
                (d.C1Process != null ? d.C1Process : (d.xC1Process != null ? d.xC1Process : d.freeChlorine)), 'mg/L',
                (d.C1SetPoint != null ? d.C1SetPoint : d.xC1Setpoint),
                (d.C1Value != null ? d.C1Value : d.xC1Value),
                (d.C1Disturbance != null ? d.C1Disturbance : d.xC1Disturbance),
                (d.C1Status != null ? d.C1Status : d.bmC1Status),
                (d.C1RunStatus != null ? d.C1RunStatus : d.bmC1RunStatus),
                (d.bmP1Status != null ? d.bmP1Status : d.P1Status)
            )}
            ${buildControllerSection(
                'Controlador 2 — pH', '#6ee0a0',
                (d.C2Process != null ? d.C2Process : (d.xC2Process != null ? d.xC2Process : d.pH)), '',
                (d.C2SetPoint != null ? d.C2SetPoint : d.xC2Setpoint),
                (d.C2Value != null ? d.C2Value : d.xC2Value),
                (d.C2Disturbance != null ? d.C2Disturbance : d.xC2Disturbance),
                (d.C2Status != null ? d.C2Status : d.bmC2Status),
                (d.C2RunStatus != null ? d.C2RunStatus : d.bmC2RunStatus),
                (d.bmP2Status != null ? d.bmP2Status : d.P2Status)
            )}`;

        tsEl.textContent = 'Última atualização: ' + new Date().toLocaleTimeString('pt-PT');
        modal.show();
    }
    window.openControllerModal = openControllerModal;
    // ─────────────────────────────────────────────────────────────────────────

    document.getElementById('date-range-form').addEventListener('submit', function(e) {
        e.preventDefault();
        activeRange = 'custom';
        setActiveRangeBtn(null);
        fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    });

    let activeRange = '24h';

    function localDateStr(d) {
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
    }
    function localDatetimeStr(d) {
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }
    function todayStr() { return localDateStr(new Date()); }
    function daysAgoStr(n) { const d = new Date(); d.setDate(d.getDate() - n); return localDateStr(d); }

    function loadCurrentHistory() {
        if (activeRange === '24h') {
            const now = new Date();
            const h24ago = new Date(now.getTime() - 24 * 60 * 60 * 1000);
            const s = localDateStr(h24ago);
            const t = localDateStr(now);
            document.getElementById('start_date').value = s;
            document.getElementById('end_date').value = t;
            fetchHistory(s, t, { start_datetime: localDatetimeStr(h24ago), end_datetime: localDatetimeStr(now) });
        } else {
            fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
        }
    }

    function setActiveRangeBtn(activeId) {
        ['range-24h','range-7d','range-30d'].forEach(function(id) {
            const btn = document.getElementById(id);
            if (!btn) return;
            if (id === activeId) {
                btn.classList.remove('btn-outline-info'); btn.classList.add('btn-info');
            } else {
                btn.classList.remove('btn-info'); btn.classList.add('btn-outline-info');
            }
        });
    }

    document.getElementById('range-24h').addEventListener('click', function() {
        if (activeRange === '24h') {
            // Desligar: volta a mostrar desde as 00:00 do dia atual
            activeRange = 'custom';
            const t = todayStr();
            document.getElementById('start_date').value = t;
            document.getElementById('end_date').value = t;
            setActiveRangeBtn(null);
            fetchHistory(t, t);
        } else {
            activeRange = '24h';
            setActiveRangeBtn('range-24h');
            loadCurrentHistory();
        }
    });
    document.getElementById('range-7d').addEventListener('click', function() {
        activeRange = '7d';
        const s = daysAgoStr(6), t = todayStr();
        document.getElementById('start_date').value = s;
        document.getElementById('end_date').value = t;
        setActiveRangeBtn('range-7d');
        fetchHistory(s, t);
    });
    document.getElementById('range-30d').addEventListener('click', function() {
        activeRange = '30d';
        const s = daysAgoStr(29), t = todayStr();
        document.getElementById('start_date').value = s;
        document.getElementById('end_date').value = t;
        setActiveRangeBtn('range-30d');
        fetchHistory(s, t);
    });
    document.getElementById('btn-clear-range').addEventListener('click', function() {
        activeRange = '24h';
        setActiveRangeBtn('range-24h');
        loadCurrentHistory();
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
            loadCurrentHistory();
        } else {
            alert('Erro ao guardar nota: ' + (data.error || 'Erro desconhecido'));
        }
    });

    // Carga inicial
    loadDynamicSetpointStatus();
    updateGauges();
    setActiveRangeBtn('range-24h');
    loadCurrentHistory();
    // Inicia o ciclo de atualização para os gauges a cada 10 segundos
    setInterval(updateGauges, 10000);
    // Atualiza também o histórico periodicamente para reduzir discrepância visual com os gauges.
    setInterval(loadCurrentHistory, 60000);
});
</script>



<?php
require_once '../footer.php';
?>


