<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';

// Função para contar relatórios por técnico
function getReportsByTechnician($conn) {
    $sql = "SELECT u.first_name, u.last_name, COUNT(r.id) as total_reports 
            FROM reports r 
            JOIN users u ON r.technician_id = u.id 
            GROUP BY r.technician_id";
    $result = $conn->query($sql);
    $reports_by_technician = [];
    while ($row = $result->fetch_assoc()) {
        $reports_by_technician[] = $row;
    }
    return $reports_by_technician;
}

function getWorkOrdersByTechnician($conn) {
    $sql = "SELECT u.first_name, u.last_name, COUNT(wo.id) AS total_work_orders
            FROM users u
            JOIN work_orders wo ON u.id = wo.assigned_user
            GROUP BY u.id";
    
    $result = $conn->query($sql);

    if (!$result) {
        die("Erro na consulta SQL: " . $conn->error);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    return $data;
}

// Função para contar ordens de trabalho por status
function getWorkOrdersByStatus($conn) {
    $sql = "SELECT status, COUNT(id) as total_work_orders 
            FROM work_orders 
            GROUP BY status";
    $result = $conn->query($sql);
    $work_orders_by_status = [];
    while ($row = $result->fetch_assoc()) {
        $work_orders_by_status[] = $row;
    }
    return $work_orders_by_status;
}

// Função para contar ordens de trabalho por prioridade
function getWorkOrdersByPriority($conn) {
    $sql = "SELECT priority, COUNT(id) as total_work_orders 
            FROM work_orders 
            GROUP BY priority";
    $result = $conn->query($sql);
    $work_orders_by_priority = [];
    while ($row = $result->fetch_assoc()) {
        $work_orders_by_priority[] = $row;
    }
    return $work_orders_by_priority;
}

// Função para contar total de ordens de trabalho abertas e fechadas
function getTotalOpenClosedWorkOrders($conn) {
    $sql = "SELECT 
                (SELECT COUNT(*) FROM work_orders WHERE status != 'Fechada') as total_open,
                (SELECT COUNT(*) FROM work_orders WHERE status = 'Fechada') as total_closed";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Função para calcular o tempo médio de conclusão das ordens de trabalho
function getAverageCompletionTime($conn) {
    $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as avg_completion_time
            FROM work_orders 
            WHERE status = 'Fechada'";
    $result = $conn->query($sql);
    return $result->fetch_assoc();
}

// Consulta para obter a OT com menor tempo de execução
$query_min = "SELECT id, description, TIMESTAMPDIFF(HOUR, created_at, closed_at) AS execution_time
              FROM work_orders
              WHERE status = 'Fechada'
              ORDER BY execution_time ASC
              LIMIT 1";
$result_min = mysqli_query($conn, $query_min);
$min_ot = mysqli_fetch_assoc($result_min);

// Consulta para obter a OT com maior tempo de execução
$query_max = "SELECT id, description, TIMESTAMPDIFF(HOUR, created_at, closed_at) AS execution_time
              FROM work_orders
              WHERE status = 'Fechada'
              ORDER BY execution_time DESC
              LIMIT 1";
$result_max = mysqli_query($conn, $query_max);
$max_ot = mysqli_fetch_assoc($result_max);

$work_orders_by_technician = getWorkOrdersByTechnician($conn);
$reports_by_technician = getReportsByTechnician($conn);
$work_orders_by_status = getWorkOrdersByStatus($conn);
$work_orders_by_priority = getWorkOrdersByPriority($conn);
$total_open_closed_work_orders = getTotalOpenClosedWorkOrders($conn);
$average_completion_time = getAverageCompletionTime($conn);
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <script src="/work_log/js/chart.min.js"></script>
    <style>
        body {
            background-color: #f8f9fa;
        }

        .card {
            margin-top: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <h1>Estatísticas</h1> 
        
        <div class="d-flex mb-3">
            <a href="redirect_page.php" class="btn btn-secondary">Voltar</a>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h3>Relatórios por Técnico</h3>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Técnico</th>
                                    <th>Total de Relatórios</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reports_by_technician as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['first_name']) . ' ' . htmlspecialchars($row['last_name']) ?></td>
                                    <td><?= htmlspecialchars($row['total_reports']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>  
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h2>Ordens de Trabalho por Técnico</h2>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Técnico</th>
                                    <th>Total de OTs</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($work_orders_by_technician as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['first_name']) . ' ' . htmlspecialchars($row['last_name']) ?></td>
                                    <td><?= htmlspecialchars($row['total_work_orders']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h3>Ordens de Trabalho por Status</h3>
                        <div class="chart-container">
                            <canvas id="workOrdersByStatusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h3>Ordens de Trabalho por Prioridade</h3>
                        <div class="chart-container">
                            <canvas id="workOrdersByPriorityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Execução de Ordens de Trabalho</h3>
                    </div>
                    <div class="card-body">
                        <h4>Tempo Médio de Conclusão</h4>
                        <p class="display-6"><?= round($average_completion_time['avg_completion_time'], 2) ?> horas</p>
                        
                        <h5>OT com Menor Tempo de Execução</h5>
                        <p>
                            <strong>ID:</strong> 
                            <a href="view_work_order.php?id=<?= htmlspecialchars($min_ot['id']) ?>" class="text-primary">
                                <?= htmlspecialchars($min_ot['id']) ?>
                            </a> - 
                            <?= htmlspecialchars($min_ot['description']) ?> - 
                            <span class="badge bg-success"><?= htmlspecialchars($min_ot['execution_time']) ?> horas</span>
                        </p>

                        <h5>OT com Maior Tempo de Execução</h5>
                        <p>
                            <strong>ID:</strong> 
                            <a href="view_work_order.php?id=<?= htmlspecialchars($max_ot['id']) ?>" class="text-primary">
                                <?= htmlspecialchars($max_ot['id']) ?>
                            </a> - 
                            <?= htmlspecialchars($max_ot['description']) ?> - 
                            <span class="badge bg-danger"><?= htmlspecialchars($max_ot['execution_time']) ?> horas</span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">Total de OTs</h3>
                    </div>
                    <div class="card-body">                        
                        <h4>Abertas: </h4>
                        <p>
                            <a href="list_work_orders.php?status=open">
                                <?= $total_open_closed_work_orders['total_open'] ?>
                            </a>
                        </p>                        
                        <h4>Fechadas: </h4>
                        <p>
                            <a href="list_work_orders.php?status=closed">
                                <?= $total_open_closed_work_orders['total_closed'] ?>
                            </a>
                        </p>
                    </div>               
                </div>  
            </div>
        </div>

    </div>

    <script>
        const workOrdersByStatusData = <?= json_encode($work_orders_by_status) ?>;
        const workOrdersByPriorityData = <?= json_encode($work_orders_by_priority) ?>;         

        // Função para criar o gráfico de ordens de trabalho por status
        function createWorkOrdersByStatusChart(data) {
            const ctx = document.getElementById('workOrdersByStatusChart').getContext('2d');
            const labels = data.map(item => item.status);
            const values = data.map(item => item.total_work_orders);
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Ordens de Trabalho por Status',
                        data: values,
                        backgroundColor: 'rgba(153, 102, 255, 0.2)',
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            ticks: {
                                stepSize: 1 // Define o incremento do eixo Y para 1
                            }
                        }
                    }
                }
            });
        }

        // Função para criar o gráfico de ordens de trabalho por prioridade
        function createWorkOrdersByPriorityChart(data) {
            const ctx = document.getElementById('workOrdersByPriorityChart').getContext('2d');
            const labels = data.map(item => item.priority);
            const values = data.map(item => item.total_work_orders);
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Ordens de Trabalho por Prioridade',
                        data: values,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.2)',
                            'rgba(255, 159, 64, 0.2)',
                            'rgba(255, 205, 86, 0.2)',
                            'rgba(75, 192, 192, 0.2)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(255, 205, 86, 1)',
                            'rgba(75, 192, 192, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false
                }
            });
        }

        // Criar gráficos ao carregar a página
        document.addEventListener('DOMContentLoaded', function () {
            createWorkOrdersByStatusChart(workOrdersByStatusData);
            createWorkOrdersByPriorityChart(workOrdersByPriorityData);
        });
    </script>
</body>
</html>
