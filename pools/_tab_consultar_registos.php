<?php
// Obter todos os tanques para o dropdown de filtro
if (!isset($tanks)) { // Evita buscar os dados duas vezes se já existirem
    $tanks_stmt_filter = $conn->query("SELECT id, name FROM tanks ORDER BY name ASC");
    $tanks_filter = $tanks_stmt_filter->fetch_all(MYSQLI_ASSOC);
} else {
    $tanks_filter = $tanks;
}

// Lógica de busca
$results = [];
$searched = false;
if (isset($_GET['tank_id_filter']) && !empty($_GET['tank_id_filter'])) {
    $searched = true;
    $tank_id = $_GET['tank_id_filter'];
    // Define datas padrão se não forem fornecidas
    $start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
    $end_date = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    // Buscar Análises
    $stmt = $conn->prepare("SELECT a.*, u.full_name FROM analyses a JOIN users u ON a.user_id = u.id WHERE a.tank_id = ? AND DATE(a.analysis_datetime) BETWEEN ? AND ? ORDER BY a.analysis_datetime DESC");
    $stmt->bind_param("iss", $tank_id, $start_date, $end_date);
    $stmt->execute();
    $results['analyses'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Buscar Leituras de Água
    $stmt = $conn->prepare("SELECT w.*, u.full_name FROM water_readings w JOIN users u ON w.user_id = u.id WHERE w.tank_id = ? AND DATE(w.reading_datetime) BETWEEN ? AND ? ORDER BY w.reading_datetime DESC");
    $stmt->bind_param("iss", $tank_id, $start_date, $end_date);
    $stmt->execute();
    $results['water'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Buscar Hipoclorito
    $stmt = $conn->prepare("SELECT h.*, u.full_name FROM hypochlorite_readings h JOIN users u ON h.user_id = u.id WHERE h.tank_id = ? AND DATE(h.reading_datetime) BETWEEN ? AND ? ORDER BY h.reading_datetime DESC");
    $stmt->bind_param("iss", $tank_id, $start_date, $end_date);
    $stmt->execute();
    $results['hypochlorite'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<div class="card shadow-sm border-top-0 rounded-0 rounded-bottom">
    <div class="card-body p-4">
        <form method="GET" action="registos.php">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label for="tank_id_filter" class="form-label">Tanque</label>
                    <select class="form-select" name="tank_id_filter" required>
                        <option value="">-- Selecione --</option>
                        <?php foreach ($tanks_filter as $tank): ?>
                            <option value="<?= $tank['id'] ?>" <?= (isset($_GET['tank_id_filter']) && $_GET['tank_id_filter'] == $tank['id']) ? 'selected' : '' ?>><?= htmlspecialchars($tank['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Data Início</label>
                    <input type="date" class="form-control" name="start_date" value="<?= isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days')) ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">Data Fim</label>
                    <input type="date" class="form-control" name="end_date" value="<?= isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Pesquisar</button>
                </div>
            </div>
            <input type="hidden" name="tab" value="consultar">
        </form>

        <hr class="my-4">

        <?php if ($searched): ?>
            <?php if (!empty($results['analyses'])): ?>
                <h4 class="mb-3">Histórico de Análises</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['water'])): ?>
                <h4 class="mb-3 mt-4">Histórico de Leituras de Água</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                       </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($results['hypochlorite'])): ?>
                <h4 class="mb-3 mt-4">Histórico de Consumo de Hipoclorito</h4>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        </table>
                </div>
            <?php endif; ?>

            <?php if (empty($results['analyses']) && empty($results['water']) && empty($results['hypochlorite'])): ?>
                <div class="alert alert-info">Nenhum registo encontrado para os filtros selecionados.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-secondary">Selecione um tanque e um período para ver o histórico de registos.</div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('tab') && urlParams.get('tab') === 'consultar') {
        const tab = new bootstrap.Tab(document.getElementById('consultar-registos-tab'));
        tab.show();
    }
});
</script>