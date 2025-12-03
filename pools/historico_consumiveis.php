<?php
require_once '../header.php';

// --- Lógica de busca e filtros ---

// 1. Buscar todas as piscinas para preencher o menu dropdown
$pools_stmt = $conn->query("SELECT id, name FROM tanks WHERE type = 'piscina' ORDER BY name ASC");
$pools = $pools_stmt->fetch_all(MYSQLI_ASSOC);

// 2. Verificar se uma piscina foi selecionada
$selected_pool_id = isset($_GET['tank_id']) ? (int)$_GET['tank_id'] : null;
$logs = [];
$pool_name = '';

if ($selected_pool_id) {
    // Busca o nome da piscina selecionada para o título
    foreach ($pools as $pool) {
        if ($pool['id'] == $selected_pool_id) {
            $pool_name = $pool['name'];
            break;
        }
    }

    // 3. Busca o histórico de trocas para a piscina selecionada
    $stmt = $conn->prepare("
        SELECT 
            cl.log_datetime, 
            CONCAT(u.first_name, ' ', u.last_name) as user_name, 
            c.name as chemical_name, 
            c.package_volume,
            c.unit
        FROM chemical_logs cl
        JOIN users u ON cl.user_id = u.id
        JOIN chemicals c ON cl.chemical_id = c.id
        WHERE cl.tank_id = ?
        ORDER BY cl.log_datetime DESC
    ");
    $stmt->bind_param("i", $selected_pool_id);
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<style>
    /* Estilos para a Timeline (idênticos aos da view_work_order.php) */
    .timeline { list-style: none; padding: 0; position: relative; }
    .timeline:before {
        content: ''; position: absolute; top: 0; bottom: 0;
        left: 20px; width: 2px; background-color: #e9ecef;
    }
    .timeline-item { display: flex; align-items: flex-start; margin-bottom: 1.5rem; position: relative; }
    .timeline-icon {
        flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        color: #fff; background-color: #0d6efd; z-index: 1;
    }
    .timeline-content { margin-left: 20px; padding-top: 5px; }
    .timeline-content strong { display: block; font-weight: 600; }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Histórico de Troca de Consumíveis</h1>
        <a href="registos.php" class="btn btn-secondary">Voltar ao Menu</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label for="tank_id" class="form-label">Selecione uma piscina para ver o seu histórico:</label>
                    <select id="tank_id" name="tank_id" class="form-select" required>
                        <option value="">-- Escolha uma piscina --</option>
                        <?php foreach ($pools as $pool): ?>
                            <option value="<?= $pool['id'] ?>" <?= ($selected_pool_id == $pool['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pool['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Ver Histórico</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_pool_id): ?>
    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Histórico para: <?= htmlspecialchars($pool_name) ?></h5>
        </div>
        <div class="card-body">
            <?php if (count($logs) > 0): ?>
                <ul class="timeline">
                    <?php foreach ($logs as $log): ?>
                    <li class="timeline-item">
                        <div class="timeline-icon"><i class="fas fa-prescription-bottle"></i></div>
                        <div class="timeline-content">
                            <strong>Troca de <?= htmlspecialchars($log['chemical_name']) ?></strong>
                            <p class="text-muted mb-0">
                                Uma embalagem de <strong><?= number_format($log['package_volume'], 2, ',', '') ?> <?= htmlspecialchars($log['unit']) ?></strong> foi registada.
                            </p>
                            <small>Por: <strong><?= htmlspecialchars($log['user_name']) ?></strong> em <?= date('d/m/Y', strtotime($log['log_datetime'])) ?> às <?= date('H:i', strtotime($log['log_datetime'])) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-center text-muted">Nenhum registo de troca de consumíveis encontrado para esta piscina.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once '../footer.php';
?>