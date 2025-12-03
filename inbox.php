<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Recupera o nome do utilizador logado
include 'db.php';
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
if (!$stmt) {
    die("Erro na consulta: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($user_name);
$stmt->fetch();
$stmt->close();

// Parâmetros de pesquisa
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Buscar mensagens recebidas pelo usuário logado
$received_query = "
    SELECT messages.id, users.username AS sender, messages.message_text, messages.timestamp, messages.is_read 
    FROM messages 
    JOIN users ON messages.sender_id = users.id 
    WHERE receiver_id = ?
";
$received_params = [$user_id];
$received_types = 'i';

if (!empty($search)) {
    $received_query .= " AND (users.username LIKE ? OR messages.message_text LIKE ?)";
    $received_params[] = '%' . $search . '%';
    $received_params[] = '%' . $search . '%';
    $received_types .= 'ss';
}

$received_query .= " ORDER BY messages.timestamp DESC";
$stmt = $conn->prepare($received_query);

if ($stmt === false) {
    die("Erro na preparação da consulta: " . $conn->error);
}

$stmt->bind_param($received_types, ...$received_params);
$stmt->execute();
$received_messages = $stmt->get_result();

// Buscar mensagens enviadas pelo usuário logado
$sent_query = "
    SELECT messages.id, users.username AS receiver, messages.message_text, messages.timestamp, messages.is_read 
    FROM messages 
    JOIN users ON messages.receiver_id = users.id 
    WHERE sender_id = ?
";
$sent_params = [$user_id];
$sent_types = 'i';

if (!empty($search)) {
    $sent_query .= " AND (users.username LIKE ? OR messages.message_text LIKE ?)";
    $sent_params[] = '%' . $search . '%';
    $sent_params[] = '%' . $search . '%';
    $sent_types .= 'ss';
}

$sent_query .= " ORDER BY messages.timestamp DESC";
$stmt = $conn->prepare($sent_query);

if ($stmt === false) {
    die("Erro na preparação da consulta: " . $conn->error);
}

$stmt->bind_param($sent_types, ...$sent_params);
$stmt->execute();
$sent_messages = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caixa de Entrada</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .message-card {
            margin-bottom: 1rem;
        }
        .empty-message {
            text-align: center;
            margin-top: 2rem;
        }
        .badge-status {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <h2 class="mb-4">📬 Caixa de Entrada</h2>

        <!-- Botões -->
        <div class="d-flex mb-4">
            <a href="send_message.php" class="btn btn-primary me-2">✉️ Nova Mensagem</a>
            <a href="redirect_page.php" class="btn btn-secondary">⬅️ Voltar</a>
        </div>

        <!-- Pesquisa -->
        <form method="GET" action="inbox.php" class="mb-4">
            <div class="row g-2">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control" placeholder="Pesquisar por usuário ou conteúdo" value="<?= htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">🔍 Pesquisar</button>
                    <a href="inbox.php" class="btn btn-secondary">❌ Limpar</a>
                </div>
            </div>
        </form>

        <!-- Mensagens Recebidas -->
        <h3 class="mb-3">📥 Mensagens Recebidas</h3>
        <?php if ($received_messages->num_rows > 0): ?>
            <?php while ($row = $received_messages->fetch_assoc()): ?>
                <div class="card message-card <?= $row['is_read'] ? 'border-light' : 'border-warning' ?>">
                    <div class="card-body">
                        <h5 class="card-title">
                            <span class="text-primary">De: <?= htmlspecialchars($row['sender']); ?></span>
                            <span class="badge <?= $row['is_read'] ? 'bg-success' : 'bg-warning text-dark' ?> badge-status">
                                <?= $row['is_read'] ? 'Lida' : 'Não Lida' ?>
                            </span>
                        </h5>
                        <p class="card-text"><?= htmlspecialchars($row['message_text']); ?></p>
                        <p class="text-muted small mb-2">Enviado em: <?= $row['timestamp']; ?></p>
                        <div class="d-flex justify-content-start">
                            <?php if (!$row['is_read']): ?>
                                <a href="mark_as_read.php?id=<?= $row['id']; ?>" class="btn btn-success btn-sm me-2">✔️ Marcar como Lida</a>
                            <?php endif; ?>
                            <a href="send_message.php?reply_to=<?= $row['sender']; ?>" class="btn btn-primary btn-sm me-2">🔁 Responder</a>
                            <!-- Botão para abrir o modal -->
                            <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#messageModal<?= $row['id']; ?>">🔍 Detalhes</button>
                        </div>
                    </div>
                </div>

                <!-- Modal para cada mensagem -->
                <div class="modal fade" id="messageModal<?= $row['id']; ?>" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="messageModalLabel">Detalhes da Mensagem</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                            </div>
                            <div class="modal-body">
                                <h6>De: <?= htmlspecialchars($row['sender']); ?></h6>
                                <p><?= htmlspecialchars($row['message_text']); ?></p>
                                <p class="text-muted small">Enviado em: <?= $row['timestamp']; ?></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                <?php if (!$row['is_read']): ?>
                                    <a href="mark_as_read.php?id=<?= $row['id']; ?>" class="btn btn-success">Marcar como Lida</a>
                                <?php endif; ?>
                                <a href="send_message.php?reply_to=<?= $row['sender']; ?>" class="btn btn-primary">Responder</a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-message">
                <h4>📭 Sem mensagens recebidas</h4>
                <p class="text-muted">Não há mensagens na sua caixa de entrada.</p>
            </div>
        <?php endif; ?>

        <!-- Mensagens Enviadas -->
        <h3 class="mt-5 mb-3">📤 Mensagens Enviadas</h3>
        <?php if ($sent_messages->num_rows > 0): ?>
            <?php while ($row = $sent_messages->fetch_assoc()): ?>
                <div class="card message-card border-light">
                    <div class="card-body">
                        <h5 class="card-title">
                            <span class="text-primary">Para: <?= htmlspecialchars($row['receiver']); ?></span>
                            <span class="badge <?= !$row['is_read'] ? 'bg-warning text-dark' : 'bg-success' ?> badge-status">
                                <?= !$row['is_read'] ? 'Não Lida pelo destinatário' : 'Lida pelo destinatário' ?>
                            </span>
                        </h5>
                        <p class="card-text"><?= htmlspecialchars($row['message_text']); ?></p>
                        <p class="text-muted small">Enviado em: <?= $row['timestamp']; ?></p>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-message">
                <h4>📭 Sem mensagens enviadas</h4>
                <p class="text-muted">Você ainda não enviou mensagens.</p>
            </div>
        <?php endif; ?>
    </div>

    <script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>


<?php
$conn->close();
?>
