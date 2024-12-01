<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
date_default_timezone_set('Europe/Lisbon');
include 'db.php';

// Verifica se o ID da ordem de trabalho foi passado na URL
if (isset($_GET['id'])) {
    $work_order_id = $_GET['id'];

    // Prepara e executa a consulta para obter os detalhes da ordem de trabalho
    $stmt = $conn->prepare("
        SELECT w.id, a.name AS asset_name, w.description, w.status, w.priority, 
               u.first_name AS assigned_user, w.created_at, w.accept_by, w.accept_at, 
               w.closed_at, acceptor.first_name AS acceptor_name, w.due_date
        FROM work_orders w 
        JOIN assets a ON w.asset_id = a.id 
        JOIN users u ON w.assigned_user = u.id
        LEFT JOIN users acceptor ON w.accept_by = acceptor.id
        WHERE w.id = ?
    ");
    
    $stmt->bind_param("i", $work_order_id);
    $stmt->execute();
    $stmt->bind_result($id, $asset_name, $description, $status, $priority, $assigned_user, $created_at, $accept_by, $accept_at, $closed_at, $acceptor_name, $due_date);
    
    if ($stmt->fetch()) {
        if (isset($created_at) && isset($closed_at)) {
            $create_date = new DateTime($created_at);
            $close_date = new DateTime($closed_at);
            $interval = $create_date->diff($close_date);
            $elapsed_hours = ($interval->days * 24) + $interval->h + ($interval->i / 60); 
            $elapsed_time = number_format($elapsed_hours) . ' horas';
        } else {
            $elapsed_time = 'Dados de aceitação ou fecho não disponíveis.';
        }
    } else {
        die("Ordem de trabalho não encontrada.");
    }

    $stmt->close();
} else {
    die("ID da ordem de trabalho não fornecido.");
}

// Consultar usuários para o dropdown
$users = [];
$user_stmt = $conn->prepare("SELECT id, first_name FROM users");
$user_stmt->execute();
$user_stmt->bind_result($user_id, $user_name);
while ($user_stmt->fetch()) {
    $users[] = ['id' => $user_id, 'name' => $user_name];
}

// Lógica para definir a classe CSS do card "Data de Vencimento"
$due_date_color_class = 'bg-success text-white'; // Default: No prazo

if ($status !== 'Fechada' && !empty($due_date)) {
    $due_date_obj = new DateTime($due_date);
    $current_date = new DateTime();

    if ($current_date->diff($due_date_obj)->days <= 1) {
        $due_date_color_class = 'bg-danger text-white'; // Vencida

    } elseif ($current_date->diff($due_date_obj)->days <= 3) {
        $due_date_color_class = 'bg-warning text-dark'; // Próxima de vencer

    } elseif ($current_date->diff($due_date_obj)->days <= 5) {
        $due_date_color_class = 'bg-success text-dark'; // Próxima de vencer

    } elseif ($status === 'Fechada') {
        $due_date_color_class = 'bg-secondary text-white'; // Fechada
    }
}
$user_stmt->close();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Ordem de Trabalho</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .priority-crítica { background-color: #f8d7da; color: #842029; }
        .priority-alta { background-color: #fff3cd; color: #856404; }
        .priority-média { background-color: #cff4fc; color: #055160; }
        .priority-baixa { background-color: #d4edda; color: #155724; }
        .status-closed { background-color: #d6d8db; color: #495057; }
        .bg-danger {
            background-color: #f8d7da !important;
            color: #842029 !important;
        }

        .bg-warning {
            background-color: #fff3cd !important;
            color: #856404 !important;
        }

        .bg-success {
            background-color: #d4edda !important;
            color: #155724 !important;
        }

        .bg-secondary {
            background-color: #d6d8db !important;
            color: #495057 !important;
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5">
    <a href="list_work_orders.php" class="btn btn-primary mb-4">
        <i class="bi bi-arrow-left"></i> Voltar à Lista de Ordens de Trabalho
    </a>
    
    <h2 class="mb-4 text-center">Detalhes da Ordem de Trabalho</h2>

    <!-- Dados principais da OT -->
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-hash"></i> ID</h5>
                    <p class="card-text fw-bold"><?= htmlspecialchars($id); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-tools"></i> Ativo</h5>
                    <p class="card-text"><?= htmlspecialchars($asset_name); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card <?= 'priority-' . strtolower($priority); ?>">
                <div class="card-body text-center">
                    <h5 class="card-title"><i class="bi bi-exclamation-triangle"></i> Prioridade</h5>
                    <p class="card-text fw-bold"><?= htmlspecialchars($priority); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Detalhes adicionais -->
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card <?= $status === 'Fechada' ? 'status-closed' : ''; ?>">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-info-circle"></i> Status</h5>
                    <p class="card-text fw-bold">
                        <?= htmlspecialchars($status); ?>
                        <?php if ($status === 'Fechada' && !empty($closed_at)): ?>
                            <br><small>Fechada em: <?= htmlspecialchars($closed_at); ?></small>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-person-circle"></i> Atribuído a</h5>
                    <p class="card-text"><?= htmlspecialchars($assigned_user); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-calendar"></i> Data de Criação</h5>
                    <p class="card-text"><?= htmlspecialchars($created_at); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Novo card: Data de Vencimento -->
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card <?= $due_date_color_class; ?>">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-calendar-check"></i> Data de Vencimento</h5>
                    <p class="card-text"><?= htmlspecialchars($due_date); ?></p>
                </div>
            </div>
        </div>
   

    <!-- Datas importantes -->
    
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-person-check"></i> Aceite por</h5>
                    <p class="card-text"><?= !empty($acceptor_name) ? htmlspecialchars($acceptor_name) : 'N/A'; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-clock"></i> Data de Aceitação</h5>
                    <p class="card-text"><?= htmlspecialchars($accept_at); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-clock-history"></i> Tempo Decorrido</h5>
                    <p class="card-text"><?= htmlspecialchars($elapsed_time); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Descrição e ações -->
    <div class="row">
        <div class="col-md-8 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-file-earmark-text"></i> Descrição</h5>
                    <p class="card-text"><?= nl2br(htmlspecialchars($description)); ?></p>
                </div>
            </div>
        </div>
    

    <!-- Ações -->
    <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title"><i class="bi bi-gear"></i> Ações</h5>
                    <form method="POST" action="update_work_order.php">
                        <input type="hidden" name="work_order_id" value="<?= $id; ?>">

                        <div class="mb-3">
                            <label for="assign_user" class="form-label">Passar a:</label>
                            <select id="assign_user" name="assign_user" class="form-select">
                                <option value="">Selecionar utilizador</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id']; ?>"><?= htmlspecialchars($user['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Alterar status:</label>
                            <select id="status" name="status" class="form-select">
                                <option value="">Selecionar status</option>
                                <option value="Pendente">Pendente</option>
                                <option value="Aceite">Aceite</option>
                                <option value="Em Andamento">Em Andamento</option>
                                <option value="Fechada">Fechada</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary" <?= $status === 'Fechada' ? 'disabled' : ''; ?>>Salvar Ações</button>
                            <button type="submit" name="accept" value="accept" class="btn btn-success" <?= ($status !== 'Aceite') ? 'disabled' : ''; ?>>Aceitar OT</button>
                            <button type="submit" name="close" value="close" class="btn btn-danger"
                                <?= ($status === 'Fechada' || ($_SESSION['user_id'] != $accept_by)) ? 'disabled' : ''; ?>>
                                <?= $status === 'Fechada' ? 'Reabrir OT' : 'Fechar OT'; ?>
                            </button>
                        </div>                
                    </form>
                </div> 
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
