<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];

// As suas contagens de mensagens e OTs existentes
// ... (o seu código PHP existente para contar mensagens e OTs permanece igual) ...
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WorkLog CMMS - Dashboard</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/work_log/css/all.min.css">
    <style>
        body {
            background: url('images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp') no-repeat center center fixed;
            background-size: cover;
        }
        .main-container {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            padding: 2rem;
            margin-top: 2rem;
        }
        .card-link {
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            border-radius: 10px;
        }
        .card-link:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        /* Estilos para o novo Dashboard */
        .dashboard-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        .dashboard-card .card-title {
            font-size: 1rem;
            font-weight: bold;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        .dashboard-card .card-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #343a40;
        }
        .value-ok { color: #198754; } /* Verde */
        .value-warning { color: #ffc107; } /* Amarelo */
        .value-danger { color: #dc3545; } /* Vermelho */
		@keyframes marquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); } /* Anima até metade (a cópia) */
        }
        .ticker-wrap {
            width: 100%;
            overflow: hidden; /* Esconde o que está fora da viewport */
            background-color: #f8f9fa;
            padding: 20px 0;
            border-radius: 10px;
        }
        .ticker-move {
            display: flex;
            align-items: stretch;
            width: fit-content;
            animation: marquee 40s linear infinite; /* Ajuste o tempo (40s) para mudar a velocidade */
        }
        .ticker-move:hover {
            animation-play-state: paused; /* Pausa a animação ao passar o rato por cima */
        }
        .ticker-item {
            width: 280px; /* Largura fixa para cada card */
            margin: 0 15px;
            flex-shrink: 0;
        }
        .dashboard-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            height: 100%;
        }
        .dashboard-card .card-title { font-size: 0.9rem; font-weight: bold; color: #6c757d; }
        .dashboard-card .card-value { font-size: 2rem; font-weight: 700; }
        .value-ok { color: #198754; }
        .value-danger { color: #dc3545; }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container main-container">
    
   <section id="realtime-dashboard" class="mb-5">
        <h2 class="mb-3">Estado Atual em Tempo Real</h2>
        <div class="ticker-wrap">
            <div class="ticker-move" id="dashboard-ticker">
                <div class="text-center p-4 text-muted">A carregar dados...</div>
            </div>
        </div>
    </section>
    
    <hr class="my-5">

    <h1 class="text-center mb-5">Bem-vindo ao WorkLog CMMS</h1>
    <div class="row justify-content-center">
        <div class="col-md-4 mb-4">
            <div class="card card-link text-center">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-swimming-pool"></i> Gestão de Tanques</h5>
                    <p class="card-text">Registar análises e consumos de tanques e piscinas.</p>
                    <a href="pools/gerir_tanques.php" class="btn btn-primary">Gerir Tanques</a>
                    <a href="pools/registos.php" class="btn btn-secondary">Registos</a>
                </div>
            </div>
        </div>
        </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tickerContainer = document.getElementById('dashboard-ticker');
    let isInitialLoad = true;

    function getValueClass(value, low, high) {
        if (value === null || isNaN(value)) return '';
        return (value < low || value > high) ? 'value-danger' : 'value-ok';
    }
    
    function createCardHTML(item) {
        let title, value, unit, valueClass = '';
        
        switch(item.type) {
            case 'ph':
                title = `${item.tank_name} - pH`;
                value = parseFloat(item.value).toFixed(2);
                valueClass = getValueClass(item.value, 7.2, 7.6);
                break;
            case 'cloro':
                title = `${item.tank_name} - Cloro`;
                value = parseFloat(item.value).toFixed(2);
                unit = 'ppm';
                valueClass = getValueClass(item.value, 1.0, 3.0);
                break;
            case 'rede':
                title = 'Contador da Rede';
                value = new Intl.NumberFormat('pt-PT').format(item.value);
                unit = 'm³';
                break;
            case 'hipoclorito':
                title = `${item.tank_name} - Hipoclorito`;
                value = new Intl.NumberFormat('pt-PT').format(item.value);
                unit = 'Litros';
                break;
        }

        // Usamos IDs únicos para cada valor e unidade para podermos atualizá-los depois
        return `
            <div class="ticker-item">
                <div class="dashboard-card">
                    <div class="card-title">${title}</div>
                    <div class="card-value ${valueClass}" id="value-${item.id}">${value} 
                        <small class="text-muted" id="unit-${item.id}">${unit}</small>
                    </div>
                </div>
            </div>
        `;
    }

    async function updateDashboard() {
        try {
            const response = await fetch('api/latest_status.php');
            const data = await response.json();
            
            let dataItems = [];
            // Transforma os dados da API numa lista simples de items para os cards
            if (data.analyses) {
                data.analyses.forEach(item => {
                    dataItems.push({id: `ph-${item.tank_id}`, type: 'ph', tank_name: item.tank_name, value: item.ph_level});
                    dataItems.push({id: `cl-${item.tank_id}`, type: 'cloro', tank_name: item.tank_name, value: item.chlorine_level});
                });
            }
            if (data.agua_rede) {
                data.agua_rede.forEach(item => {
                    dataItems.push({id: 'rede', type: 'rede', value: item.meter_value});
                });
            }
            if (data.hipoclorito) {
                data.hipoclorito.forEach(item => {
                    dataItems.push({id: `hipo-${item.tank_id}`, type: 'hipoclorito', tank_name: item.tank_name, value: item.consumption_liters});
                });
            }

            if (isInitialLoad) {
                // Na primeira carga, cria os cards e duplica-os para a animação
                let htmlContent = dataItems.map(createCardHTML).join('');
                tickerContainer.innerHTML = htmlContent + htmlContent; // Duplica o conteúdo
                isInitialLoad = false;
            } else {
                // Nas atualizações seguintes, apenas muda os valores, sem interromper a animação
                dataItems.forEach(item => {
                    const valueEl = document.getElementById(`value-${item.id}`);
                    if (valueEl) {
                        let formattedValue = '';
                        let newClass = '';
                        switch(item.type) {
                            case 'ph':
                                formattedValue = parseFloat(item.value).toFixed(2);
                                newClass = getValueClass(item.value, 7.2, 7.6);
                                break;
                            case 'cloro':
                                formattedValue = parseFloat(item.value).toFixed(2);
                                newClass = getValueClass(item.value, 1.0, 3.0);
                                break;
                            default:
                                formattedValue = new Intl.NumberFormat('pt-PT').format(item.value);
                                break;
                        }
                        valueEl.innerHTML = `${formattedValue} <small class="text-muted" id="unit-${item.id}">${valueEl.querySelector('small').innerHTML}</small>`;
                        valueEl.className = `card-value ${newClass}`;
                    }
                });
            }

        } catch (error) {
            if (isInitialLoad) tickerContainer.innerHTML = '<div class="text-center p-4 text-danger">Não foi possível carregar os dados.</div>';
            console.error(error);
        }
    }

    updateDashboard();
    setInterval(updateDashboard, 60000); // Atualiza a cada 60 segundos
});
</script>

<script src="/work_log/js/popper.min.js"></script>
<script src="/work_log/js/bootstrap.min.js"></script>
</body>
</html>