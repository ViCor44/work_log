<?php
require_once '../header.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Central de Medida inválida.");
}

$meter_id = $_GET['id'];

// Busca o nome e o IP da central para o título e para o JavaScript
$stmt = $conn->prepare("SELECT local, ip_address FROM centrais_de_medida WHERE id = ?");
$stmt->bind_param("i", $meter_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Central de Medida não encontrada.");
}

$meter_info = $result->fetch_assoc();
$meter_name = $meter_info['local'];
$meter_ip = $meter_info['ip_address'];
$stmt->close();
?>

<script src="/work_log/js/Chart.min.js"></script>
<script src="/work_log/js/chartjs-gauge.min.js"></script>

<style>
    /* ================================================= */
    /* ESTILOS PROFISSIONAIS - MONITORIZAÇÃO DETALHADA    */
    /* ================================================= */
    body {
            background-color: #1E2A44; /* Deep navy blue for a professional backdrop */
            color: #D3D8E0; /* Light gray text for readability */
            font-family: 'Arial', sans-serif;
        }

    .page-title {
        color: #ECF0F7;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        font-size: 1.8rem;
    }

    .gauge-card {
        background-color: #2A3F5F;
        border: 1px solid #2E4057;
        border-radius: 10px;
        padding: 1.5rem;
        text-align: center;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .gauge-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(74, 144, 226, 0.25);
    }

    .gauge-card h5 {
        color: #A9B7D0;
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 1rem;
    }

    .details-card {
        background-color: #2A3F5F;
        border: 1px solid #2E4057;
        border-radius: 10px;
        padding: 1.5rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        position: relative;
    }

    .details-table-body tr {
        cursor: pointer;
        color: #D3D8E0;
    }

    .details-table-body tr:hover {
        background-color: #34495E;
    }

    .table {
        background-color: #2A3F5F;
        color: #D3D8E0;
    }

    .table th, .table td {
        border-color: #2E4057;
    }

    .chart-stats {
        position: absolute;
        top: 20px;
        right: 80px;
        background-color: rgba(42, 63, 95, 0.95);
        padding: 8px 12px;
        border-radius: 5px;
        font-size: 1rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 10;
        color: #A9B7D0;
    }

    .chart-stats span {
        margin-left: 10px;
    }

    .btn {
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        padding: 0.75rem 1rem;
        border-radius: 8px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }

    .btn-secondary {
        background-color: #6C757D;
        border-color: #6C757D;
    }
    .btn-secondary:hover {
        background-color: #5A6268;
        border-color: #5A6268;
        transform: translateY(-2px);
    }

    .modal-content {
        background-color: #2A3F5F;
        color: #D3D8E0;
        border: 1px solid #2E4057;
    }

    .modal-header {
        border-bottom: 1px solid #2E4057;
    }

    .form-control {
        background-color: #34495E;
        border: 1px solid #2E4057;
        color: #D3D8E0;
    }

    .form-control:focus {
        background-color: #34495E;
        border-color: #4A90E2;
        box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
    }

    .btn-primary {
        background-color: #4A90E2;
        border-color: #4A90E2;
    }
    .btn-primary:hover {
        background-color: #357ABD;
        border-color: #357ABD;
    }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title">Monitorização Detalhada: <?= htmlspecialchars($meter_name) ?></h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
    <div class="row">
        <div class="col-lg-3">
            <div class="gauge-card">
                <h5>Tensão Média (V)</h5>
                <canvas id="voltageGauge" height="100"></canvas>
            </div>
            <div class="gauge-card">
                <h5>Corrente Média (A)</h5>
                <canvas id="currentGauge" height="100"></canvas>
            </div>
            <div class="gauge-card">
                <h5>Potência Ativa Total (kW)</h5>
                <canvas id="powerGauge" height="100"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="details-card">
                <h5 class="mb-3">Leituras Detalhadas (clique numa linha para ver o histórico)</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <tbody id="details-table-body" class="details-table-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal fade" id="historyModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="historyModalTitle">Histórico do Parâmetro</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form class="row g-3 mb-3" id="modal-date-range-form">
                            <div class="col-md-4">
                                <label for="modal_start_date" class="form-label">Data Início</label>
                                <input type="date" class="form-control" id="modal_start_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="modal_end_date" class="form-label">Data Fim</label>
                                <input type="date" class="form-control" id="modal_end_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                            </div>
                        </form>
                        <canvas id="parameterHistoryChart" height="120"></canvas>
                        <div id="chart-stats-container" class="chart-stats" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const meterId = <?= $meter_id ?>;
    const meterIp = '<?= $meter_ip ?>';
    let voltageGauge, currentGauge, powerGauge;
    let parameterHistoryChart;
    let currentParameter = '';
    let currentParameterLabel = '';

    // Função para criar um manómetro (gauge)
    function createGauge(ctx, min, max, value, label) {
        return new Chart(ctx, {
            type: 'gauge',
            data: {
                datasets: [{
                    value: value,
                    minValue: min,
                    data: [min, (min + max) / 2, max],
                    backgroundColor: ['#198754', '#ffc107', '#dc3545'],
                }]
            },
            options: {
                responsive: true,
                title: { text: label, display: true, fontColor: 'white', fontSize: 16 },
                needle: { radiusPercentage: 2, widthPercentage: 3, lengthPercentage: 80, color: 'rgba(0, 0, 0, 1)' },
                valueLabel: { display: true, fontSize: 16, color: 'white', formatter: (val) => val.toFixed(2) }
            }
        });
    }

    async function updateMeterData() {
        try {
            const response = await fetch(`get_controller_data.php?ip=${meterIp}`);
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            // ATENÇÃO: As chaves devem corresponder EXATAMENTE ao seu ficheiro XML
            const voltage = parseFloat(data.voltageLNAvg) || 0;
            const current = parseFloat(data.currentAvg) || 0;
            const power = parseFloat(data.activePowerTotal) || 0; // Converte para kW

            // Atualiza os Gauges
            if (!voltageGauge) voltageGauge = createGauge(document.getElementById('voltageGauge').getContext('2d'), 350, 450, voltage, 'Tensão (V)');
            else { voltageGauge.data.datasets[0].value = voltage; voltageGauge.update(); }

            if (!currentGauge) currentGauge = createGauge(document.getElementById('currentGauge').getContext('2d'), 0, 400, current, 'Corrente (A)');
            else { currentGauge.data.datasets[0].value = current; currentGauge.update(); }

            if (!powerGauge) powerGauge = createGauge(document.getElementById('powerGauge').getContext('2d'), 0, 200, power, 'Potência (kW)');
            else { powerGauge.data.datasets[0].value = power; powerGauge.update(); }

            // Atualiza a tabela de detalhes (removendo max/min display)
            const detailsBody = document.getElementById('details-table-body');
            detailsBody.innerHTML = `
                <tr data-param="voltageAB" data-label="Tensão AB"><th>Tensão AB</th><td>${parseFloat(data.voltageAB || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageBC" data-label="Tensão BC"><th>Tensão BC</th><td>${parseFloat(data.voltageBC || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageCA" data-label="Tensão CA"><th>Tensão CA</th><td>${parseFloat(data.voltageCA || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageLLAvg" data-label="Tensão Média LL"><th>Tensão Média LL</th><td>${parseFloat(data.voltageLLAvg || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageAN" data-label="Tensão AN"><th>Tensão AN</th><td>${parseFloat(data.voltageAN || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageBN" data-label="Tensão BN"><th>Tensão BN</th><td>${parseFloat(data.voltageBN || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageCN" data-label="Tensão CN"><th>Tensão CN</th><td>${parseFloat(data.voltageCN || 0).toFixed(1)} V</td></tr>
                <tr data-param="voltageLNAvg" data-label="Tensão Média LN"><th>Tensão Média LN</th><td>${parseFloat(data.voltageLNAvg || 0).toFixed(1)} V</td></tr>
                <tr data-param="currentA" data-label="Corrente Fase A"><th>Corrente Fase A</th><td>${parseFloat(data.currentA || 0).toFixed(2)} A</td></tr>
                <tr data-param="currentB" data-label="Corrente Fase B"><th>Corrente Fase B</th><td>${parseFloat(data.currentB || 0).toFixed(2)} A</td></tr>
                <tr data-param="currentC" data-label="Corrente Fase C"><th>Corrente Fase C</th><td>${parseFloat(data.currentC || 0).toFixed(2)} A</td></tr>
                <tr data-param="currentAvg" data-label="Corrente Média"><th>Corrente Média</th><td>${parseFloat(data.currentAvg || 0).toFixed(2)} A</td></tr>
                <tr data-param="activePowerA" data-label="Potência Ativa A"><th>Potência Ativa A</th><td>${parseFloat(data.activePowerA || 0).toFixed(2)} kW</td></tr>
                <tr data-param="activePowerB" data-label="Potência Ativa B"><th>Potência Ativa B</th><td>${parseFloat(data.activePowerB || 0).toFixed(2)} kW</td></tr>
                <tr data-param="activePowerC" data-label="Potência Ativa C"><th>Potência Ativa C</th><td>${parseFloat(data.activePowerC || 0).toFixed(2)} kW</td></tr>
                <tr data-param="activePowerTotal" data-label="Potência Ativa Total"><th>Potência Ativa Total</th><td>${parseFloat(data.activePowerTotal || 0).toFixed(2)} kW</td></tr>
                <tr data-param="powerFactorTotal" data-label="Fator de Potência Total"><th>Fator de Potência Total</th><td>${parseFloat(data.powerFactorTotal || 0).toFixed(3)}</td></tr>
                <tr data-param="frequency" data-label="Frequência"><th>Frequência</th><td>${parseFloat(data.frequency || 0).toFixed(1)} Hz</td></tr>
            `;
            detailsBody.querySelectorAll('tr').forEach(row => {
                row.addEventListener('click', function() {
                    const parameter = this.dataset.param;
                    const label = this.dataset.label;
                    console.log('Row clicked:', parameter, label); // Debug
                    document.getElementById('historyModalTitle').textContent = `Histórico de ${label}`;
                    fetchParameterHistory(parameter, label);
                    new bootstrap.Modal(document.getElementById('historyModal')).show();
                });
            });

            // Evento para o filtro de datas do modal
            document.getElementById('modal-date-range-form').addEventListener('submit', function(e) {
                e.preventDefault();
                fetchParameterHistory(currentParameter, currentParameterLabel);
            });

        } catch (error) {
            console.error("Erro ao carregar dados da Central de Medida:", error);
            document.getElementById('details-table-body').innerHTML = '<tr><td colspan="2" class="text-center text-danger">Erro de comunicação com a central.</td></tr>';
        }
    }

    // Função para criar o gráfico de histórico no modal
    function createParameterChart(labels, values) {
        if (parameterHistoryChart) {
            parameterHistoryChart.destroy();
        }
        const ctx = document.getElementById('parameterHistoryChart').getContext('2d');
        parameterHistoryChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: currentParameterLabel,
                    data: values,
                    borderColor: '#0d6efd',
                    tension: 0.1
                }]
            },
            options: { responsive: true }
        });

        const statsContainer = document.getElementById('chart-stats-container');
        if (!statsContainer) {
            console.error('chart-stats-container not found in DOM');
            return;
        }

        const validValues = values.filter(v => v !== null && !isNaN(v));
        console.log('Valid Values:', validValues); // Debug valid values

        if (validValues.length > 0) {
            const maxVal = Math.max(...validValues);
            const minVal = Math.min(...validValues);
            statsContainer.innerHTML = `
                <span><strong>Máx:</strong> ${maxVal.toFixed(2)}</span>
                <span><strong>Mín:</strong> ${minVal.toFixed(2)}</span>
            `;
            statsContainer.style.display = 'block';
            // Force re-render by toggling display
            setTimeout(() => {
                statsContainer.style.display = 'none';
                setTimeout(() => {
                    statsContainer.style.display = 'block';
                    console.log('Stats Container Updated:', statsContainer.innerHTML); // Debug
                }, 10);
            }, 10);
        } else {
            statsContainer.innerHTML = '<span class="text-warning">Nenhum dado válido disponível.</span>';
            statsContainer.style.display = 'block';
            console.log('No valid values, showing warning'); // Debug
        }
    }

    // Função para ir buscar o histórico de um parâmetro específico
    async function fetchParameterHistory(parameterName, parameterLabel) {
        currentParameter = parameterName;
        currentParameterLabel = parameterLabel;

        const startDate = document.getElementById('modal_start_date').value;
        const endDate = document.getElementById('modal_end_date').value;

        try {
            const url = `../api/get_meter_parameter_history.php?meter_id=${meterId}&parameter=${parameterName}&start_date=${startDate}&end_date=${endDate}`;
            const response = await fetch(url);
            const data = await response.json();
            console.log('API Response:', data); // Debug API response

            if (data.error) throw new Error(data.error);

            const labels = data.map(rec => new Date(rec.log_datetime).toLocaleString('pt-PT'));
            const values = data.map(rec => parseFloat(rec.value) || 0); // Convert to number, default to 0
            console.log('Values:', values); // Debug values

            createParameterChart(labels, values);
        } catch (error) {
            console.error("Erro ao carregar histórico do parâmetro:", error);
            const statsContainer = document.getElementById('chart-stats-container');
            if (statsContainer) {
                statsContainer.innerHTML = '<span class="text-danger">Erro ao carregar dados do histórico.</span>';
                statsContainer.style.display = 'block';
            }
        }
    }

    updateMeterData();
    setInterval(updateMeterData, 10000);
});
</script>

<?php
require_once '../footer.php';
?>