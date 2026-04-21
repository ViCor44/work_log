<?php
require_once '../core.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$days = isset($_GET['days']) && is_numeric($_GET['days']) && (int)$_GET['days'] > 0
    ? (int)$_GET['days']
    : 7;

$isViewer = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'viewer';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plano PID</title>
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
        .btn-success { background: #059669; color: #ffffff; }
        .btn-secondary { background: #4b5563; color: #ffffff; }
        .btn[disabled] { opacity: 0.6; cursor: not-allowed; }
        .muted { font-size: 13px; color: #d1d5db; }
        .pdf-wrap {
            height: calc(100vh - 64px);
        }
        .pdf-frame {
            border: 0;
            width: 100%;
            height: 100%;
            background: #ffffff;
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="left">
            <strong>Plano de Ajuste PID</strong>
            <span class="muted">Período: últimos <?= (int)$days ?> dias</span>
        </div>
        <div class="right">
            <a class="btn btn-secondary" href="glossario_analise_pid.php?days=<?= (int)$days ?>&from=plano" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-book"></i> Glossário da Análise
            </a>
            <a class="btn btn-warning" href="gerar_pdf_pid_semanal.php?days=<?= (int)$days ?>" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-print"></i> Abrir PDF em nova aba
            </a>
            <?php if (!$isViewer): ?>
                <button class="btn btn-success" id="accept-bulk-btn" type="button">
                    <i class="fas fa-check-circle"></i> Aceitar Sugestões e Guardar
                </button>
            <?php endif; ?>
            <a class="btn btn-secondary" href="dashboard.php">Voltar ao Dashboard</a>
        </div>
    </div>

    <div class="pdf-wrap">
        <iframe class="pdf-frame" src="gerar_pdf_pid_semanal.php?days=<?= (int)$days ?>" title="Plano PID PDF"></iframe>
    </div>

    <script>
        (function () {
            const btn = document.getElementById('accept-bulk-btn');
            if (!btn) {
                return;
            }

            btn.addEventListener('click', async function () {
                const ok = confirm(
                    'Confirmar aplicação das sugestões do PDF para os últimos <?= (int)$days ?> dias?\n\n' +
                    'Serão ignorados controladores sem dados, sem alteração ou bloqueados por 72h.'
                );

                if (!ok) {
                    return;
                }

                const original = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> A aplicar...';

                try {
                    const response = await fetch('../api/apply_pid_suggestions_bulk.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ days: <?= (int)$days ?> })
                    });

                    const data = await response.json();
                    if (!response.ok || data.error) {
                        throw new Error(data.error || ('Falha HTTP ' + response.status));
                    }

                    alert(
                        'Aplicação concluída.\n\n' +
                        'Total controladores: ' + data.total_controllers + '\n' +
                        'Aplicados: ' + data.applied + '\n' +
                        'Sem dados: ' + data.skipped_no_data + '\n' +
                        'Bloqueados (72h): ' + data.skipped_blocked + '\n' +
                        'Sem alteração: ' + data.skipped_unchanged + '\n' +
                        'Erros: ' + data.errors
                    );
                } catch (err) {
                    alert('Erro ao aplicar sugestões: ' + err.message);
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            });
        })();
    </script>
</body>
</html>
