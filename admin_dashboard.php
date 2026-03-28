<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];

// Contar mensagens não lidas para o usuário logado
$stmt = $conn->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
if (!$stmt) {
    die("Erro na consulta de mensagens: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($unread_count);
$stmt->fetch();
$stmt->close();

// Contar ordens de trabalho atribuídas e ainda não aceites
$stmt_ot = $conn->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_user = ? AND accept_at IS NULL");
if (!$stmt_ot) {
    die("Erro na consulta de ordens de trabalho: " . $conn->error);
}
$stmt_ot->bind_param("i", $user_id);
$stmt_ot->execute();
$stmt_ot->bind_result($unaccepted_ot_count);
$stmt_ot->fetch();
$stmt_ot->close();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema CMMS - Página Inicial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ================================================= */
        /* ESTILOS PROFISSIONAIS                             */
        /* ================================================= */
        body {
            background-color: #1E2A44; /* Deep navy blue for a professional backdrop */
            color: #D3D8E0; /* Light gray text for readability */
            font-family: 'Arial', sans-serif;
        }
        .card-link {
            transition: transform 0.3s, box-shadow 0.3s;
            border: 1px solid #2E4057;
            border-top: 3px solid #4A90E2; /* Professional blue accent */
            border-radius: 8px;
            margin-bottom: 1.5rem;
            height: 100%;
            background-color: #2A3F5F; /* Darker slate for cards */
        }
        .card-link:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(74, 144, 226, 0.2);
        }
        .card-title {
            color: #A9B7D0; /* Subtle light blue-gray for titles */
            font-size: 1.1rem;
            font-weight: 600;
        }
        .card-text.text-muted {
            color: #8A9BA8 !important; /* Softer muted text */
            font-size: 0.9rem;
        }
        .main-title {
            color: #ECF0F7;
            font-size: 2rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ESTILOS PARA O TICKER */
        @keyframes marquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .ticker-wrap {
            width: 100%;
            overflow: hidden;
            background-color: #243356; /* Slightly lighter professional blue */
            padding: 15px 0;
            border-radius: 8px;
            border: 1px solid #2E4057;
            margin-bottom: 2rem;
        }
        .ticker-move {
            display: flex;
            align-items: stretch;
            width: fit-content;
            animation: marquee 50s linear infinite;
        }
        .ticker-move:hover {
            animation-play-state: paused;
        }
        .ticker-item {
            width: 280px;
            margin: 0 10px;
            flex-shrink: 0;
        }
        .dashboard-card {
            background-color: #2A3F5F;
            border-radius: 6px;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 100%;
            border: 1px solid #2E4057;
        }
        .dashboard-card .card-title {
            font-size: 0.95rem;
            font-weight: 500;
            color: #A9B7D0;
        }
        .dashboard-card .card-value {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .dashboard-card .text-muted {
            color: #8A9BA8 !important;
        }
        .value-ok { color: #27AE60; } /* Professional green */
        .value-danger { color: #E74C3C; } /* Professional red */
        .btn-primary {
            background-color: #4A90E2;
            border-color: #4A90E2;
        }
        .btn-primary:hover {
            background-color: #357ABD;
            border-color: #357ABD;
        }
        .btn-secondary {
            background-color: #6C757D;
            border-color: #6C757D;
        }
        .btn-secondary:hover {
            background-color: #5A6268;
            border-color: #5A6268;
        }
        .btn-warning {
            background-color: #F1C40F;
            border-color: #F1C40F;
            color: #1E2A44;
        }
        .btn-warning:hover {
            background-color: #D4A017;
            border-color: #D4A017;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <section id="realtime-dashboard" class="mb-5">
        <h2 class="mb-3 text-slate-300">Últimas Leituras</h2>
        <div class="ticker-wrap">
            <div class="ticker-move" id="dashboard-ticker">
                <div class="text-center p-4 text-muted">A carregar dados...</div>
            </div>
        </div>
    </section>
    <h1 class="text-center mb-5 main-title">Bem-vindo ao WorkLog CMMS</h1>
    <div class="row justify-content-center">

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-box"></i> Gestão de Ativos</h5>
                    <p class="card-text text-muted">Gerir ativos, categorias e tipos de equipamento.</p>
                    <div class="mt-auto">
                        <a href="gerir_ativos.php" class="btn btn-primary stretched-link">Gerir Ativos</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-network-wired text-info"></i> Controle de Equipamentos</h5>
                    <p class="card-text text-muted">Monitorizar e controlar equipamentos remotos em tempo real.</p>
                    <div class="mt-auto">
                        <a href="dashboard_scada.php" class="btn btn-info text-white stretched-link">Aceder ao Dashboard</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-cogs"></i> Gestão de Administrador</h5>
                    <p class="card-text text-muted">Gerir utilizadores, ver logs e estado da licença do sistema.</p>
                    <div class="mt-auto">
                        <a href="manage_users.php" class="btn btn-primary">Utilizadores</a>
                        <?php if ($_SESSION['user_type'] === 'admin'): ?>
                            <a href="admin/view_logs.php" class="btn btn-secondary">Logs</a>
                            <a href="admin/licenca.php" class="btn btn-warning text-dark">Licença</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">
                        <i class="fas fa-envelope"></i> Sistema de Mensagens
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger ms-2"><?= $unread_count; ?></span>
                        <?php endif; ?>
                    </h5>
                    <p class="card-text text-muted">Ver e enviar mensagens para outros utilizadores.</p>
                    <div class="mt-auto">
                        <a href="inbox.php" class="btn btn-primary">Ver Mensagens</a>
                        <a href="send_message.php" class="btn btn-secondary">Nova Mensagem</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-file-alt"></i> Sistema de Relatórios</h5>
                    <p class="card-text text-muted">Ver e redigir relatórios diários e de manutenção.</p>
                    <div class="mt-auto">
                        <a href="list_reports.php" class="btn btn-primary">Listar Relatórios</a>
                        <a href="create_report.php" class="btn btn-secondary">Novo Relatório</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">
                        <i class="fas fa-tasks"></i> Ordens de Trabalho
                        <?php if ($unaccepted_ot_count > 0): ?>
                            <span class="badge bg-warning text-dark ms-2"><?= $unaccepted_ot_count; ?></span>
                        <?php endif; ?>
                    </h5>
                    <p class="card-text text-muted">Gerir as ordens de trabalho dos ativos.</p>
                    <div class="mt-auto">
                        <a href="list_work_orders.php" class="btn btn-primary">Ver Ordens</a>
                        <a href="create_work_order.php" class="btn btn-secondary">Nova Ordem</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-swimming-pool text-primary"></i> Módulo de Piscinas</h5>
                    <p class="card-text text-muted">Registe análises, consumos, consulte relatórios e monitorize os controladores em tempo real.</p>
                    <div class="mt-auto">
                        <a href="pools/registos.php" class="btn btn-primary stretched-link">Aceder ao Módulo</a>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-4">
            <div class="card card-link text-center h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-chart-pie"></i> Estatísticas</h5>
                    <p class="card-text text-muted">Ver estatísticas várias.</p>
                    <div class="mt-auto">
                        <a href="statistics.php" class="btn btn-primary">Ver Estatísticas</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tickerContainer = document.getElementById('dashboard-ticker');

    // ... (O resto do JavaScript permanece igual)
    function parseSafeFloat(value) { if (value === null || typeof value === 'undefined') return NaN; return parseFloat(String(value).replace(',', '.')); }
    function getValueClass(type, value) { const numValue = parseSafeFloat(value); if (isNaN(numValue)) return ''; switch (type) { case 'ph': return (numValue < 7.0 || numValue >= 8.0) ? 'value-danger' : 'value-ok'; case 'cloro': return (numValue < 1.0 || numValue >= 3.0) ? 'value-danger' : 'value-ok'; default: return 'value-ok'; } }
    function createCardHTML(item) { let formattedValue = 'N/A'; const numValue = parseSafeFloat(item.value); if (!isNaN(numValue)) { if (item.type === 'ph' || item.type === 'cloro') { formattedValue = numValue.toFixed(2).replace('.', ','); } else { formattedValue = new Intl.NumberFormat('pt-PT').format(numValue); } } const valueClass = getValueClass(item.type, item.value); const unit = item.unit || ''; return `<div class="ticker-item"><div class="dashboard-card"><div class="card-title">${item.title}</div><div class="card-value ${valueClass}">${formattedValue} <small class="text-muted">${unit}</small></div></div></div>`; }
    async function updateDashboard() { try { const response = await fetch('api/latest_status.php'); const data = await response.json(); let dataItems = []; if (data.analyses) { data.analyses.forEach(item => { dataItems.push({type: 'ph', title: `${item.tank_name} - pH`, value: item.ph_level, unit: ''}); dataItems.push({type: 'cloro', title: `${item.tank_name} - Cloro`, value: item.chlorine_level, unit: 'ppm'}); }); } if (data.agua_rede) { data.agua_rede.forEach(item => dataItems.push({type: 'rede', title: 'Contador da Rede', value: item.meter_value, unit: 'm³'})); } if (data.hipoclorito) { data.hipoclorito.forEach(item => dataItems.push({type: 'hipoclorito', title: `${item.tank_name} - Hipoclorito`, value: item.consumption_liters, unit: 'Litros'})); } if (dataItems.length === 0) { tickerContainer.innerHTML = '<div class="text-center p-4 text-muted">Nenhum dado recente para exibir.</div>'; return; } let htmlContent = dataItems.map(createCardHTML).join(''); tickerContainer.innerHTML = htmlContent + htmlContent; } catch (error) { tickerContainer.innerHTML = '<div class="text-center p-4 text-danger">Não foi possível carregar os dados.</div>'; console.error(error); } }
    updateDashboard();
    setInterval(updateDashboard, 60000);
});
</script>
<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>