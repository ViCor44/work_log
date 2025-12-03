<?php
include 'db.php';
include 'core.php';

date_default_timezone_set('Europe/Lisbon');

// Verifica se o ID da ordem de trabalho foi passado na URL
if (isset($_GET['id'])) {
    $work_order_id = $_GET['id'];

    // ALTERAÇÃO 1: Adicionar um LEFT JOIN para a tabela de utilizadores com o alias 'creator'
    // e selecionar o nome do criador.
    $stmt = $conn->prepare("
        SELECT 
            w.id, a.name AS asset_name, w.description, w.status, w.priority, 
            u.first_name AS assigned_user_firstname, u.last_name AS assigned_user_lastname, 
            w.created_at, w.accept_by, w.accept_at, w.closed_at, 
            acceptor.first_name AS acceptor_name, 
            w.due_date, w.assigned_user,
            creator.first_name AS creator_name, creator.last_name AS creator_lastname
        FROM work_orders w 
        JOIN assets a ON w.asset_id = a.id 
        JOIN users u ON w.assigned_user = u.id
        LEFT JOIN users acceptor ON w.accept_by = acceptor.id
        LEFT JOIN users creator ON w.created_by_user_id = creator.id
        WHERE w.id = ?
    ");
    
    $stmt->bind_param("i", $work_order_id);
    $stmt->execute();
    
    // ALTERAÇÃO 2: Mudar para get_result() que é mais flexível
    $result = $stmt->get_result();
    $wo_data = $result->fetch_assoc();
    
    if ($wo_data) {
        // Extrai todos os dados para variáveis com o nome das colunas (ex: $id, $asset_name, etc.)
        extract($wo_data);
        // Junta o primeiro e último nome para a variável que o seu HTML já usa
        $assigned_user = $assigned_user_firstname . ' ' . $assigned_user_lastname;

        if (isset($created_at) && isset($closed_at)) {
            $create_date = new DateTime($created_at);
            $close_date = new DateTime($closed_at);
            $interval = $create_date->diff($close_date);
            $elapsed_time = '';
            if ($interval->d > 0) $elapsed_time .= $interval->d . 'd ';
            if ($interval->h > 0) $elapsed_time .= $interval->h . 'h ';
            if ($interval->i > 0) $elapsed_time .= $interval->i . 'm';
            $elapsed_time = trim($elapsed_time);
            if (empty($elapsed_time)) $elapsed_time = 'Menos de 1 minuto';
        } else {
            $elapsed_time = 'N/A';
        }
    } else {
        die("Ordem de trabalho não encontrada.");
    }

    $stmt->close();
} else {
    die("ID da ordem de trabalho não fornecido.");
}

// --- CONSULTAR AS FOTOS DA ORDEM DE TRABALHO ---
$photos = [];
$photo_stmt = $conn->prepare("SELECT photo_path FROM work_order_photos WHERE work_order_id = ?");
$photo_stmt->bind_param("i", $work_order_id);
$photo_stmt->execute();
$result = $photo_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $photos[] = $row['photo_path'];
}
$photo_stmt->close();

// Consultar usuários para o dropdown
$users = [];
$user_stmt = $conn->prepare("SELECT id, first_name, last_name FROM users");
$user_stmt->execute();
$user_result = $user_stmt->get_result();
while($user_row = $user_result->fetch_assoc()){
    $users[] = $user_row;
}
$user_stmt->close();

// --- Lógica para os badges de status e prioridade ---
$priority_map = [
    'Crítica' => 'bg-danger',
    'Alta' => 'bg-warning text-dark',
    'Média' => 'bg-info text-dark',
    'Baixa' => 'bg-secondary',
];
$status_map = [
    'Pendente' => 'bg-primary',
    'Aceite' => 'bg-info text-dark',
    'Em Andamento' => 'bg-success',
    'Fechada' => 'bg-dark',
];
$priority_class = isset($priority_map[$priority]) ? $priority_map[$priority] : 'bg-light text-dark';
$status_class = isset($status_map[$status]) ? $status_map[$status] : 'bg-light text-dark';

// ====================================================================
// == LÓGICA DE DATA DE VENCIMENTO ATUALIZADA ==
// ====================================================================
$due_date_card_class = '';
$due_date_text = !empty($due_date) ? date("d/m/Y", strtotime($due_date)) : 'N/A';
$due_date_text_class = '';
$due_date_status_text = 'N/A';
$due_date_status_class = 'bg-secondary';

if (!empty($due_date)) {
    $due_date_obj = new DateTime($due_date);
    $current_date = new DateTime();
    $closed_date_obj = !empty($closed_at) ? new DateTime($closed_at) : null;

    if ($status === 'Fechada' && $closed_date_obj) {
        if ($closed_date_obj > $due_date_obj) {
            // Cenário 1: Fechada com Atraso
            $interval = $due_date_obj->diff($closed_date_obj);
            $due_date_status_text = 'Fechada com Atraso';
            $due_date_text = 'Atraso de ' . $interval->format('%a dias');
            $due_date_card_class = 'border-danger';
            $due_date_status_class = 'bg-danger';
            $due_date_text_class = 'text-danger';
        } else {
            // Cenário 2: Fechada Dentro do Prazo
            $interval = $closed_date_obj->diff($due_date_obj);
            $due_date_status_text = 'Dentro do Prazo';
            $due_date_text = 'Antecedência de ' . $interval->format('%a dias');
            $due_date_card_class = 'border-success';
            $due_date_status_class = 'bg-success';
            $due_date_text_class = 'text-success';
        }
    } else { // Ordem de Trabalho Aberta
        if ($due_date_obj < $current_date) {
            // Cenário 3: Vencida e Aberta
            $interval = $due_date_obj->diff($current_date);
            $due_date_status_text = 'Vencida';
            $due_date_text = 'Atrasada há ' . $interval->format('%a dias');
            $due_date_card_class = 'border-danger';
            $due_date_status_class = 'bg-danger';
            $due_date_text_class = 'text-danger';
        } else {
            // Cenário 4: Por Vencer
            $interval = $current_date->diff($due_date_obj);
            $diff_days = $interval->days;
            $due_date_text = 'Faltam ' . $interval->format('%a dias');
            
            if ($diff_days <= 3) {
                $due_date_status_text = 'Urgente';
                $due_date_card_class = 'border-warning';
                $due_date_status_class = 'bg-warning text-dark';
                $due_date_text_class = 'text-warning';
            } else {
                $due_date_status_text = 'Dentro do Prazo';
                $due_date_card_class = 'border-success';
                $due_date_status_class = 'bg-success';
                $due_date_text_class = 'text-success';
            }
        }
    }
}
// ====================================================================
// == FIM DA LÓGICA DE DATA ==
// ====================================================================

// --- Lógica para desativar botões ---
// A variável do ID do utilizador atribuído é a $assigned_user que vem da base de dados
$disable_accept = ($_SESSION['user_id'] != $assigned_user || !empty($accept_by)) ? 'disabled' : '';

// Lógica para o botão fechar/reabrir
$disable_close = ''; 
if ($status === 'Fechada') {
    if ($_SESSION['user_id'] != $accept_by) {
        $disable_close = 'disabled';
    }
} else {
    if (empty($accept_by) || $_SESSION['user_id'] != $accept_by) {
        $disable_close = 'disabled';
    }
}

$stmt_history = $conn->prepare("
    SELECT h.action, h.description, h.event_datetime, u.first_name 
    FROM work_order_history h 
    LEFT JOIN users u ON h.user_id = u.id 
    WHERE h.work_order_id = ? 
    ORDER BY h.event_datetime ASC
");
$stmt_history->bind_param("i", $work_order_id);
$stmt_history->execute();
$history_events = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_history->close();

?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Ordem de Trabalho #<?= htmlspecialchars($id); ?></title>
    <link href="/wor_log/css/bootstrap.min.css" rel="stylesheet">
    <link href="/work_log/css/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .timeline {
            list-style: none;
            padding: 0;
            position: relative;
        }
        .timeline:before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 20px;
            width: 2px;
            background-color: #e9ecef;
        }
        .timeline-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .timeline-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background-color: #adb5bd;
            z-index: 1;
        }
        .timeline-content {
            margin-left: 20px;
            padding-top: 5px;
        }
        .timeline-content strong {
            display: block;
            font-weight: 600;
        }
        .photo-thumbnail {
            cursor: pointer;
            transition: transform 0.2s;
        }
        .photo-thumbnail:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container mt-5 mb-5">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="list_work_orders.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
        </div>
        <div class="text-end">
            <h1 class="mb-0">Ordem de Trabalho #<?= htmlspecialchars($id); ?></h1>
            <p class="text-muted mb-0">Ativo: <?= htmlspecialchars($asset_name); ?></p>
        </div>
    </div>
    
    <div class="p-3 mb-4 bg-light border rounded-3 text-center">
        <span class="badge fs-6 me-2 <?= $status_class; ?>"><i class="bi bi-info-circle-fill"></i> Status: <?= htmlspecialchars($status); ?></span>
        <span class="badge fs-6 <?= $priority_class; ?>"><i class="bi bi-exclamation-triangle-fill"></i> Prioridade: <?= htmlspecialchars($priority); ?></span>
    </div>

    <div class="row g-4">
        
        <div class="col-lg-8">
            
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0"><i class="bi bi-file-earmark-text me-2"></i>Descrição do Problema</h5>
                </div>
                <div class="card-body">
                    <p class="card-text"><?= nl2br(htmlspecialchars($description)); ?></p>
                </div>
            </div>
            
            <?php if (!empty($photos)): ?>
            <div class="card mb-4">
                 <div class="card-header bg-white">
                    <h5 class="card-title mb-0"><i class="bi bi-images me-2"></i>Fotos Anexadas</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($photos as $photo_path): ?>
                            <div class="col-lg-3 col-md-4 col-6 mb-3">
                                <img src="<?= htmlspecialchars($photo_path); ?>" class="img-fluid img-thumbnail photo-thumbnail" alt="Foto da Ordem de Trabalho" style="height: 150px; width: 100%; object-fit: cover;" data-bs-toggle="modal" data-bs-target="#photoModal" data-photo-src="<?= htmlspecialchars($photo_path); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Histórico da Ordem</h5>
                </div>
                <div class="card-body">
                    <ul class="timeline">
                        <li class="timeline-item">
                            <div class="timeline-icon bg-primary"><i class="bi bi-plus-lg"></i></div>
                            <div class="timeline-content">
                                <strong>Criada por <?= htmlspecialchars($creator_name . ' ' . $creator_lastname); ?></strong>
                        		<span><?= date("d/m/Y H:i", strtotime($created_at)); ?></span>
                            </div>
                        </li>
                        
                        <?php foreach ($history_events as $event): ?>
                        <li class="timeline-item">
                            <div class="timeline-icon bg-secondary"><i class="bi bi-info-circle"></i></div>
                            <div class="timeline-content">
                                <strong><?= htmlspecialchars(ucfirst(strtolower($event['action']))) ?> por <?= htmlspecialchars($event['first_name']) ?></strong>
                                <?php if (!empty($event['description'])): ?>
                                    <p class="text-muted mb-0"><?= htmlspecialchars($event['description']) ?></p>
                                <?php endif; ?>
                                <span><?= date("d/m/Y H:i", strtotime($event['event_datetime'])); ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>

                         <?php if($status === 'Fechada' && !empty($closed_at)): ?>
                        <li class="timeline-item">
                            <div class="timeline-icon bg-dark"><i class="bi bi-check2-circle"></i></div>
                            <div class="timeline-content">
                                <strong>Fechada</strong>
                                <span><?= date("d/m/Y H:i", strtotime($closed_at)); ?></span>
                                <small class="d-block text-muted">Tempo total: <?= htmlspecialchars($elapsed_time); ?></small>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="bi bi-gear-fill me-2"></i>Ações</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="update_work_order.php">
                        <input type="hidden" name="work_order_id" value="<?= $id; ?>">
                        
                        <div class="d-grid gap-2">
                             <button type="submit" name="accept" value="accept" class="btn btn-success" <?= $disable_accept; ?>><i class="bi bi-check-lg"></i> Aceitar OT</button>
                             <button type="submit" name="close" value="close" class="btn btn-dark" <?= $disable_close; ?>>
                                 <i class="bi bi-<?= $status === 'Fechada' ? 'unlock' : 'lock'; ?>"></i> <?= $status === 'Fechada' ? 'Reabrir OT' : 'Fechar OT'; ?>
                             </button>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label for="assign_user" class="form-label">Passar para:</label>
                            <select id="assign_user" name="assign_user" class="form-select">
                                <option value="">Selecionar utilizador...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id']; ?>"><?= htmlspecialchars($user['first_name'] . " " . $user['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" <?= $status === 'Fechada' ? 'disabled' : ''; ?>><i class="bi bi-person-up"></i> Reatribuir</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card <?= $due_date_card_class; ?>">
             <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="bi bi-person-lines-fill me-2"></i>Detalhes</h5>
                <?php if (!empty($due_date)): ?>
                    <span class="badge <?= $due_date_status_class; ?>">
                        <?= $due_date_status_text; ?>
                    </span>
                <?php endif; ?>
             </div>
             <ul class="list-group list-group-flush">
                 <li class="list-group-item d-flex justify-content-between align-items-center">
                     Atribuído a
                     <strong><?= htmlspecialchars($assigned_user); ?></strong>
                 </li>
                 <li class="list-group-item">
                     <div class="d-flex justify-content-between align-items-center">
                        <span>Data de Vencimento Previsto</span>
                        <strong><?= !empty($due_date) ? date("d/m/Y", strtotime($due_date)) : 'N/A'; ?></strong>
                     </div>
                     <?php if (!empty($due_date)): ?>
                        <div class="text-end mt-1">
                            <small class="fw-bold <?= $due_date_text_class; ?>"><?= htmlspecialchars($due_date_text); ?></small>
                        </div>
                     <?php endif; ?>
                 </li>
             </ul>
        </div>
        
    </div>
</div>

<div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
    </div>


<script src="/work_log/js/bootstrap.bundle.min.js"></script>
<script>
// JavaScript para o Modal de Fotos
document.addEventListener('DOMContentLoaded', function () {
    var photoModal = document.getElementById('photoModal');
    photoModal.addEventListener('show.bs.modal', function (event) {
        // Botão que acionou o modal
        var thumbnail = event.relatedTarget;
        // Extrai o caminho da imagem do atributo data-photo-src
        var photoSrc = thumbnail.getAttribute('data-photo-src');
        // Atualiza o src da imagem no modal
        var modalImage = photoModal.querySelector('#modalImage');
        modalImage.src = photoSrc;
    });
});
</script>
</body>
</html>