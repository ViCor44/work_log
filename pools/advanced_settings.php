<?php
require_once '../header.php';

// Check if a valid tank ID is provided in the URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Tanque inválido.");
}
$tank_id = $_GET['id'];

// Fetch tank name and controller IP
$stmt = $conn->prepare("SELECT name, controller_ip FROM tanks WHERE id = ?");
$stmt->bind_param("i", $tank_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Tanque não encontrado.");
}
$tank_info = $result->fetch_assoc();
$tank_name = $tank_info['name'];
$controller_ip = $tank_info['controller_ip'];
$analysis_days = isset($_GET['days']) && is_numeric($_GET['days']) && (int)$_GET['days'] > 0 ? (int)$_GET['days'] : 3;
$analysis_start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : '';
$analysis_end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : '';
$stmt->close();
?>

<style>
.pid-analysis {
    font-family: 'Courier New', monospace;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: .25rem;
    padding: 1rem;
    margin-bottom: 1rem;
}
.metric-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
.metric-card.warning {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}
.metric-card.success {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}
.suggestion-item {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 8px;
}
.suggestion-item strong {
    color: #856404;
}
.stats-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.stats-table th, .stats-table td {
    border: 1px solid #dee2e6;
    padding: 8px;
    text-align: left;
}
.stats-table th {
    background-color: #f8f9fa;
    font-weight: bold;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Análise Inteligente de Controle PID: <?= htmlspecialchars($tank_name) ?></h1>
        <div>
            <a href="view_pool_details.php?id=<?= $tank_id ?>" class="btn btn-secondary">Voltar à Monitorização</a>
            <a href="dashboard.php" class="btn btn-secondary">Voltar ao Dashboard</a>
        </div>
    </div>

    <div class="card mb-4" id="pid-suggestions-card">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-brain"></i> Sugestões de Ajuste PID (<span id="pid-period-label">carregando...</span>)</h5>
            <small class="text-muted">Análise baseada em dados históricos</small>
        </div>
        <div class="card-body" id="pid-suggestions-body">
            <div class="text-center">
                <div class="spinner-border text-warning" role="status">
                    <span class="visually-hidden">Carregando análise de PID...</span>
                </div>
                <p class="mt-2 text-muted">Analisando dados históricos e calculando recomendações...</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal para aceitar sugestão de PID -->
<div class="modal fade" id="acceptSuggestionModal" tabindex="-1" aria-labelledby="acceptSuggestionLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="acceptSuggestionLabel">
                    <i class="fas fa-check-circle"></i> Aceitar Sugestão de Ajuste PID
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="suggestionModalBody">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label"><strong>P (Kp) Atual</strong></label>
                        <input type="text" id="currentP" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><strong>P (Kp) Sugerido</strong></label>
                        <input type="number" id="suggestedP" class="form-control" step="0.000001">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <span id="pChange" class="badge bg-info w-100 text-center"></span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label"><strong>Ti (Tempo Integral) Atual</strong></label>
                        <input type="text" id="currentI" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><strong>Ti Sugerido</strong></label>
                        <input type="number" id="suggestedI" class="form-control" step="0.01">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <span id="iChange" class="badge bg-info w-100 text-center"></span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label"><strong>Td (Tempo Derivativo) Atual</strong></label>
                        <input type="text" id="currentD" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><strong>Td Sugerido</strong></label>
                        <input type="number" id="suggestedD" class="form-control" step="0.01">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <span id="dChange" class="badge bg-info w-100 text-center"></span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><strong>Motivo da Alteração</strong></label>
                    <textarea id="suggestionReason" class="form-control" rows="3" placeholder="Motivo gerado automaticamente..."></textarea>
                </div>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Importante:</strong> Após aceitar, monitore o sistema por 24-48 horas para validar o comportamento.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="confirmAcceptBtn" onclick="confirmAcceptSuggestion()">
                    <i class="fas fa-check"></i> Aceitar e Gravar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentSuggestionData = null;

    function openAcceptModal(suggestedP, suggestedI, suggestedD, reason, canAccept) {
        if (!canAccept) {
            alert('Período de monitorização ativo. Não é possível aceitar nova sugestão neste momento.');
            return;
        }
        currentSuggestionData = { p: suggestedP, i: suggestedI, d: suggestedD };
        
        // Valores atuais (se disponível)
        document.getElementById('currentP').value = suggestedP || 'N/A';
        document.getElementById('currentI').value = suggestedI || 'N/A';
        document.getElementById('currentD').value = suggestedD || 'N/A';
        
        // Suggestões editáveis
        document.getElementById('suggestedP').value = suggestedP || '';
        document.getElementById('suggestedI').value = suggestedI || '';
        document.getElementById('suggestedD').value = suggestedD || '';
        
        // Motivo
        document.getElementById('suggestionReason').value = reason || 'Sugestão automática - Análise de 3 dias de histórico de controle';
        
        // Atualiza badges de mudança quando valores mudam
        document.getElementById('suggestedP').addEventListener('input', updateChangeIndicators);
        document.getElementById('suggestedI').addEventListener('input', updateChangeIndicators);
        document.getElementById('suggestedD').addEventListener('input', updateChangeIndicators);
        
        updateChangeIndicators();
        
        // Abre modal
        new bootstrap.Modal(document.getElementById('acceptSuggestionModal')).show();
    }

    function updateChangeIndicators() {
        const currentP = parseFloat(document.getElementById('currentP').value) || 0;
        const currentI = parseFloat(document.getElementById('currentI').value) || 0;
        const currentD = parseFloat(document.getElementById('currentD').value) || 0;
        
        const suggestedP = parseFloat(document.getElementById('suggestedP').value) || currentP;
        const suggestedI = parseFloat(document.getElementById('suggestedI').value) || currentI;
        const suggestedD = parseFloat(document.getElementById('suggestedD').value) || currentD;
        
        document.getElementById('pChange').textContent = suggestedP !== currentP ? 
            `${suggestedP > currentP ? '+' : ''}${(suggestedP - currentP).toFixed(3)}` : '=';
        document.getElementById('iChange').textContent = suggestedI !== currentI ? 
            `${suggestedI > currentI ? '+' : ''}${(suggestedI - currentI)}` : '=';
        document.getElementById('dChange').textContent = suggestedD !== currentD ? 
            `${suggestedD > currentD ? '+' : ''}${(suggestedD - currentD)}` : '=';
    }

    function confirmAcceptSuggestion() {
        const tankId = <?= $tank_id ?>;
        const p = parseFloat(document.getElementById('suggestedP').value);
        const i = parseFloat(document.getElementById('suggestedI').value);
        const d = parseFloat(document.getElementById('suggestedD').value);
        const reason = document.getElementById('suggestionReason').value;

        if (!reason.trim()) {
            alert('Por favor, adicione um motivo para a alteração.');
            return;
        }

        // Desabilita botão
        document.getElementById('confirmAcceptBtn').disabled = true;
        document.getElementById('confirmAcceptBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gravando...';

        // Envia para API
        fetch('../api/apply_pid_suggestion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                tank_id: tankId,
                p: p,
                i: i,
                d: d,
                reason: reason
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ Sugestão aceita e gravada com sucesso!\n\nNovos valores de PID:\nKp: ' + p + '\nTi: ' + i + '\nTd: ' + d);
                
                // Fecha modal
                bootstrap.Modal.getInstance(document.getElementById('acceptSuggestionModal')).hide();
                
                // Recarrega sugestões
                fetchPidSuggestions(initialPidDays, initialPidStartDate, initialPidEndDate);
            } else {
                alert('❌ Erro: ' + (data.error || 'Falha ao gravar sugestão'));
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('❌ Erro ao comunicar com o servidor: ' + error.message);
        })
        .finally(() => {
            document.getElementById('confirmAcceptBtn').disabled = false;
            document.getElementById('confirmAcceptBtn').innerHTML = '<i class="fas fa-check"></i> Aceitar e Gravar';
        });
    }

    const controllerIp = '<?= $controller_ip ?>';
    let tankId = <?= $tank_id ?>;
    const initialPidDays = <?= (int)$analysis_days ?>;
    const initialPidStartDate = '<?= htmlspecialchars($analysis_start_date, ENT_QUOTES, 'UTF-8') ?>';
    const initialPidEndDate = '<?= htmlspecialchars($analysis_end_date, ENT_QUOTES, 'UTF-8') ?>';
    const pidPeriodLabelEl = document.getElementById('pid-period-label');

    function numOrNA(value, decimals = 2) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) return 'N/A';
        return Number(value).toFixed(decimals);
    }

    function confidenceBadge(level) {
        if (level === 'alta') return 'bg-success';
        if (level === 'media') return 'bg-warning text-dark';
        return 'bg-danger';
    }

    function severityBadge(level) {
        if (level === 'alta') return 'badge bg-danger';
        if (level === 'media') return 'badge bg-warning text-dark';
        return 'badge bg-info text-dark';
    }

    function renderWindowRows(timeWindows) {
        if (!timeWindows) return '';
        const labels = {
            madrugada: 'Madrugada (00-06)',
            manha: 'Manhã (06-12)',
            tarde: 'Tarde (12-18)',
            noite: 'Noite (18-24)'
        };
        return Object.keys(labels).map(key => {
            const bucket = timeWindows[key] || {};
            const st = bucket.stats;
            return `<tr>
                <td>${labels[key]}</td>
                <td>${bucket.samples || 0}</td>
                <td>${st ? numOrNA(st.mean_abs, 3) : 'N/A'}</td>
                <td>${st ? numOrNA(st.stdev, 3) : 'N/A'}</td>
                <td>${st ? numOrNA(st.sign_change_rate * 100, 1) + '%' : 'N/A'}</td>
            </tr>`;
        }).join('');
    }

    function renderActionPlan(actions) {
        if (!actions || !actions.length) return '';
        return actions.map((item, idx) => `
            <div class="alert alert-light border mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong>${idx + 1}. ${item.title}</strong>
                    <span class="${severityBadge(item.severity)}">${item.severity.toUpperCase()}</span>
                </div>
                <div><strong>Ação:</strong> ${item.action}</div>
                <div><strong>Impacto esperado:</strong> ${item.expected_impact}</div>
                <div><strong>Risco:</strong> ${item.risk}</div>
            </div>
        `).join('');
    }

    function renderBeforeAfter(impact) {
        if (!impact || !impact.before || !impact.after) return '';
        return `
            <div class="row mb-4">
                <div class="col-md-12">
                    <h6 class="text-secondary"><i class="fas fa-exchange-alt"></i> Impacto da Última Alteração de PID (24h antes vs 24h depois)</h6>
                    <table class="stats-table">
                        <tr><th>Métrica</th><th>Antes</th><th>Depois</th><th>Delta</th></tr>
                        <tr>
                            <td>Erro Médio Absoluto</td>
                            <td>${numOrNA(impact.before.mean_abs, 4)}</td>
                            <td>${numOrNA(impact.after.mean_abs, 4)}</td>
                            <td>${numOrNA(impact.delta.mean_abs, 4)}</td>
                        </tr>
                        <tr>
                            <td>Desvio Padrão</td>
                            <td>${numOrNA(impact.before.stdev, 4)}</td>
                            <td>${numOrNA(impact.after.stdev, 4)}</td>
                            <td>${numOrNA(impact.delta.stdev, 4)}</td>
                        </tr>
                    </table>
                </div>
            </div>
        `;
    }

    async function fetchPidSuggestions(days = 3, startDate = '', endDate = '') {
        if (!tankId || tankId <= 0) {
            console.error('tankId inválido em fetchPidSuggestions:', tankId);
            return;
        }

        try {
            const params = new URLSearchParams();
            params.set('tank_id', tankId);
            if (startDate && endDate) {
                params.set('start_date', startDate);
                params.set('end_date', endDate);
            } else {
                params.set('days', days);
            }

            const response = await fetch(`../api/get_pid_suggestions.php?${params.toString()}`);
            const data = await response.json();
            const container = document.getElementById('pid-suggestions-body');

            if (pidPeriodLabelEl) {
                if (data.start_date && data.end_date) {
                    pidPeriodLabelEl.textContent = `${data.start_date} a ${data.end_date}`;
                } else {
                    pidPeriodLabelEl.textContent = `últimos ${data.days || days} dias`;
                }
            }

            if (data.error) {
                container.innerHTML = `<div class="alert alert-danger">Erro: ${data.error}</div>`;
                return;
            }

            let html = '';

            // Resumo dos dados analisados
            html += `
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="metric-card">
                            <h6><i class="fas fa-chart-line"></i> Resumo da Análise</h6>
                            <p class="mb-1"><strong>Tanque:</strong> ${data.tank_name}</p>
                            <p class="mb-1"><strong>Período analisado:</strong> ${data.start_date && data.end_date ? `${data.start_date} a ${data.end_date}` : `${data.days} dias`}</p>
                            <p class="mb-0"><strong>Registros processados:</strong> ${data.row_count}</p>
                        </div>
                    </div>
                </div>
            `;

            if (data.chlorine && data.chlorine.stats) {
                const stats = data.chlorine.stats;
                const suggestions = data.chlorine.suggestions;
                const confidence = data.chlorine.confidence || { level: 'baixa', score: 0, reasons: [] };
                const score = data.chlorine.composite_score || { total: 0, weights: {}, components: {} };
                const contribution = data.chlorine.contribution || { controller_pct: 50, external_pct: 50 };
                const trend = data.chlorine.error_trend || { sparkline: '' };

                // Estatísticas detalhadas
                html += `
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="fas fa-chart-bar"></i> Estatísticas de Controle (Cloro)</h6>
                            <table class="stats-table">
                                <tr><th>Métrica</th><th>Valor</th><th>Interpretação</th></tr>
                                <tr><td>Amostras</td><td>${stats.samples}</td><td>Quantidade de medições analisadas</td></tr>
                                <tr><td>Erro Médio</td><td>${stats.mean.toFixed(4)}</td><td>Viés sistemático (+ = acima do setpoint)</td></tr>
                                <tr><td>Erro Médio Absoluto</td><td>${stats.mean_abs.toFixed(4)}</td><td>Precisão média do controle</td></tr>
                                <tr><td>Erro Médio (%)</td><td>${stats.mean_pct !== null ? stats.mean_pct.toFixed(2) + '%' : 'N/A'}</td><td>Viés relativo ao setpoint médio</td></tr>
                                <tr><td>Erro Médio Absoluto (%)</td><td>${stats.mean_abs_pct !== null ? stats.mean_abs_pct.toFixed(2) + '%' : 'N/A'}</td><td>Precisão relativa ao setpoint médio</td></tr>
                                <tr><td>Desvio Padrão</td><td>${stats.stdev.toFixed(4)}</td><td>Volatilidade das medições</td></tr>
                                <tr><td>Mínimo/Máximo</td><td>${stats.min.toFixed(4)} / ${stats.max.toFixed(4)}</td><td>Faixa de variação do erro</td></tr>
                                <tr><td>Mudanças de Sinal</td><td>${stats.sign_changes} (${(stats.sign_change_rate * 100).toFixed(1)}%)</td><td>Frequência de oscilações</td></tr>
                                <tr><td>Tempo médio de resposta</td><td>${data.chlorine.mean_response_delay_min !== null ? data.chlorine.mean_response_delay_min + ' min' : 'N/A'}</td><td>Delay médio entre dosagem e efeito</td></tr>
                                <tr><td>Recuperação pós-queda externa</td><td>${data.chlorine.mean_recovery_min !== null ? data.chlorine.mean_recovery_min + ' min' : 'N/A'}</td><td>Tempo médio para recuperar após quedas não atribuídas ao controlador</td></tr>
                                <tr><td>Estabilização pós-recuperação</td><td>${data.chlorine.mean_stabilization_min !== null ? data.chlorine.mean_stabilization_min + ' min' : 'N/A'}</td><td>Tempo médio para voltar a estabilizar no setpoint</td></tr>
                                <tr><td>Perturbações externas</td><td>${data.chlorine.disturbance_count || 0} (sem recuperação: ${data.chlorine.unrecovered_count || 0})</td><td>Eventos de queda súbita sem mudança relevante do controlador</td></tr>
                                <tr><td>Zeros observados</td><td>${data.chlorine.zero_observed_count || 0}</td><td>Total de leituras em zero/quase zero no período</td></tr>
                                <tr><td>Zeros espontâneos filtrados</td><td>${data.chlorine.zero_glitch_count || 0}</td><td>Apenas sequências curtas (1-2 leituras) desconsideradas na estatística principal</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success"><i class="fas fa-lightbulb"></i> Diagnóstico de Performance</h6>
                            <div class="pid-analysis">
                                <p>
                                    <strong>Confiança da Análise:</strong>
                                    <span class="badge ${confidenceBadge(confidence.level)}">${(confidence.level || 'baixa').toUpperCase()} (${numOrNA(confidence.score, 1)}%)</span>
                                </p>
                                <p><strong>Score Composto:</strong> ${numOrNA(score.total, 1)}/100</p>
                                <p><strong>Peso dos critérios:</strong> Precisão 40% | Estabilidade 25% | Recuperação 20% | Robustez 15%</p>
                                <p><strong>Contribuição provável:</strong> Controle ${numOrNA(contribution.controller_pct, 1)}% vs Externo ${numOrNA(contribution.external_pct, 1)}%</p>
                                <p><strong>Tendência recente do erro:</strong> <span class="font-monospace">${trend.sparkline || 'N/A'}</span></p>
                                ${confidence.reasons && confidence.reasons.length ? `<p class="text-muted"><strong>Limitações:</strong> ${confidence.reasons.join('; ')}</p>` : ''}
                `;

                // Análise de performance (separa estado geral das dimensões técnicas)
                const precisionIssue = stats.mean_abs >= 0.25;
                const unstable = stats.stdev > 0.3;
                const hasBias = Math.abs(stats.mean) > 0.15;
                const slowRecovery = data.chlorine.mean_recovery_min !== null && data.chlorine.mean_recovery_min > 30;
                const slowStabilization = data.chlorine.mean_stabilization_min !== null && data.chlorine.mean_stabilization_min > 50;
                const unrecoveredDisturbances = (data.chlorine.unrecovered_count || 0) > 0;

                if (precisionIssue || unstable) {
                    html += '<p class="text-danger"><i class="fas fa-times-circle"></i> <strong>ESTADO GERAL: PREOCUPANTE</strong> - Necessita ajuste de controle</p>';
                } else if (stats.mean_abs >= 0.1 || hasBias) {
                    html += '<p class="text-warning"><i class="fas fa-exclamation-triangle"></i> <strong>ESTADO GERAL: ATENCAO</strong> - Controle aceitavel com pontos de melhoria</p>';
                } else {
                    html += '<p class="text-success"><i class="fas fa-check-circle"></i> <strong>ESTADO GERAL: BOM</strong> - Controle consistente no periodo</p>';
                }

                if (precisionIssue) {
                    html += '<p class="text-danger"><i class="fas fa-crosshairs"></i> <strong>Precisao</strong>: erro medio absoluto alto (controle impreciso)</p>';
                } else {
                    html += '<p class="text-success"><i class="fas fa-crosshairs"></i> <strong>Precisao</strong>: dentro da faixa esperada</p>';
                }

                if (unstable) {
                    html += '<p class="text-warning"><i class="fas fa-wave-square"></i> <strong>Estabilidade</strong>: oscilacoes detectadas (variacao elevada)</p>';
                } else if (precisionIssue) {
                    html += '<p class="text-warning"><i class="fas fa-equals"></i> <strong>Estabilidade</strong>: variacao baixa, mas com erro persistente</p>';
                } else {
                    html += '<p class="text-success"><i class="fas fa-equals"></i> <strong>Estabilidade</strong>: poucas variacoes</p>';
                }

                if (hasBias) {
                    html += '<p class="text-info"><i class="fas fa-balance-scale"></i> <strong>Vies</strong>: tendencia consistente de erro em relacao ao setpoint</p>';
                } else {
                    html += '<p class="text-muted"><i class="fas fa-balance-scale"></i> <strong>Vies</strong>: sem tendencia relevante de desvio</p>';
                }

                if (slowRecovery) {
                    html += '<p class="text-warning"><i class="fas fa-stopwatch"></i> <strong>Recuperacao</strong>: resposta lenta apos quedas externas</p>';
                } else {
                    html += '<p class="text-success"><i class="fas fa-stopwatch"></i> <strong>Recuperacao</strong>: tempo de retorno adequado</p>';
                }

                if (slowStabilization) {
                    html += '<p class="text-warning"><i class="fas fa-wave-square"></i> <strong>Estabilizacao</strong>: demora para reentrar na banda do setpoint</p>';
                } else {
                    html += '<p class="text-success"><i class="fas fa-wave-square"></i> <strong>Estabilizacao</strong>: retorno consistente apos recuperacao</p>';
                }

                if (unrecoveredDisturbances) {
                    html += `<p class="text-danger"><i class="fas fa-exclamation-circle"></i> <strong>Perturbacoes</strong>: ${data.chlorine.unrecovered_count} evento(s) sem recuperacao completa no periodo</p>`;
                }

                if ((data.chlorine.zero_glitch_count || 0) > 0) {
                    html += `<p class="text-muted"><i class="fas fa-filter"></i> <strong>Robustez</strong>: ${(data.chlorine.zero_glitch_count || 0)} zero(s) espontaneo(s) foram ignorados na analise</p>`;
                }

                html += `
                            </div>
                        </div>
                    </div>
                `;

                html += `
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <h6 class="text-info"><i class="fas fa-clock"></i> Análise por Janelas Horárias</h6>
                            <table class="stats-table">
                                <tr><th>Janela</th><th>Amostras</th><th>MAE</th><th>Desvio Padrão</th><th>Oscilação (%)</th></tr>
                                ${renderWindowRows(data.chlorine.time_windows)}
                            </table>
                        </div>
                    </div>
                `;

                if (data.chlorine.action_plan && data.chlorine.action_plan.length > 0) {
                    html += `
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="text-danger"><i class="fas fa-list-ol"></i> Plano Priorizado de Ações</h6>
                                ${renderActionPlan(data.chlorine.action_plan)}
                            </div>
                        </div>
                    `;
                }

                // Recomendações detalhadas
                if (suggestions && suggestions.length > 0) {
                    html += `
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h6 class="text-warning"><i class="fas fa-tools"></i> Recomendações de Ajuste PID</h6>
                    `;

                    suggestions.forEach((suggestion, index) => {
                        let iconClass = 'fas fa-info-circle';
                        let alertClass = 'alert-info';

                        if (suggestion.includes('aumentar') || suggestion.includes('reduzir')) {
                            iconClass = 'fas fa-sliders-h';
                            alertClass = 'alert-warning';
                        } else if (suggestion.includes('estável') || suggestion.includes('bom')) {
                            iconClass = 'fas fa-check-circle';
                            alertClass = 'alert-success';
                        } else if (suggestion.includes('oscilações') || suggestion.includes('instável')) {
                            iconClass = 'fas fa-exclamation-triangle';
                            alertClass = 'alert-danger';
                        }

                        html += `
                            <div class="alert ${alertClass} suggestion-item">
                                <i class="${iconClass}"></i> <strong>${index + 1}.</strong> ${suggestion}
                            </div>
                        `;
                    });

                    html += `
                            </div>
                        </div>
                    `;
                }

                // Botão para aceitar sugestão
                if (data.chlorine.suggested_values) {
                    if (data.chlorine.can_accept_suggestion) {
                        html += `
                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <button class="btn btn-success btn-lg w-100" onclick="openAcceptModal(${data.chlorine.suggested_values.p}, ${data.chlorine.suggested_values.i}, ${data.chlorine.suggested_values.d}, 'Sugestão automática aceita - Análise de ${data.days} dias de histórico de controle', ${data.chlorine.can_accept_suggestion})">
                                        <i class="fas fa-check-circle me-2"></i>Aceitar Sugestão e Gravar
                                    </button>
                                </div>
                            </div>
                        `;
                    } else {
                        html += `
                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <div class="alert alert-warning">
                                        <i class="fas fa-clock"></i>
                                        <strong>Período de Monitorização Ativo</strong><br>
                                        ${data.chlorine.block_reason}<br>
                                        <small class="text-muted">Última alteração: ${data.chlorine.last_change_time}</small>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                }

                html += renderBeforeAfter(data.chlorine.before_after_impact);
            } else {
                const noRecentMsg = data.message
                    ? data.message
                    : `Sem dados suficientes para análise de cloro nos últimos ${data.days} dias.`;
                const lastAvailableInfo = data.last_available_log_datetime
                    ? `<br><small class="text-muted">Último registo no histórico: ${data.last_available_log_datetime}</small>`
                    : '';

                html += `
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Sem dados recentes.</strong> ${noRecentMsg}${lastAvailableInfo}
                            </div>
                        </div>
                    </div>
                `;
            }

            // Histórico de mudanças recentes
            if (data.pid_change_history && data.pid_change_history.length > 0) {
                html += `
                    <div class="row">
                        <div class="col-md-12">
                            <h6 class="text-secondary"><i class="fas fa-history"></i> Últimas Modificações de PID</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Data/Hora</th>
                                            <th>Kp</th>
                                            <th>Ti</th>
                                            <th>Td</th>
                                            <th>Motivo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                `;

                data.pid_change_history.forEach(entry => {
                    html += `
                        <tr>
                            <td><small>${entry.changed_at}</small></td>
                            <td>${entry.p}</td>
                            <td>${entry.i}</td>
                            <td>${entry.d}</td>
                            <td><small>${entry.reason || 'Não informado'}</small></td>
                        </tr>
                    `;
                });

                html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Nota de segurança
            html += `
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="alert alert-light border">
                            <i class="fas fa-shield-alt text-primary"></i>
                            <strong>Nota de Segurança:</strong> As recomendações são baseadas em análise histórica.
                            Sempre faça ajustes incrementais e monitore o comportamento do sistema por pelo menos 24-48 horas antes de novos ajustes.
                        </div>
                    </div>
                </div>
            `;

            container.innerHTML = html;
        } catch (error) {
            console.error('Erro ao obter sugestões de PID:', error);
            document.getElementById('pid-suggestions-body').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Erro na análise:</strong> ${error.message}
                </div>
            `;
        }
    }

    // Initial load
    fetchPidSuggestions(initialPidDays, initialPidStartDate, initialPidEndDate);
</script>

<?php
require_once '../footer.php';
?>