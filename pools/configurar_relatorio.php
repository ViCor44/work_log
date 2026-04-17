<?php
require_once '../header.php';

// Busca todos os tanques
$tanks_stmt = $conn->query("SELECT id, name, type FROM tanks ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);

// Carrega configuração existente se houver
$config_path = __DIR__ . '/config_relatorio.json';
$config = [
    'sections' => [
        ['name' => 'Piscinas', 'tanks' => []],
        ['name' => 'Rejeitado', 'tanks' => []],
        ['name' => 'Consumo de Água da Rede', 'tanks' => []],
        ['name' => 'Rega', 'tanks' => []],
        ['name' => 'Reserva nos Tanques', 'tanks' => []],
    ]
];

if (file_exists($config_path)) {
    $json = file_get_contents($config_path);
    $decoded = json_decode($json, true);
    if (isset($decoded['sections'])) {
        $config = $decoded;
    } elseif (is_array($decoded)) {
        $config['sections'] = $decoded;
    }
}

if (!isset($config['sections']) || !is_array($config['sections'])) {
    $config['sections'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['sections'] = $_POST['sections'] ?? [];
    file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: configurar_relatorio.php?salvo=1');
    exit;
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Configurar Relatório de Água</h1>
        <a href="relatorio_semanal_agua.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar ao Relatório
        </a>
    </div>

    <?php if (isset($_GET['salvo'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> Configuração salva com sucesso!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="post" id="config-form">
        <div class="row" id="sections-container">
            <?php foreach ($config['sections'] as $secIdx => $section): ?>
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center">
                            <input type="text" 
                                   name="sections[<?= $secIdx ?>][name]" 
                                   class="form-control form-control-sm section-name" 
                                   value="<?= htmlspecialchars($section['name']) ?>" 
                                   placeholder="Nome da Secção">
                            <button type="button" class="btn btn-sm btn-danger ms-2" onclick="removeSection(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="tank-list">
                                <?php foreach ($tanks as $tank): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               name="sections[<?= $secIdx ?>][tanks][]" 
                                               value="<?= $tank['id'] ?>" 
                                               id="tank_<?= $secIdx ?>_<?= $tank['id'] ?>"
                                               <?= in_array($tank['id'], $section['tanks']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="tank_<?= $secIdx ?>_<?= $tank['id'] ?>">
                                            <strong><?= htmlspecialchars($tank['name']) ?></strong>
                                            <small class="text-muted">(<?= htmlspecialchars($tank['type']) ?>)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <button type="button" class="btn btn-info" onclick="addSection()">
                    <i class="fas fa-plus"></i> Adicionar Nova Secção
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Salvar Configuração
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    let nextSectionIndex = <?= count($config['sections']) ?>;

    function addSection() {
        const container = document.getElementById('sections-container');
        const idx = nextSectionIndex++;
        
        const html = `
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <input type="text" 
                               name="sections[${idx}][name]" 
                               class="form-control form-control-sm section-name" 
                               value="Nova Secção" 
                               placeholder="Nome da Secção">
                        <button type="button" class="btn btn-sm btn-danger ms-2" onclick="removeSection(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="tank-list">
                            ${getTankCheckboxes(idx)}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', html);
    }

    function getTankCheckboxes(idx) {
        const tanks = <?= json_encode($tanks) ?>;
        let html = '';
        tanks.forEach(tank => {
            html += `
                <div class="form-check">
                    <input class="form-check-input" 
                           type="checkbox" 
                           name="sections[${idx}][tanks][]" 
                           value="${tank.id}" 
                           id="tank_${idx}_${tank.id}">
                    <label class="form-check-label" for="tank_${idx}_${tank.id}">
                        <strong>${tank.name}</strong>
                        <small class="text-muted">(${tank.type})</small>
                    </label>
                </div>
            `;
        });
        return html;
    }

    function removeSection(btn) {
        const card = btn.closest('.col-md-6');
        card.remove();
    }
</script>
