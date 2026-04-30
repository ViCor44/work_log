<?php
require_once '../header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { die("Tanque inválido."); }
$tank_id = (int)$_GET['id'];

$stmt = $conn->prepare("SELECT name FROM tanks WHERE id = ?");
$stmt->bind_param("i", $tank_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) { die("Tanque não encontrado."); }
$tank_info = $result->fetch_assoc();
$tank_name = $tank_info['name'];
$stmt->close();
?>

<style>
.params-card {
    background: linear-gradient(135deg, #2c3e50, #34495e);
    color: #ecf0f1;
    border: 1px solid #4a6278;
    border-radius: 8px;
    padding: 1.5rem;
}
.params-card label {
    color: #b0bec5;
    font-size: 0.85rem;
    margin-bottom: 2px;
}
.params-card .form-control {
    background: #1e2a35;
    border: 1px solid #4a6278;
    color: #ecf0f1;
    font-size: 0.95rem;
}
.params-card .form-control:focus {
    background: #243342;
    border-color: #5dade2;
    color: #fff;
    box-shadow: 0 0 0 2px rgba(93,173,226,0.25);
}
.param-hint {
    font-size: 0.78rem;
    color: #78909c;
    margin-top: 2px;
}
.section-title {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #5dade2;
    text-transform: uppercase;
    border-bottom: 1px solid #4a6278;
    padding-bottom: 4px;
    margin-bottom: 1rem;
}
.default-badge {
    font-size: 0.75rem;
    color: #95a5a6;
    margin-left: 6px;
}
#toast-params {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
    min-width: 280px;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Parâmetros Setpoint Dinâmico — <?= htmlspecialchars($tank_name) ?></h1>
        <div>
            <a href="view_pool_details.php?id=<?= $tank_id ?>" class="btn btn-secondary">← Voltar ao Tanque</a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="params-card mb-3">
                <p class="mb-3" style="color:#b0bec5; font-size:0.9rem;">
                    Estes parâmetros controlam o algoritmo de setpoint dinâmico para o Controlador 1 (Cloro).
                    Os valores padrão são os valores originais do algoritmo. Qualquer alteração tem efeito imediato no próximo ciclo do worker (5 em 5 min).
                </p>

                <form id="params-form" novalidate>

                    <!-- OFFSET / TENDÊNCIA -->
                    <div class="section-title mt-3">Offsets de Antecipação</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label>Offset de antecipação <span class="default-badge">(padrão: 0.06)</span></label>
                            <input type="number" step="0.01" class="form-control" name="anticipation_offset" id="f_anticipation_offset">
                            <div class="param-hint">Offset base aplicado ao PV na descida/subida.</div>
                        </div>
                        <div class="col-md-4">
                            <label>Offset mínimo <span class="default-badge">(padrão: 0.03)</span></label>
                            <input type="number" step="0.01" class="form-control" name="min_follow_offset" id="f_min_follow_offset">
                            <div class="param-hint">Limite inferior do offset ajustado pela bomba.</div>
                        </div>
                        <div class="col-md-4">
                            <label>Offset máximo <span class="default-badge">(padrão: 0.18)</span></label>
                            <input type="number" step="0.01" class="form-control" name="max_follow_offset" id="f_max_follow_offset">
                            <div class="param-hint">Limite superior do offset — evita sobre-ajuste.</div>
                        </div>
                    </div>

                    <!-- BOMBA -->
                    <div class="section-title mt-3">Controlo por % de Bomba</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label>% Bomba mínima desejada <span class="default-badge">(padrão: 20)</span></label>
                            <input type="number" step="0.5" class="form-control" name="pump_min_target" id="f_pump_min_target">
                            <div class="param-hint">Se bomba &lt; mínimo, aumenta o offset para forçar mais doseagem.</div>
                        </div>
                        <div class="col-md-4">
                            <label>% Bomba máxima desejada <span class="default-badge">(padrão: 35)</span></label>
                            <input type="number" step="0.5" class="form-control" name="pump_max_target" id="f_pump_max_target">
                            <div class="param-hint">Se bomba &gt; máximo, reduz o offset para travar doseagem.</div>
                        </div>
                        <div class="col-md-4">
                            <label>Passo de ajuste por bomba <span class="default-badge">(padrão: 0.02)</span></label>
                            <input type="number" step="0.005" class="form-control" name="pump_adjust_step" id="f_pump_adjust_step">
                            <div class="param-hint">Quanto o offset sobe/desce por cada % de desvio da bomba.</div>
                        </div>
                    </div>

                    <!-- FILTROS / COOLDOWN -->
                    <div class="section-title mt-3">Filtros e Cooldown</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label>Deadband de tendência (mg/L) <span class="default-badge">(padrão: 0.01)</span></label>
                            <input type="number" step="0.001" class="form-control" name="trend_deadband" id="f_trend_deadband">
                            <div class="param-hint">Delta mínimo entre leituras para considerar tendência real. Filtra ruído.</div>
                        </div>
                        <div class="col-md-4">
                            <label>Cooldown entre envios (seg) <span class="default-badge">(padrão: 60)</span></label>
                            <input type="number" step="1" class="form-control" name="cooldown_sec" id="f_cooldown_sec">
                            <div class="param-hint">Segundos mínimos entre dois envios consecutivos ao controlador.</div>
                        </div>
                        <div class="col-md-4">
                            <label>Delta mínimo para enviar (mg/L) <span class="default-badge">(padrão: 0.01)</span></label>
                            <input type="number" step="0.001" class="form-control" name="min_send_delta" id="f_min_send_delta">
                            <div class="param-hint">Só envia ao controlador se o novo SP diferir pelo menos este valor do último enviado.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary px-4">Guardar Parâmetros</button>
                        <button type="button" class="btn btn-outline-warning px-4" id="btn-reset-defaults">↩ Restaurar Defaults</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast de feedback -->
<div id="toast-params" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
        <div class="toast-body fw-bold" id="toast-params-msg"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
</div>

<script>
const TANK_ID = <?= $tank_id ?>;
const API = '../api/dynamic_setpoint_params.php';

const FIELD_NAMES = [
    'anticipation_offset','min_follow_offset','max_follow_offset',
    'pump_min_target','pump_max_target','pump_adjust_step',
    'trend_deadband','cooldown_sec','min_send_delta'
];

function showToast(msg, type = 'success') {
    const el = document.getElementById('toast-params');
    el.classList.remove('bg-success','bg-danger','bg-warning','text-dark');
    if (type === 'success') el.classList.add('bg-success','text-white');
    else if (type === 'danger') el.classList.add('bg-danger','text-white');
    else el.classList.add('bg-warning','text-dark');
    document.getElementById('toast-params-msg').textContent = msg;
    bootstrap.Toast.getOrCreateInstance(el, {delay: 3500}).show();
}

function fillForm(params) {
    FIELD_NAMES.forEach(name => {
        const el = document.getElementById('f_' + name);
        if (el) el.value = params[name];
    });
}

// Carrega valores actuais
async function loadParams() {
    try {
        const r = await fetch(`${API}?tank_id=${TANK_ID}`);
        const data = await r.json();
        if (data.params) fillForm(data.params);
    } catch (e) {
        showToast('Erro ao carregar parâmetros.', 'danger');
    }
}

// Guardar
document.getElementById('params-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const body = new URLSearchParams({ tank_id: TANK_ID, action: 'save' });
    FIELD_NAMES.forEach(name => {
        const el = document.getElementById('f_' + name);
        if (el) body.append(name, el.value);
    });
    try {
        const r = await fetch(API, { method: 'POST', body });
        const data = await r.json();
        if (data.success) {
            showToast(data.message, 'success');
            if (data.params) fillForm(data.params);
        } else {
            showToast(data.error || 'Erro ao guardar.', 'danger');
        }
    } catch (e) {
        showToast('Erro de rede.', 'danger');
    }
});

// Restaurar defaults
document.getElementById('btn-reset-defaults').addEventListener('click', async function() {
    if (!confirm('Restaurar todos os parâmetros para os valores padrão do algoritmo?')) return;
    const body = new URLSearchParams({ tank_id: TANK_ID, action: 'reset' });
    try {
        const r = await fetch(API, { method: 'POST', body });
        const data = await r.json();
        if (data.success) {
            showToast(data.message, 'success');
            if (data.params) fillForm(data.params);
        } else {
            showToast(data.error || 'Erro ao restaurar.', 'danger');
        }
    } catch (e) {
        showToast('Erro de rede.', 'danger');
    }
});

loadParams();
</script>

<?php require_once '../footer.php'; ?>
