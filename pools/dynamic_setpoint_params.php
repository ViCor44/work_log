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

// Helper local para ler settings (mesmo padrão do worker)
if (!function_exists('get_setting_value')) {
    function get_setting_value(mysqli $conn, string $key, ?string $default = null): ?string {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        if (!$stmt) return $default;
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return ($row !== null) ? $row['setting_value'] : $default;
    }
}

// Carrega parâmetros actuais da DB (fallback para defaults se não existirem)
$pPrefix = 'dynamic_setpoint_tank_' . $tank_id . '_ctrl_1_param_';
$defaults = [
    'anticipation_offset' => 0.06,
    'min_follow_offset'   => 0.03,
    'max_follow_offset'   => 0.18,
    'pump_min_target'     => 20.0,
    'pump_max_target'     => 35.0,
    'pump_adjust_step'    => 0.02,
    'trend_deadband'      => 0.01,
    'trend_window_size'   => 10.0,
    'trend_min_majority'  => 2.0,
    'cooldown_sec'        => 60.0,
    'min_send_delta'      => 0.01,
    'safety_disable_above_pv' => 3.00,
    'night_anticipation_offset' => 0.03,
    'night_min_follow_offset'   => 0.015,
    'night_max_follow_offset'   => 0.09,
    'night_pump_min_target'     => 10.0,
    'night_pump_max_target'     => 17.5,
    'night_pump_adjust_step'    => 0.01,
    'night_start_hour'    => 22.0,
    'night_end_hour'      => 7.0,
    'night_disable_dynamic' => 0.0,
    'night_min_excess_over_base' => 0.25,
    'night_min_drop_delta' => 0.02,
    'ha_anticipation_offset' => 0.12,
    'ha_min_follow_offset'   => 0.06,
    'ha_max_follow_offset'   => 0.35,
    'ha_pump_min_target'     => 12.0,
    'ha_pump_max_target'     => 45.0,
    'ha_pump_adjust_step'    => 0.04,
];
$params = [];
foreach ($defaults as $name => $default) {
    $val = get_setting_value($conn, $pPrefix . $name, null);
    $params[$name] = ($val !== null) ? (float)$val : $default;
}
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
.params-card label .info-icon {
    display: inline-block;
    width: 15px;
    height: 15px;
    background: #4a6278;
    border-radius: 50%;
    color: #aed6f1;
    font-size: 0.7rem;
    font-weight: 700;
    text-align: center;
    line-height: 15px;
    margin-left: 5px;
    cursor: help;
    vertical-align: middle;
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
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Quando o cloro desce abaixo da base, o SP enviado ao controlador é PV + este offset, forçando doseagem antes da queda. Na subida, SP = PV − offset, reduzindo doseagem. Valores maiores reagem mais cedo mas são mais agressivos.">Offset de antecipação <span class="default-badge">(padrão: 0.06)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="anticipation_offset" id="f_anticipation_offset" value="<?= $params['anticipation_offset'] ?>">
                            <div class="param-hint">Offset base aplicado ao PV na descida/subida.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="O offset real é ajustado pela % da bomba, mas nunca desce abaixo deste valor mínimo. Garante que o controlador recebe sempre um SP com diferença significativa, mesmo quando a bomba está a trabalhar bem.">Offset mínimo <span class="default-badge">(padrão: 0.03)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="min_follow_offset" id="f_min_follow_offset" value="<?= $params['min_follow_offset'] ?>">
                            <div class="param-hint">Limite inferior do offset ajustado pela bomba.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Mesmo que a bomba esteja muito abaixo do alvo mínimo, o offset não ultrapassa este valor. Evita que o SP seja empurrado demasiado longe do PV, o que poderia causar sobre-doseagem.">Offset máximo <span class="default-badge">(padrão: 0.18)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="max_follow_offset" id="f_max_follow_offset" value="<?= $params['max_follow_offset'] ?>">
                            <div class="param-hint">Limite superior do offset — evita sobre-ajuste.</div>
                        </div>
                    </div>

                    <!-- BOMBA -->
                    <div class="section-title mt-3">Controlo por % de Bomba</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Se a % da bomba estiver abaixo deste valor, o algoritmo aumenta o offset para tornar o SP mais exigente, forçando o controlador a dosear mais. Exemplo: bomba a 10% e mínimo 20% → offset aumenta para compensar a doseagem insuficiente.">% Bomba mínima desejada <span class="default-badge">(padrão: 20)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="pump_min_target" id="f_pump_min_target" value="<?= $params['pump_min_target'] ?>">
                            <div class="param-hint">Se bomba &lt; mínimo, aumenta o offset para forçar mais doseagem.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Se a % da bomba estiver acima deste valor, o algoritmo reduz o offset para aliviar o SP, travando doseagem excessiva. Exemplo: bomba a 50% e máximo 35% → offset diminui para deixar o controlador relaxar.">% Bomba máxima desejada <span class="default-badge">(padrão: 35)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="pump_max_target" id="f_pump_max_target" value="<?= $params['pump_max_target'] ?>">
                            <div class="param-hint">Se bomba &gt; máximo, reduz o offset para travar doseagem.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Por cada 1% de desvio da bomba em relação ao intervalo desejado, o offset é ajustado por este valor. Exemplo com passo=0.02: bomba 10% abaixo do mínimo → offset +0.20. Valores maiores tornam o sistema mais reactivo à bomba.">Passo de ajuste por bomba <span class="default-badge">(padrão: 0.02)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.005" class="form-control" name="pump_adjust_step" id="f_pump_adjust_step" value="<?= $params['pump_adjust_step'] ?>">
                            <div class="param-hint">Quanto o offset sobe/desce por cada % de desvio da bomba.</div>
                        </div>
                    </div>

                    <!-- FILTROS / COOLDOWN -->
                    <div class="section-title mt-3">Filtros e Cooldown</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Diferença mínima entre a leitura actual e a anterior para o algoritmo considerar que há uma tendência real (subida ou descida). Abaixo deste valor, o sistema restaura o SP base para evitar reacções a flutuações de sensor. Aumente se o sensor for ruidoso.">Deadband de tendência (mg/L) <span class="default-badge">(padrão: 0.01)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.001" class="form-control" name="trend_deadband" id="f_trend_deadband" value="<?= $params['trend_deadband'] ?>">
                            <div class="param-hint">Delta mínimo entre leituras para considerar tendência real. Filtra ruído.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Número de leituras consecutivas (em controller_history) usadas para calcular a tendência. Cada leitura ocorre a cada 5 min: 10 leituras ≈ 50 min, 6 leituras ≈ 30 min. Janela maior é mais estável; menor é mais reativa.">Janela de tendência (nº leituras) <span class="default-badge">(padrão: 10)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="1" min="3" max="60" class="form-control" name="trend_window_size" id="f_trend_window_size" value="<?= $params['trend_window_size'] ?>">
                            <div class="param-hint">Quantas leituras considerar para confirmar tendência (3–60).</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Diferença mínima entre o número de deltas positivos e negativos dentro da janela para confirmar tendência. Ex.: com janela=10 e maioria=2, basta ter 2 deltas a mais num sentido (ex.: 6 a subir vs 4 a descer) para confirmar. Aumentar exige mais consistência; diminuir torna mais reativo.">Maioria mínima de deltas <span class="default-badge">(padrão: 2)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="1" min="1" max="60" class="form-control" name="trend_min_majority" id="f_trend_min_majority" value="<?= $params['trend_min_majority'] ?>">
                            <div class="param-hint">Diferença mínima entre deltas + e − na janela para confirmar tendência.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Tempo mínimo em segundos entre dois envios consecutivos de setpoint ao controlador. Evita flooding de comandos em ciclos rápidos. Como o worker corre de 5 em 5 min (300s), valores abaixo de 60s têm pouco impacto prático.">Cooldown entre envios (seg) <span class="default-badge">(padrão: 60)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="1" class="form-control" name="cooldown_sec" id="f_cooldown_sec" value="<?= $params['cooldown_sec'] ?>">
                            <div class="param-hint">Segundos mínimos entre dois envios consecutivos ao controlador.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Mesmo após o cooldown expirar, o algoritmo só envia um novo SP se este diferir do último enviado por pelo menos este valor. Evita envios redundantes quando o cálculo resulta num valor praticamente igual ao anterior.">Delta mínimo para enviar (mg/L) <span class="default-badge">(padrão: 0.01)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.001" class="form-control" name="min_send_delta" id="f_min_send_delta" value="<?= $params['min_send_delta'] ?>">
                            <div class="param-hint">Só envia ao controlador se o novo SP diferir pelo menos este valor do último enviado.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Limite de segurança de PV (cloro). Se o PV atual for maior ou igual a este valor, o SP dinâmico é inativado nesse ciclo e o sistema usa SP base.">PV de segurança para inativar dinâmico (mg/L) <span class="default-badge">(padrão: 3.00)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="safety_disable_above_pv" id="f_safety_disable_above_pv" value="<?= $params['safety_disable_above_pv'] ?>">
                            <div class="param-hint">Se PV >= limite, força SP base (sem offsets dinâmicos).</div>
                        </div>
                    </div>

                    <div class="section-title mt-3">Modo Noturno (mais conservador)</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset de antecipação noturno. Por padrão é metade do normal para uma atuação mais suave durante baixo consumo.">Offset antecipação (noite) <span class="default-badge">(padrão: 0.03)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="night_anticipation_offset" id="f_night_anticipation_offset" value="<?= $params['night_anticipation_offset'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset mínimo noturno. Mantém atuação mais conservadora durante a noite.">Offset mínimo (noite) <span class="default-badge">(padrão: 0.015)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.001" class="form-control" name="night_min_follow_offset" id="f_night_min_follow_offset" value="<?= $params['night_min_follow_offset'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset máximo noturno. Limita agressividade da compensação durante a noite.">Offset máximo (noite) <span class="default-badge">(padrão: 0.09)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="night_max_follow_offset" id="f_night_max_follow_offset" value="<?= $params['night_max_follow_offset'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Alvo mínimo da bomba no período noturno. Metade do valor normal por defeito.">% Bomba mínima (noite) <span class="default-badge">(padrão: 10)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="night_pump_min_target" id="f_night_pump_min_target" value="<?= $params['night_pump_min_target'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Alvo máximo da bomba no período noturno. Metade do valor normal por defeito.">% Bomba máxima (noite) <span class="default-badge">(padrão: 17.5)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="night_pump_max_target" id="f_night_pump_max_target" value="<?= $params['night_pump_max_target'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Passo de ajuste da bomba no período noturno. Metade do normal por defeito.">Passo ajuste bomba (noite) <span class="default-badge">(padrão: 0.01)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.005" class="form-control" name="night_pump_adjust_step" id="f_night_pump_adjust_step" value="<?= $params['night_pump_adjust_step'] ?>">
                            <div class="param-hint">Perfil noturno: 50% do default normal.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="form-check form-switch mt-1 mb-1">
                                <input class="form-check-input" type="checkbox" id="f_night_disable_dynamic" name="night_disable_dynamic" <?= ((float)$params['night_disable_dynamic'] === 1.0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="f_night_disable_dynamic" data-bs-toggle="tooltip" data-bs-placement="top" title="Se ativo, o SP dinâmico não atua durante a janela noturna. O algoritmo mantém/restaura o SP base do controlador nesse período.">Inativar SP dinâmico durante o período noturno <span class="default-badge">(padrão: desligado)</span> <span class="info-icon">?</span></label>
                            </div>
                            <div class="param-hint">Quando ativo, à noite o sistema usa apenas o setpoint base (sem offsets dinâmicos).</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Hora de início do modo noturno. Dentro desta janela, o algoritmo só acompanha a descida quando o cloro estiver bem acima da base e com queda mais evidente.">Início noite (hora) <span class="default-badge">(padrão: 22)</span> <span class="info-icon">?</span></label>
                            <input type="number" min="0" max="23" step="1" class="form-control" name="night_start_hour" id="f_night_start_hour" value="<?= $params['night_start_hour'] ?>">
                            <div class="param-hint">0-23. Ex.: 22 significa 22:00.</div>
                        </div>
                        <div class="col-md-3">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Hora de fim do modo noturno. Pode atravessar meia-noite (ex.: início 22, fim 7).">Fim noite (hora) <span class="default-badge">(padrão: 7)</span> <span class="info-icon">?</span></label>
                            <input type="number" min="0" max="23" step="1" class="form-control" name="night_end_hour" id="f_night_end_hour" value="<?= $params['night_end_hour'] ?>">
                            <div class="param-hint">0-23. Ex.: 7 significa 07:00.</div>
                        </div>
                        <div class="col-md-3">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Durante a noite, só aplica ajuste de descida se PV estiver acima de (SP base + este excesso). Quanto maior, mais conservador.">Excesso mínimo sobre base (mg/L) <span class="default-badge">(padrão: 0.25)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="night_min_excess_over_base" id="f_night_min_excess_over_base" value="<?= $params['night_min_excess_over_base'] ?>">
                            <div class="param-hint">À noite exige PV mais acima da base para atuar.</div>
                        </div>
                        <div class="col-md-3">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Durante a noite, só considera queda confirmada se a descida for pelo menos este delta entre leituras. Também respeita o trend deadband (usa o maior dos dois).">Queda mínima confirmada (mg/L) <span class="default-badge">(padrão: 0.02)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.001" class="form-control" name="night_min_drop_delta" id="f_night_min_drop_delta" value="<?= $params['night_min_drop_delta'] ?>">
                            <div class="param-hint">Evita atuar em quedas pequenas durante baixo consumo.</div>
                        </div>
                    </div>

                    <div class="section-title mt-3">Alta Afluência (mais agressivo)</div>
                    <p style="color:#b0bec5; font-size:0.82rem; margin-bottom:0.75rem;">Quando o toggle "🏊 Alta afluência" está ativo na página do tanque, estes valores substituem os normais acima durante a descida acima da base.</p>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset de antecipação usado em modo de alta afluência. Deve ser maior que o normal para reagir mais cedo.">Offset antecipação (HA) <span class="default-badge">(padrão: 0.12)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="ha_anticipation_offset" id="f_ha_anticipation_offset" value="<?= $params['ha_anticipation_offset'] ?>">
                            <div class="param-hint">Normalmente o dobro do offset normal.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset mínimo em modo de alta afluência. Nunca desce abaixo deste valor, mesmo quando a bomba está a trabalhar bem.">Offset mínimo (HA) <span class="default-badge">(padrão: 0.06)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="ha_min_follow_offset" id="f_ha_min_follow_offset" value="<?= $params['ha_min_follow_offset'] ?>">
                            <div class="param-hint">Limite inferior em HA.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Offset máximo em modo de alta afluência. Permite seguir a descida com mais agressão sem over-shoot excessivo.">Offset máximo (HA) <span class="default-badge">(padrão: 0.35)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.01" class="form-control" name="ha_max_follow_offset" id="f_ha_max_follow_offset" value="<?= $params['ha_max_follow_offset'] ?>">
                            <div class="param-hint">Limite superior em HA.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="% mínima da bomba em HA. Mais baixo que o normal: reage mesmo quando a bomba já está a dosear um pouco.">% Bomba mínima (HA) <span class="default-badge">(padrão: 12)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="ha_pump_min_target" id="f_ha_pump_min_target" value="<?= $params['ha_pump_min_target'] ?>">
                            <div class="param-hint">Inicia ajuste mais cedo em HA.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="% máxima da bomba em HA. Mais alto que o normal: aguenta a doseagem até à bomba estar bem ocupada.">% Bomba máxima (HA) <span class="default-badge">(padrão: 45)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.5" class="form-control" name="ha_pump_max_target" id="f_ha_pump_max_target" value="<?= $params['ha_pump_max_target'] ?>">
                            <div class="param-hint">Reduz offset só quando bomba muito alta em HA.</div>
                        </div>
                        <div class="col-md-4">
                            <label data-bs-toggle="tooltip" data-bs-placement="top" title="Passo de ajuste por bomba em HA. Maior que o normal: o offset sobe/desce mais rapidamente com a % da bomba.">Passo ajuste bomba (HA) <span class="default-badge">(padrão: 0.04)</span> <span class="info-icon">?</span></label>
                            <input type="number" step="0.005" class="form-control" name="ha_pump_adjust_step" id="f_ha_pump_adjust_step" value="<?= $params['ha_pump_adjust_step'] ?>">
                            <div class="param-hint">Reação mais forte à bomba em HA.</div>
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
    'trend_deadband','trend_window_size','trend_min_majority','cooldown_sec','min_send_delta','safety_disable_above_pv',
    'night_anticipation_offset','night_min_follow_offset','night_max_follow_offset',
    'night_pump_min_target','night_pump_max_target','night_pump_adjust_step',
    'night_start_hour','night_end_hour','night_disable_dynamic','night_min_excess_over_base','night_min_drop_delta',
    'ha_anticipation_offset','ha_min_follow_offset','ha_max_follow_offset',
    'ha_pump_min_target','ha_pump_max_target','ha_pump_adjust_step'
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
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = Number(params[name]) === 1;
            return;
        }
        el.value = params[name];
    });
}

// Inicializa tooltips Bootstrap
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el, { trigger: 'hover focus' });
    });
});

// Guardar
document.getElementById('params-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const body = new URLSearchParams({ tank_id: TANK_ID, action: 'save' });
    FIELD_NAMES.forEach(name => {
        const el = document.getElementById('f_' + name);
        if (!el) return;
        if (el.type === 'checkbox') {
            body.append(name, el.checked ? '1' : '0');
            return;
        }
        body.append(name, el.value);
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

</script>

<?php require_once '../footer.php'; ?>
