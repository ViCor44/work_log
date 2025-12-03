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
</style>

<div class="container-fluid mt-4">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Monitorização Detalhada: <?= htmlspecialchars($tank_name) ?></h1>
	    <div>
	        <a href="tank_pid_manager.php?tank_id_filter=<?= $tank_id ?>" class="btn btn-warning">Definições Avançadas</a>
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
                    </div>
                </div>
                <div class="col-12 mb-3">
                    <div class="gauge-card">
                        <h5>pH</h5>
                        <canvas id="phGauge" height="150"></canvas>
                        <div id="ph-details" class="details-box"></div>
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
               <div class="row">
			   		<div class="col-12"> <h5 class="text-center">Histórico de Cloro Livre (mg/L)</h5>
		                <canvas id="cloroHistoryChart" height="350"></canvas> </div>
		            <div class="col-12 mb-4"> <h5 class="text-center">Histórico de pH</h5>
		                <canvas id="phHistoryChart" height="350"></canvas> </div>
		            
		        </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tankId = <?= $tank_id ?>;
    const controllerIp = '<?= $controller_ip ?>';
    let phHistoryChart, cloroHistoryChart;
    let tempGauge, phGauge, cloroGauge;

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
	function createDualAxisHistoryChart(ctx, chartLabels, datasets) {
        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: datasets // Passa o array de datasets diretamente
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permite alturas diferentes
                scales: {
                    yAxes: [
                        { id: 'y-axis-line', type: 'linear', position: 'left' },
                        { id: 'y-axis-bar', type: 'linear', position: 'right', ticks: { min: 0, max: 100, callback: function(value) { return value + "%" } } }
                    ]
                },
				plugins: {
	                zoom: {
	                    // Opções de Pan (arrastar)
	                    pan: {
	                        enabled: true, // Ativa o arrastar
	                        mode: 'x',     // Arrastar apenas no eixo X (tempo)
	                    },
	                    // Opções de Zoom
	                    zoom: {
	                        enabled: true, // Ativa o zoom
	                        mode: 'x',     // Zoom apenas no eixo X (tempo)
	                        // Para fazer zoom, o utilizador pode usar a roda do rato ou o gesto "pinch"
	                    }
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
                cloroGauge.update();	        }
	        
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
	            <div class="detail-row"><span>Setpoint:</span> <strong>${cloroSetpoint || 'N/A'}</strong></div>
	            <div class="detail-row"><span>Dosagem:</span> <strong>${cl_formattedState}</strong></div>
	            <div class="detail-row"><span>Distúrbio:</span> <strong>${cl_disturbance || 'N/A'}</strong></div>
	        `;
	
	    } catch (error) {
	        console.error("Error updating gauges:", error);
	        // Add a visual error indicator here if you want
	    }
	}

    // Função para ir buscar o histórico
        async function fetchHistory(startDate, endDate) {
        try {
            const url = `../api/get_pool_history.php?id=${tankId}&start_date=${startDate}&end_date=${endDate}`;
            const response = await fetch(url);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            // Prepara os dados para os gráficos
            const historyLabels = data.history.map(rec => new Date(rec.log_datetime).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }));
            
            const phValues = data.history.map(rec => rec.ph_value);
            const phDosagem = data.history.map(rec => parseFloat(rec.ph_controller_state) || 0);
            const phSetpoints = data.history.map(rec => rec.ph_setpoint); // Novo array para setpoints de pH

            const cloroValues = data.history.map(rec => rec.chlorine_value);
            const cloroDosagem = data.history.map(rec => parseFloat(rec.cl_controller_state) || 0);
            const cloroSetpoints = data.history.map(rec => rec.chlorine_setpoint); // Novo array para setpoints de Cloro

            // Destrói os gráficos antigos
            if (phHistoryChart) phHistoryChart.destroy();
            if (cloroHistoryChart) cloroHistoryChart.destroy();
            
            // Cria os datasets para o gráfico de pH (valor, dosagem e setpoint)
            const phDatasets = [
                { type: 'line', label: 'pH (Valor)', data: phValues, borderColor: 'rgba(54, 162, 235, 1)', yAxisID: 'y-axis-line', fill: false, tension: 0.1 },
                { type: 'bar', label: 'Dosagem', data: phDosagem, backgroundColor: 'rgba(54, 162, 235, 0.2)', yAxisID: 'y-axis-bar' },
                { type: 'line', label: 'Setpoint', data: phSetpoints, borderColor: 'rgba(255, 99, 132, 0.8)', borderWidth: 2, yAxisID: 'y-axis-line', fill: false, pointRadius: 0 }
            ];
            
            // Cria os datasets para o gráfico de Cloro (valor, dosagem e setpoint)
            const cloroDatasets = [
                { type: 'line', label: 'Cloro (Valor)', data: cloroValues, borderColor: 'rgba(75, 192, 192, 1)', yAxisID: 'y-axis-line', fill: false, tension: 0.1 },
                { type: 'bar', label: 'Dosagem', data: cloroDosagem, backgroundColor: 'rgba(75, 192, 192, 0.2)', yAxisID: 'y-axis-bar' },
                { type: 'line', label: 'Setpoint', data: cloroSetpoints, borderColor: 'rgba(255, 99, 132, 0.8)', borderWidth: 2, yAxisID: 'y-axis-line', fill: false, pointRadius: 0 }
            ];
            
            phHistoryChart = createDualAxisHistoryChart(document.getElementById('phHistoryChart').getContext('2d'), historyLabels, phDatasets);
            cloroHistoryChart = createDualAxisHistoryChart(document.getElementById('cloroHistoryChart').getContext('2d'), historyLabels, cloroDatasets);

        } catch (error) {
            console.error("Erro ao carregar histórico:", error);
        }
    }
    
    document.getElementById('date-range-form').addEventListener('submit', function(e) {
        e.preventDefault();
        fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    });

    // Carga inicial
    updateGauges();
    fetchHistory(document.getElementById('start_date').value, document.getElementById('end_date').value);
    
    // Inicia o ciclo de atualização para os gauges a cada 10 segundos
    setInterval(updateGauges, 10000);
});
</script>

<?php
require_once '../footer.php';
?>


