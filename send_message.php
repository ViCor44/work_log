<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

include 'db.php';
$user_id = $_SESSION['user_id'];

$reply_to = isset($_GET['reply_to']) ? $_GET['reply_to'] : ''; // Preencher o destinatário ao responder

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Captura os dados do formulário
    $receiver_id = $_POST['receiver']; // Agora pega o ID do destinatário ou '@all'
    $message_text = $_POST['message'];

    if ($receiver_id === '@all') {
        // Enviar mensagem para todos os utilizadores
        $stmt = $conn->prepare("SELECT id FROM users WHERE id != ?");
        
        // Verificar se a preparação da consulta falhou
        if (!$stmt) {
            die("Erro na consulta SQL: " . $conn->error); // Debugging
        }

        $stmt->bind_param("i", $user_id); // Exclui o remetente da seleção
        $stmt->execute();
        $stmt->bind_result($receiver_id);

        // Armazena todos os IDs dos usuários em um array
        $receiver_ids = [];
        while ($stmt->fetch()) {
            $receiver_ids[] = $receiver_id;
        }

        $stmt->close(); // Fechar a consulta antes de começar a inserção

        // Começa uma transação para inserir múltiplas mensagens
        $conn->begin_transaction();
        try {
            foreach ($receiver_ids as $receiver_id) {
                $insert_stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, is_read) VALUES (?, ?, ?, 0)");
                
                // Verificar se a preparação da consulta falhou
                if (!$insert_stmt) {
                    die("Erro na consulta SQL de inserção: " . $conn->error); // Debugging
                }
                
                $insert_stmt->bind_param("iis", $user_id, $receiver_id, $message_text);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            $conn->commit();
            $message = "Mensagem enviada com sucesso para todos os utilizadores!";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Erro ao enviar mensagem para todos: " . $e->getMessage();
        }
    } else {
        // Inserir a mensagem na tabela para um único destinatário
        $insert_stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_text, is_read) VALUES (?, ?, ?, 0)");
        
        // Verificar se a preparação da consulta falhou
        if (!$insert_stmt) {
            die("Erro na consulta SQL de inserção: " . $conn->error); // Debugging
        }
        
        $insert_stmt->bind_param("iis", $user_id, $receiver_id, $message_text);

        if ($insert_stmt->execute()) {
            $message = "Mensagem enviada com sucesso!";
        } else {
            $message = "Erro ao enviar a mensagem: " . $insert_stmt->error;
        }
        $insert_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Mensagem</title>
    <link href="/work_log/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <h2>Enviar Mensagem</h2>

        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label for="receiver" class="form-label">Destinatário</label>
                <select class="form-select" id="receiver" name="receiver" required>
                    <option value="" disabled selected>Selecione um destinatário</option>
                    <option value="@all">Todos os utilizadores</option> <!-- Opção para enviar a todos -->
                    <?php
                    // Consulta para obter todos os usuários
                    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE accepted = 1");
                    
                    // Verificar se a consulta foi preparada com sucesso
                    if (!$stmt) {
                        die("Erro na consulta SQL: " . $conn->error); // Debugging
                    }
                    
                    $stmt->execute();
                    $stmt->bind_result($user_id, $first_name, $last_name);
                    
                    while ($stmt->fetch()) {
                        echo '<option value="' . $user_id . '">' . htmlspecialchars($first_name . ' ' . $last_name) . '</option>';
                    }
                    $stmt->close();
                    ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="message" class="form-label">Mensagem</label>
                <textarea class="form-control" id="message" name="message" rows="4" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Enviar</button>
            <a href="inbox.php" class="btn btn-primary me-md-2">Ver Mensagens</a>
        </form>
    </div>

    <script src="/work_log/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
