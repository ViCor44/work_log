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

// Contar ordens de trabalho atribuídas e ainda não aceitas
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
        body {
            background: url('images/c7a9801f-2e42-4a72-8918-8b8bebb0f903.webp') no-repeat center center fixed; /* Substitua pelo caminho da imagem */
            background-size: cover;
            color: #ffffff; /* Torna o texto mais visível sobre o fundo */
        }

        .card {
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            border-radius: 10px;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .card .badge {
            float: right;
            font-size: 0.85rem;
            padding: 0.4em 0.6em;
        }

        .container {
            margin-top: 50px;
        }

        h1 {
            font-weight: bold;
            color: #343a40;
        }        

        .btn-primary {
            background-color: #007bff;
            border: none;
        }

        .btn-secondary {
            background-color: #6c757d;
            border: none;
        }
        .card-top {
            margin-bottom: 20px; /* Adiciona espaço entre os cards superiores e os inferiores */
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-4">
    <h1 class="text-center mb-5">Bem-vindo ao WorkLog CMMS</h1>
    <div class="row justify-content-center">
        <!-- Card de Ativos -->
        <div class="col-md-4">
            <div class="card card-top text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-box"></i> Ativos
                    </h5>
                    <p class="card-text">Registe novos ativos para uma gestão eficaz.</p>
                    <a href="list_assets.php" class="btn btn-primary">Listar Ativos</a>
                    <a href="create_asset.php" class="btn btn-secondary">Novo Ativo</a>
                </div>
            </div>
        </div> 

        <!-- Card de Gerir Utilizadores -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-users"></i> Gerir Utilizadores
                    </h5>
                    <p class="card-text">Administre os utilizadores do sistema.</p>
                    <a href="manage_users.php" class="btn btn-primary">Gerir</a>
                </div>
            </div>
        </div>

        <!-- Card para Sistema de Mensagens -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-envelope"></i> Sistema de Mensagens 
                        <?php if ($unread_count > 0): ?>
                            <span class="badge bg-danger"><?= $unread_count; ?></span>
                        <?php endif; ?>
                    </h5>
                    <p class="card-text">Ver e enviar mensagens para outros utilizadores.</p>
                    <a href="inbox.php" class="btn btn-primary">Ver Mensagens</a>
                    <a href="send_message.php" class="btn btn-secondary">Nova Mensagem</a>
                </div>
            </div>
        </div>

        <!-- Card para Sistema de Relatórios -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-file-alt"></i> Sistema de Relatórios
                    </h5>
                    <p class="card-text">Ver e redigir relatórios.</p>
                    <a href="list_reports.php" class="btn btn-primary">Listar Relatórios</a>
                    <a href="create_report.php" class="btn btn-secondary">Novo Relatório</a>
                </div>
            </div>
        </div>

        <!-- Card para Estatísticas -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-chart-pie"></i> Estatísticas
                    </h5>
                    <p class="card-text">Ver estatísticas várias.</p>
                    <a href="statistics.php" class="btn btn-primary">Ver</a>
                </div>
            </div>
        </div>

        <!-- Card para Ordens de Trabalho -->
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-tasks"></i> Ordens de Trabalho 
                        <?php if ($unaccepted_ot_count > 0): ?>
                            <span class="badge bg-warning"><?= $unaccepted_ot_count; ?></span>
                        <?php endif; ?>
                    </h5>
                    <p class="card-text">Gerir as ordens de trabalho dos ativos.</p>
                    <a href="list_work_orders.php" class="btn btn-primary">Ver Ordens</a>
                    <a href="create_work_order.php" class="btn btn-secondary">Nova Ordem</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>
</html>

