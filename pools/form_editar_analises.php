<?php
require_once '../header.php';

// 1. Obter a data a partir da URL
$edit_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 2. Buscar os tanques que requerem análises
$tanks_stmt = $conn->query("SELECT id, name FROM tanks WHERE requires_analysis = 1 ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// 3. Buscar os DADOS EXISTENTES para a data a editar
$sql = "SELECT * FROM analyses WHERE DATE(analysis_datetime) = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $edit_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 4. Organizar os dados existentes numa matriz para preencher os campos
$existing_data = ['manha' => [], 'tarde' => []];
foreach ($results as $row) {
    $existing_data[$row['period']][$row['tank_id']] = $row;
}

// Função auxiliar para desenhar os cards e evitar repetição de código
function render_analysis_card($tank, $period, $data) {
    $ph = isset($data['ph_level']) ? $data['ph_level'] : '';
    $cl = isset($data['chlorine_level']) ? $data['chlorine_level'] : '';
    $temp = isset($data['temperature']) ? $data['temperature'] : '';
    $cond = isset($data['conductivity']) ? $data['conductivity'] : '';
    $solids = isset($data['dissolved_solids']) ? $data['dissolved_solids'] : '';
    $id = isset($data['id']) ? $data['id'] : '';

    echo '<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">';
    echo '    <div class="tank-card-form">';
    echo '        <h5>' . htmlspecialchars($tank['name']) . '</h5>';
    echo '        <hr class="text-white-50 mt-1 mb-3">';
    echo '        <input type="hidden" name="analysis_id['.$period.']['.$tank['id'].']" value="'.$id.'">';
    echo '        <div class="mb-2"><label class="form-label">pH</label><input type="number" step="0.01" class="form-control" name="ph_level['.$period.']['.$tank['id'].']" value="'.$ph.'"></div>';
    echo '        <div class="mb-2"><label class="form-label">Cloro (ppm)</label><input type="number" step="0.01" class="form-control" name="chlorine_level['.$period.']['.$tank['id'].']" value="'.$cl.'"></div>';
    echo '        <div class="mb-2"><label class="form-label">Temp. (°C)</label><input type="number" step="0.1" class="form-control" name="temperature['.$period.']['.$tank['id'].']" value="'.$temp.'"></div>';
    echo '        <div class="mb-2"><label class="form-label">Condutividade (mS/cm)</label><input type="number" step="0.01" class="form-control" name="conductivity['.$period.']['.$tank['id'].']" value="'.$cond.'"></div>';
    echo '        <div class="mb-2"><label class="form-label">Sólidos Dissolv. (mg/l)</label><input type="number" step="0.01" class="form-control" name="dissolved_solids['.$period.']['.$tank['id'].']" value="'.$solids.'"></div>';
    echo '    </div>';
    echo '</div>';
}
?>
<style>
    .tank-card-form { background-color: #ffc107; color: #343a40; border-radius: 8px; padding: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); height: 100%; display: flex; flex-direction: column; }
    .tank-card-form h5 { font-weight: bold; }
    .tank-card-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; font-weight: 500; }
    .tank-card-form .form-control { background-color: rgba(255,255,255,0.9); border: 1px solid #ccc; color: #333; }
    .form-actions { background-color: #f8f9fa; padding: 1rem; border-radius: 0.5rem; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 8px rgba(0,0,0,0.1); }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex align-items-center mb-4">
        <i class="fas fa-edit fa-3x text-warning me-3"></i>
        <h1 class="h3 mb-0">Editar Análises para o dia <?= date('d/m/Y', strtotime($edit_date)) ?></h1>
    </div>

    <form action="guardar_edicao_analises.php" method="POST">
        <input type="hidden" name="edit_date" value="<?= htmlspecialchars($edit_date) ?>">

        <h4 class="mb-3">Período da Manhã</h4>
        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <?php $data = isset($existing_data['manha'][$tank['id']]) ? $existing_data['manha'][$tank['id']] : null; ?>
                <?php render_analysis_card($tank, 'manha', $data); ?>
            <?php endforeach; ?>
        </div>
        
        <hr class="my-4">
        
        <h4 class="mb-3">Período da Tarde</h4>
        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <?php $data = isset($existing_data['tarde'][$tank['id']]) ? $existing_data['tarde'][$tank['id']] : null; ?>
                <?php render_analysis_card($tank, 'tarde', $data); ?>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="relatorio_analises.php?report_date=<?= htmlspecialchars($edit_date) ?>" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-success">Guardar Alterações</button>
        </div>
    </form>
</div>
<?php require_once '../footer.php'; ?>