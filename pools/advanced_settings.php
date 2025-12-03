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
$stmt->close();
?>

<style>
.log-box {
    max-height: 500px;
    overflow-y: scroll;
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: .25rem;
    padding: 1rem;
}
.log-box table {
    margin-bottom: 0;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Configurações Avançadas: <?= htmlspecialchars($tank_name) ?></h1>
        <div>
            <a href="view_pool_details.php?id=<?= $tank_id ?>" class="btn btn-secondary">Voltar à Monitorização</a>
            <a href="dashboard.php" class="btn btn-secondary">Voltar ao Dashboard</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            Valores Atuais do PID
        </div>
        <div class="card-body">
            <div class="row" id="pid-values-container">
                <div class="col-md-6 mb-3">
                    <h5>Cloro (C1)</h5>
                    <ul class="list-group">
                        <li class="list-group-item">P: <span id="c1-p-value">A carregar...</span></li>
                        <li class="list-group-item">I: <span id="c1-i-value">A carregar...</span></li>
                        <li class="list-group-item">D: <span id="c1-d-value">A carregar...</span></li>
                    </ul>
                </div>
                <div class="col-md-6 mb-3">
                    <h5>pH (C2)</h5>
                    <ul class="list-group">
                        <li class="list-group-item">P: <span id="c2-p-value">A carregar...</span></li>
                        <li class="list-group-item">I: <span id="c2-i-value">A carregar...</span></li>
                        <li class="list-group-item">D: <span id="c2-d-value">A carregar...</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            Histórico de PID e Alarmes
        </div>
        <div class="card-body">
            <div class="log-box">
                <table class="table table-striped table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Tipo de Registo</th>
                            <th>Detalhes</th>
                        </tr>
                    </thead>
                    <tbody id="history-log-table">
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tankId = <?= $tank_id ?>;
    const controllerIp = '<?= $controller_ip ?>';

    // Function to fetch and update current PID values
    async function updatePidValues() {
        if (!controllerIp) {
            document.getElementById('pid-values-container').innerHTML = '<p class="text-danger">Não há IP de controlador configurado para este tanque.</p>';
            return;
        }

        try {
            // This API endpoint will need to be created.
            // It will fetch the PID values directly from the controller's API.
            const response = await fetch(`get_controller_data.php?ip=${controllerIp}`);
            const data = await response.json();

            if (data.error) throw new Error(data.error);
            
            // Assuming your controller returns keys like 'C1P', 'C1I', 'C1D' for Chlorine and 'C2P', 'C2I', 'C2D' for pH
            document.getElementById('c1-p-value').textContent = data.C1P || 'N/A';
            document.getElementById('c1-i-value').textContent = data.C1I || 'N/A';
            document.getElementById('c1-d-value').textContent = data.C1D || 'N/A';
            
            document.getElementById('c2-p-value').textContent = data.C2P || 'N/A';
            document.getElementById('c2-i-value').textContent = data.C2I || 'N/A';
            document.getElementById('c2-d-value').textContent = data.C2D || 'N/A';

        } catch (error) {
            console.error("Erro ao carregar valores PID:", error);
            document.getElementById('pid-values-container').innerHTML = '<p class="text-danger">Erro ao carregar valores. Verifique a conexão com o controlador.</p>';
        }
    }

    // Function to fetch and display the history log
    async function fetchHistoryLogs() {
        try {
            // This API endpoint will need to be created.
            // It will fetch PID changes and alarms from a new database table.
            const response = await fetch(`../api/get_pid_log.php?tank_id=${tankId}`);
            const logs = await response.json();
            const tbody = document.getElementById('history-log-table');
            tbody.innerHTML = ''; // Clear previous logs

            if (logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center">Nenhum registo encontrado.</td></tr>';
                return;
            }

            logs.forEach(log => {
                const row = `
                    <tr>
                        <td>${log.log_datetime}</td>
                        <td>${log.log_type}</td>
                        <td>${log.details}</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });

        } catch (error) {
            console.error("Erro ao carregar histórico de logs:", error);
            document.getElementById('history-log-table').innerHTML = '<tr><td colspan="3" class="text-danger text-center">Erro ao carregar logs.</td></tr>';
        }
    }

    // Initial load
    updatePidValues();
    fetchHistoryLogs();

    // Set a refresh interval for current PID values
    setInterval(updatePidValues, 10000); // Refresh every 10 seconds
});
</script>

<?php
require_once '../footer.php';
?>