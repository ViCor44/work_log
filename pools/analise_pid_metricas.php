<?php
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$days = isset($_GET['days']) && is_numeric($_GET['days']) && (int)$_GET['days'] > 0
    ? (int)$_GET['days']
    : 7;

$tankSql = "SELECT id, name FROM tanks WHERE type = 'piscina' AND has_controller = 1 ORDER BY name ASC";
$tanksResult = $conn->query($tankSql);
$tanks = $tanksResult ? $tanksResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise PID - Métricas Avançadas</title>
    <link rel="stylesheet" href="../css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #111827;
            color: #f9fafb;
            border-bottom: 1px solid #374151;
        }
        .toolbar .left {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .toolbar .right {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn {
            border: 0;
            border-radius: 6px;
            padding: 9px 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-warning { background: #f59e0b; color: #111827; }
        .btn-secondary { background: #4b5563; color: #ffffff; }
        .btn-primary { background: #2563eb; color: #ffffff; }
        .muted { font-size: 13px; color: #d1d5db; }
        .content {
            padding: 14px 16px;
        }
        .panel {
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #eef2ff;
            font-weight: 700;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }
        .high { background: #16a34a; }
        .medium { background: #d97706; }
        .low { background: #dc2626; }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="left">
            <strong>Análise PID - Métricas Avançadas</strong>
            <span class="muted">Período: últimos <?= (int)$days ?> dias</span>
        </div>
        <div class="right">
            <a class="btn btn-secondary" href="glossario_analise_pid.php?days=<?= (int)$days ?>&from=plano" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-book"></i> Glossário
            </a>
            <a class="btn btn-warning" href="plano_pid.php?days=<?= (int)$days ?>">
                <i class="fas fa-file-pdf"></i> Voltar ao Plano PID
            </a>
            <a class="btn btn-secondary" href="dashboard.php">Voltar ao Dashboard</a>
        </div>
    </div>

    <div class="content">
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Controlador</th>
                        <th>Confiança</th>
                        <th>Score</th>
                        <th>MAE (%)</th>
                        <th>DP</th>
                        <th>Ctrl vs Ext</th>
                        <th>Pior Janela</th>
                        <th>Delta MAE (24h)</th>
                        <th>Ação Prioritária</th>
                        <th>Detalhe</th>
                    </tr>
                </thead>
                <tbody id="analysis-body">
                    <tr>
                        <td colspan="10">A carregar análise...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const days = <?= (int)$days ?>;
        const tanks = <?= json_encode($tanks) ?>;

        function safeNum(value, decimals = 2) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) return '-';
            return Number(value).toFixed(decimals);
        }

        function getConfidenceClass(level) {
            if (level === 'alta') return 'high';
            if (level === 'media') return 'medium';
            return 'low';
        }

        function getWorstWindow(timeWindows) {
            if (!timeWindows) return '-';
            let worst = { name: '-', mae: -1 };
            const labels = {
                madrugada: 'Madrugada',
                manha: 'Manhã',
                tarde: 'Tarde',
                noite: 'Noite'
            };
            Object.keys(labels).forEach((key) => {
                const bucket = timeWindows[key];
                if (!bucket || !bucket.stats) return;
                const mae = Number(bucket.stats.mean_abs || 0);
                if (mae > worst.mae) {
                    worst = { name: labels[key], mae };
                }
            });
            return worst.name;
        }

        function topAction(actions) {
            if (!actions || !actions.length) return '-';
            return actions[0].title || actions[0].action || '-';
        }

        async function loadAnalysis() {
            const tbody = document.getElementById('analysis-body');
            const rows = [];

            for (const tank of tanks) {
                try {
                    const res = await fetch(`../api/get_pid_suggestions.php?tank_id=${tank.id}&days=${days}`);
                    const data = await res.json();

                    if (data.error || !data.chlorine || !data.chlorine.stats) {
                        rows.push(`<tr><td>${tank.name}</td><td colspan="9">Sem dados suficientes</td></tr>`);
                        continue;
                    }

                    const c = data.chlorine;
                    const conf = c.confidence || { level: 'baixa', score: 0 };
                    const score = c.composite_score ? c.composite_score.total : null;
                    const maePct = c.stats.mean_abs_pct;
                    const stdev = c.stats.stdev;
                    const ctrlPct = c.contribution ? c.contribution.controller_pct : null;
                    const extPct = c.contribution ? c.contribution.external_pct : null;
                    const worstWindow = getWorstWindow(c.time_windows);
                    const deltaMae = c.before_after_impact && c.before_after_impact.delta ? c.before_after_impact.delta.mean_abs : null;
                    const action = topAction(c.action_plan);
                    const detailUrl = `advanced_settings.php?id=${tank.id}&days=${days}`;

                    rows.push(`
                        <tr>
                            <td>${tank.name}</td>
                            <td><span class="badge ${getConfidenceClass(conf.level)}">${(conf.level || 'baixa').toUpperCase()} (${safeNum(conf.score, 1)}%)</span></td>
                            <td>${safeNum(score, 1)}</td>
                            <td>${maePct !== null ? safeNum(maePct, 2) + '%' : '-'}</td>
                            <td>${safeNum(stdev, 3)}</td>
                            <td>${ctrlPct !== null && extPct !== null ? `${safeNum(ctrlPct, 1)} / ${safeNum(extPct, 1)}` : '-'}</td>
                            <td>${worstWindow}</td>
                            <td>${safeNum(deltaMae, 3)}</td>
                            <td>${action}</td>
                            <td><a class="btn btn-primary" style="padding:4px 8px;font-size:12px;" href="${detailUrl}">Abrir</a></td>
                        </tr>
                    `);
                } catch (err) {
                    rows.push(`<tr><td>${tank.name}</td><td colspan="9">Erro ao obter dados</td></tr>`);
                }
            }

            tbody.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="10">Sem controladores disponíveis.</td></tr>';
        }

        loadAnalysis();
    </script>
</body>
</html>
