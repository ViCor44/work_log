<?php
require_once '../header.php';

// --- Lógica de Filtros ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$chemical_filter = isset($_GET['chemical_id']) ? (int)$_GET['chemical_id'] : '';

// Busca todos os produtos para o dropdown do filtro
$all_chemicals_stmt = $conn->query("SELECT * FROM chemicals ORDER BY name ASC");
$all_chemicals = $all_chemicals_stmt->fetch_all(MYSQLI_ASSOC);

// --- Lógica de Cálculo de Stock e Consumo ---
$report_summary = [];

foreach ($all_chemicals as $chemical) {
    $chemical_id = $chemical['id'];
    
    // 1. Total Gasto no período
    $stmt_spent = $conn->prepare("SELECT SUM(package_volume) as total FROM chemical_logs WHERE chemical_id = ? AND DATE(log_datetime) BETWEEN ? AND ?");
    $stmt_spent->bind_param("iss", $chemical_id, $start_date, $end_date);
    $stmt_spent->execute();
    $total_spent = (float)$stmt_spent->get_result()->fetch_assoc()['total'];
    $stmt_spent->close();

    // 2. Total Comprado no período
    $stmt_purchased = $conn->prepare("SELECT SUM(quantity) as total FROM chemical_purchases WHERE chemical_id = ? AND purchase_date BETWEEN ? AND ?");
    $stmt_purchased->bind_param("iss", $chemical_id, $start_date, $end_date);
    $stmt_purchased->execute();
    $total_purchased = (float)$stmt_purchased->get_result()->fetch_assoc()['total'];
    $stmt_purchased->close();

    // 3. Calcula o Stock Inicial
    // Stock Inicial = (Stock Atual - Comprado no período) + Gasto no período
    $initial_stock = (float)$chemical['current_stock'] - $total_purchased + $total_spent;

    $report_summary[$chemical_id] = [
        'name' => $chemical['name'],
        'unit' => $chemical['unit'],
        'initial_stock' => $initial_stock,
        'purchased' => $total_purchased,
        'spent' => $total_spent,
        'final_stock' => $chemical['current_stock']
    ];
}


// --- Lógica para buscar o Histórico Detalhado ---
$sql_logs = "
    SELECT c.log_datetime, t.name as tank_name, u.first_name, u.last_name, chem.name as chemical_name, c.package_volume
    FROM chemical_logs c
    JOIN tanks t ON c.tank_id = t.id
    JOIN users u ON c.user_id = u.id
    JOIN chemicals chem ON c.chemical_id = chem.id
    WHERE DATE(c.log_datetime) BETWEEN ? AND ?
";
$params = [$start_date, $end_date];
$types = "ss";

if (!empty($chemical_filter)) {
    $sql_logs .= " AND c.chemical_id = ?";
    $params[] = $chemical_filter;
    $types .= "i";
}
$sql_logs .= " ORDER BY c.log_datetime DESC";

$stmt_logs = $conn->prepare($sql_logs);
if ($stmt_logs) {
    // Usa call_user_func_array para compatibilidade com versões antigas do PHP
    $bind_params = [];
    $bind_params[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_params[] = &$params[$i];
    }
    call_user_func_array(array($stmt_logs, 'bind_param'), $bind_params);
    
    $stmt_logs->execute();
    $logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_logs->close();
}

$sql_purchases = "
    SELECT p.purchase_date, u.first_name, u.last_name, c.name as chemical_name, p.quantity, c.unit, p.notes
    FROM chemical_purchases p
    JOIN users u ON p.user_id = u.id
    JOIN chemicals c ON p.chemical_id = c.id
    WHERE p.purchase_date BETWEEN ? AND ?
";
$purchase_params = [$start_date, $end_date];
$purchase_types = "ss";

if (!empty($chemical_filter)) {
    $sql_purchases .= " AND p.chemical_id = ?";
    $purchase_params[] = $chemical_filter;
    $purchase_types .= "i";
}
$sql_purchases .= " ORDER BY p.purchase_date DESC";

$stmt_purchases = $conn->prepare($sql_purchases);
if ($stmt_purchases) {
    $bind_params = [];
    $bind_params[] = $purchase_types;
    for ($i = 0; $i < count($purchase_params); $i++) {
        $bind_params[] = &$purchase_params[$i];
    }
    call_user_func_array(array($stmt_purchases, 'bind_param'), $bind_params);
    
    $stmt_purchases->execute();
    $purchases_log = $stmt_purchases->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_purchases->close();
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Relatório de Stock e Consumo</h1>
        <div><a href="registos.php" class="btn btn-secondary">Voltar à Gestão</a></div>
    </div>
	
	<div class="card shadow-sm mb-4">
	    <div class="card-body">
	        <form method="GET" action="" class="row g-3 align-items-end">
	            <div class="col-md-3">
	                <label for="start_date" class="form-label">Data Início</label>
	                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
	            </div>
	            <div class="col-md-3">
	                <label for="end_date" class="form-label">Data Fim</label>
	                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
	            </div>
	            <div class="col-md-4">
	                <label for="chemical_id" class="form-label">Filtrar por Produto</label>
	                <select name="chemical_id" class="form-select">
	                    <option value="">-- Todos os Produtos --</option>
	                    <?php foreach($all_chemicals as $chemical): ?>
	                    <option value="<?= $chemical['id'] ?>" <?= ($chemical_filter == $chemical['id']) ? 'selected' : '' ?>><?= htmlspecialchars($chemical['name']) ?></option>
	                    <?php endforeach; ?>
	                </select>
	            </div>
	            <div class="col-md-2 d-flex align-items-end">
	                <button type="submit" class="btn btn-primary me-2">Filtrar</button>
	                <a href="gerar_pdf_consumiveis.php?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>" target="_blank" class="btn btn-danger">
	                    <i class="fas fa-file-pdf"></i>
	                </a>
	            </div>
	        </form>
	    </div>
	</div>

    <div class="card shadow-sm mb-4">
        <div class="card-header"><h5 class="mb-0">Resumo de Stock para o Período</h5></div>
        <div class="card-body">
            <table class="table table-sm table-bordered text-center">
                <thead class="table-light">
                    <tr>
                        <th class="text-start">Produto</th>
                        <th>Stock Inicial</th>
                        <th>Total Comprado</th>
                        <th>Total Gasto</th>
                        <th>Stock Final</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($report_summary as $summary): ?>
                    <tr>
                        <td class="text-start fw-bold"><?= htmlspecialchars($summary['name']) ?></td>
                        <td><?= number_format($summary['initial_stock'], 0, ',', '.') ?></td>
                        <td class="text-success fw-bold">+ <?= number_format($summary['purchased'], 0, ',', '.') ?></td>
                        <td class="text-danger fw-bold">- <?= number_format($summary['spent'], 0, ',', '.') ?></td>
                        <td class="fw-bold"><?= number_format($summary['final_stock'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>



	<div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0">Histórico de Compras</h5></div>
        <div class="card-body table-responsive">
            <table class="table table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Data da Compra</th>
                        <th>Produto</th>
                        <th>Quantidade</th>
                        <th>Notas</th>
                        <th>Registado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($purchases_log)): ?>
                        <?php foreach($purchases_log as $log): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($log['purchase_date'])) ?></td>
                            <td><span class="badge bg-success"><?= htmlspecialchars($log['chemical_name']) ?></span></td>
                            <td><strong><?= number_format($log['quantity'], 0, ',', '.') ?></strong></td>
                            <td><?= htmlspecialchars($log['notes']) ?></td>
                            <td><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">Nenhum registo de compra encontrado para o período e filtros selecionados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><h5 class="mb-0">Histórico de Trocas</h5></div>
        <div class="card-body table-responsive">
            <table class="table table-hover table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Data e Hora</th>
                        <th>Piscina</th>
                        <th>Produto</th>
                        <th>Volume</th>
                        <th>Registado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($logs)): ?>
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($log['log_datetime'])) ?></td>
                            <td><?= htmlspecialchars($log['tank_name']) ?></td>
                            <td><span class="badge bg-info text-dark"><?= htmlspecialchars($log['chemical_name']) ?></span></td>
                            <td><?= htmlspecialchars($log['package_volume']) ?> </td>
                            <td><?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">Nenhum registo de consumo encontrado para o período e filtros selecionados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
	
</div>
<?php require_once '../footer.php'; ?>