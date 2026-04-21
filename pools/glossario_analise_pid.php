<?php
require_once '../header.php';

$tank_id = isset($_GET['tank_id']) && is_numeric($_GET['tank_id']) ? (int)$_GET['tank_id'] : 0;
$days = isset($_GET['days']) && is_numeric($_GET['days']) ? (int)$_GET['days'] : 7;
$from = isset($_GET['from']) ? $_GET['from'] : '';

$back_url = 'dashboard.php';
if ($from === 'advanced' && $tank_id > 0) {
    $back_url = 'advanced_settings.php?id=' . $tank_id . '&days=' . max(1, $days);
} else if ($from === 'plano') {
    $back_url = 'plano_pid.php?days=' . max(1, $days);
}
?>

<style>
.glossary-card {
    border: 1px solid #d7dde5;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    margin-bottom: 18px;
}
.glossary-card .card-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #0ea5e9 100%);
    color: #fff;
    border-top-left-radius: 10px;
    border-top-right-radius: 10px;
    font-weight: 700;
}
.glossary-table {
    width: 100%;
    border-collapse: collapse;
}
.glossary-table th,
.glossary-table td {
    border: 1px solid #e5e7eb;
    padding: 10px;
    vertical-align: top;
}
.glossary-table th {
    background: #f8fafc;
}
.term-col {
    width: 25%;
    font-weight: 700;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-book me-2 text-primary"></i>Glossário da Análise PID</h1>
        <div>
            <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="alert alert-info">
        Esta página explica os termos exibidos na análise inteligente de PID, para facilitar a interpretação e a tomada de decisão operacional.
    </div>

    <div class="card glossary-card">
        <div class="card-header">1) Métricas Base de Controle</div>
        <div class="card-body p-0">
            <table class="glossary-table">
                <thead>
                    <tr>
                        <th class="term-col">Termo</th>
                        <th>O que é</th>
                        <th>Para que serve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="term-col">Amostras</td><td>Número de medições válidas usadas na análise.</td><td>Indica robustez estatística; poucas amostras reduzem confiança.</td></tr>
                    <tr><td class="term-col">Erro Médio</td><td>Média de (valor medido - setpoint).</td><td>Mostra viés: positivo tende acima do setpoint, negativo tende abaixo.</td></tr>
                    <tr><td class="term-col">Erro Médio Absoluto (MAE)</td><td>Média do valor absoluto do erro.</td><td>Indicador principal de precisão do controle.</td></tr>
                    <tr><td class="term-col">Erro Médio (%)</td><td>Erro médio relativo ao setpoint médio.</td><td>Permite comparar desempenho entre tanques/setpoints diferentes.</td></tr>
                    <tr><td class="term-col">Erro Médio Absoluto (%)</td><td>MAE relativo ao setpoint médio.</td><td>Mede precisão percentual, útil para priorização entre sistemas.</td></tr>
                    <tr><td class="term-col">Desvio Padrão</td><td>Dispersão dos erros em torno da média.</td><td>Avalia estabilidade/oscilação do processo.</td></tr>
                    <tr><td class="term-col">Mínimo/Máximo</td><td>Menor e maior erro observados.</td><td>Mostra extremos de operação e risco de eventos fora de faixa.</td></tr>
                    <tr><td class="term-col">Mudanças de Sinal</td><td>Vezes em que o erro muda de positivo para negativo (ou vice-versa).</td><td>Indica frequência de cruzamento do setpoint e tendência a oscilar.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card glossary-card">
        <div class="card-header">2) Dinâmica de Processo e Robustez</div>
        <div class="card-body p-0">
            <table class="glossary-table">
                <thead>
                    <tr>
                        <th class="term-col">Termo</th>
                        <th>O que é</th>
                        <th>Para que serve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="term-col">Tempo médio de resposta</td><td>Delay médio entre alteração de dosagem e efeito medido.</td><td>Ajusta expectativa de reação e ajuda no tuning de Ti/Td.</td></tr>
                    <tr><td class="term-col">Recuperação pós-queda externa</td><td>Tempo médio para voltar após perturbações não atribuídas ao controlador.</td><td>Mede resiliência operacional.</td></tr>
                    <tr><td class="term-col">Estabilização pós-recuperação</td><td>Tempo para voltar a uma banda estável no setpoint após recuperar.</td><td>Mostra qualidade do assentamento após distúrbio.</td></tr>
                    <tr><td class="term-col">Perturbações externas</td><td>Eventos de queda súbita sem mudança relevante de saída do controlador.</td><td>Separa problema de processo externo vs problema de tuning.</td></tr>
                    <tr><td class="term-col">Sem recuperação</td><td>Qtd de perturbações que não retornaram adequadamente no período.</td><td>Sinaliza risco operacional e necessidade de intervenção.</td></tr>
                    <tr><td class="term-col">Zeros observados</td><td>Leituras em zero ou quase zero no período.</td><td>Ajuda a detectar falhas de sensor/processo.</td></tr>
                    <tr><td class="term-col">Zeros espontâneos filtrados</td><td>Sequências curtas tratadas como glitch e removidas da estatística principal.</td><td>Aumenta robustez da análise contra ruído de medição.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card glossary-card">
        <div class="card-header">3) Diagnóstico e Qualidade da Análise</div>
        <div class="card-body p-0">
            <table class="glossary-table">
                <thead>
                    <tr>
                        <th class="term-col">Termo</th>
                        <th>O que é</th>
                        <th>Para que serve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="term-col">Confiança da Análise</td><td>Nível Alta/Média/Baixa baseado em amostragem, cobertura e qualidade de dados.</td><td>Define o quanto a recomendação pode ser aplicada com segurança.</td></tr>
                    <tr><td class="term-col">Limitações</td><td>Motivos que reduzem a confiança (pouca amostra, cobertura parcial, glitches).</td><td>Contextualiza risco de decisões prematuras.</td></tr>
                    <tr><td class="term-col">Score Composto</td><td>Índice 0-100 calculado por pesos de precisão, estabilidade, recuperação e robustez.</td><td>Resumo único de performance para comparação rápida.</td></tr>
                    <tr><td class="term-col">Peso dos critérios</td><td>Importância relativa de cada dimensão no score.</td><td>Torna o diagnóstico explicável e auditável.</td></tr>
                    <tr><td class="term-col">Contribuição Controle vs Externo</td><td>Estimativa de quanto o problema vem do tuning do controlador ou do processo externo.</td><td>Ajuda a atacar a causa correta e reduzir falsos ajustes.</td></tr>
                    <tr><td class="term-col">Tendência recente do erro</td><td>Sparkline textual dos últimos erros.</td><td>Mostra visualmente se o erro está piorando, melhorando ou oscilando.</td></tr>
                    <tr><td class="term-col">Estado Geral</td><td>Classificação final (bom, atenção, preocupante).</td><td>Sinal rápido para priorização operacional.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card glossary-card">
        <div class="card-header">4) Análise por Janelas Horárias</div>
        <div class="card-body p-0">
            <table class="glossary-table">
                <thead>
                    <tr>
                        <th class="term-col">Termo</th>
                        <th>O que é</th>
                        <th>Para que serve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="term-col">Madrugada / Manhã / Tarde / Noite</td><td>Separação temporal do período em 4 blocos horários.</td><td>Detecta padrões operacionais por faixa horária.</td></tr>
                    <tr><td class="term-col">MAE por janela</td><td>Precisão dentro de cada bloco horário.</td><td>Identifica quando o controle degrada mais.</td></tr>
                    <tr><td class="term-col">Desvio padrão por janela</td><td>Oscilação em cada bloco.</td><td>Mostra horários com maior instabilidade.</td></tr>
                    <tr><td class="term-col">Oscilação (%) por janela</td><td>Taxa de reversão de sinal por bloco.</td><td>Aponta horários críticos para ajustes finos.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card glossary-card">
        <div class="card-header">5) Impacto de Mudanças e Ações Recomendadas</div>
        <div class="card-body p-0">
            <table class="glossary-table">
                <thead>
                    <tr>
                        <th class="term-col">Termo</th>
                        <th>O que é</th>
                        <th>Para que serve</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="term-col">24h antes vs 24h depois</td><td>Comparação da última alteração de PID em janelas fixas.</td><td>Valida se a alteração melhorou ou piorou desempenho.</td></tr>
                    <tr><td class="term-col">Delta</td><td>Diferença entre depois e antes para cada métrica.</td><td>Quantifica impacto direto da alteração.</td></tr>
                    <tr><td class="term-col">Plano Priorizado de Ações</td><td>Lista ordenada por prioridade/severidade.</td><td>Facilita execução operacional com foco no maior impacto.</td></tr>
                    <tr><td class="term-col">Impacto esperado</td><td>Ganho técnico estimado da ação proposta.</td><td>Ajuda a justificar e sequenciar intervenções.</td></tr>
                    <tr><td class="term-col">Risco</td><td>Efeito colateral potencial do ajuste.</td><td>Evita alterações agressivas sem monitorização.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-warning">
        Regra prática: quando a confiança estiver baixa, priorize aumentar qualidade/quantidade de dados antes de alterar PID.
    </div>
</div>

<?php require_once '../footer.php'; ?>
