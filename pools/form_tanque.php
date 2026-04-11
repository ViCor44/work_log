<?php
// 1. INCLUIR O CABEÇALHO (que trata de tudo)
require_once '../header.php'; 
// A partir daqui, o utilizador já está autenticado e a BD conectada.

// 2. LÓGICA ESPECÍFICA DA PÁGINA
$is_editing = false;
$tank_id = null;
$tank = [
    'name' => '',
    'type' => 'piscina',
    'water_reading_frequency' => 0,
    'uses_hypochlorite' => 0,
    'requires_analysis' => 1,
    'has_reject_counter' => 0,
    'has_controller' => 0, // Valor padrão adicionado
    'controller_ip' => '',  // Valor padrão adicionado
    'volume_m3' => ''       // Volume em metros cúbicos, apenas para piscinas
];

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $is_editing = true;
    $tank_id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM tanks WHERE id = ?");
    $stmt->bind_param("i", $tank_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tank_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$tank_data) {
        // Se o ID for inválido, redireciona para a lista
        header("Location: gerir_tanques.php");
        exit;
    }

    // Mantém defaults para evitar avisos se a coluna ainda não existir localmente.
    $tank = array_merge($tank, $tank_data);
}
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0"><?= $is_editing ? 'Editar Tanque' : 'Novo Tanque' ?></h1>
                <a href="gerir_tanques.php" class="btn btn-secondary">Voltar à Lista</a>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <form action="guardar_tanque.php" method="POST">
                        <?php if ($is_editing): ?>
                            <input type="hidden" name="id" value="<?= $tank['id'] ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="name" class="form-label">Nome do Tanque</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($tank['name']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="type" class="form-label">Tipo</label>
                           <select class="form-select" id="type" name="type">
                                <option value="piscina" <?= $tank['type'] == 'piscina' ? 'selected' : '' ?>>Piscina</option>
                                <option value="outro" <?= $tank['type'] == 'outro' ? 'selected' : '' ?>>Outro Contador</option>
							</select>
                        </div>

                        <div class="mb-3">
                            <label for="water_reading_frequency" class="form-label">Contagem de Água</label>
                            <select class="form-select" id="water_reading_frequency" name="water_reading_frequency">
                                <option value="0" <?= $tank['water_reading_frequency'] == 0 ? 'selected' : '' ?>>Não efetua contagem</option>
                                <option value="1" <?= $tank['water_reading_frequency'] == 1 ? 'selected' : '' ?>>1 vez por dia</option>
                                <option value="2" <?= $tank['water_reading_frequency'] == 2 ? 'selected' : '' ?>>2 vezes por dia</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="uses_hypochlorite" class="form-label">Usa Hipoclorito?</label>
                            <select class="form-select" id="uses_hypochlorite" name="uses_hypochlorite">
                                <option value="1" <?= $tank['uses_hypochlorite'] == 1 ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= $tank['uses_hypochlorite'] == 0 ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="requires_analysis" class="form-label">Requer Análises?</label>
                            <select class="form-select" id="requires_analysis" name="requires_analysis">
                                <option value="1" <?= $tank['requires_analysis'] == 1 ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= $tank['requires_analysis'] == 0 ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>

						<div class="mb-3" id="reject_counter_container" style="display: none;">
                            <label for="has_reject_counter" class="form-label">Tem Contador de Rejeitado?</label>
                            <select class="form-select" id="has_reject_counter" name="has_reject_counter">
                                <option value="1" <?= $tank['has_reject_counter'] == 1 ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= $tank['has_reject_counter'] == 0 ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>

						<div class="mb-3" id="volume_container" style="display: none;">
                            <label for="volume_m3" class="form-label">Volume (m³)</label>
                            <input type="number" class="form-control" id="volume_m3" name="volume_m3" placeholder="Ex: 500" value="<?= htmlspecialchars($tank['volume_m3']) ?>" step="0.01" min="0">
                        </div>

						<div class="mb-3">
                            <label class="form-label">Tem Controlador Automático?</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="has_controller" id="controller_yes" value="1" <?= ($tank['has_controller'] == 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="controller_yes">Sim</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="has_controller" id="controller_no" value="0" <?= ($tank['has_controller'] == 0) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="controller_no">Não</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3" id="ip_field_container" style="display: none;">
                            <label for="controller_ip" class="form-label">Endereço IP do Controlador</label>
                            <input type="text" class="form-control" id="controller_ip" name="controller_ip" placeholder="Ex: 192.168.1.101" value="<?= htmlspecialchars($tank['controller_ip']) ?>">
                        </div>
                        
                        <div class="mt-4 text-end">
                            <a href="gerir_tanques.php" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Guardar Tanque</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('type');
    const rejectCounterContainer = document.getElementById('reject_counter_container');
    const rejectCounterSelect = document.getElementById('has_reject_counter');
    const controllerRadios = document.querySelectorAll('input[name="has_controller"]');
    const ipContainer = document.getElementById('ip_field_container');
    const ipInput = document.getElementById('controller_ip');
    const volumeContainer = document.getElementById('volume_container');
    const volumeInput = document.getElementById('volume_m3');

    function toggleRejectCounterField() {
        if (typeSelect.value === 'piscina') {
            rejectCounterContainer.style.display = 'block';
        } else {
            rejectCounterContainer.style.display = 'none';
            rejectCounterSelect.value = '0';
        }
    }

    function toggleVolumeField() {
        if (typeSelect.value === 'piscina') {
            volumeContainer.style.display = 'block';
        } else {
            volumeContainer.style.display = 'none';
            volumeInput.value = '';
        }
    }

    function toggleIpField() {
        if (document.querySelector('input[name="has_controller"]:checked').value == '1') {
            ipContainer.style.display = 'block';
        } else {
            ipContainer.style.display = 'none';
            ipInput.value = '';
        }
    }

    controllerRadios.forEach(function(radio) {
        radio.addEventListener('change', toggleIpField);
    });

    typeSelect.addEventListener('change', toggleRejectCounterField);
    typeSelect.addEventListener('change', toggleVolumeField);

    // Executa a função ao carregar a página
    toggleRejectCounterField();
    toggleVolumeField();
    toggleIpField();
});
</script>
<?php
// 4. INCLUIR O RODAPÉ
require_once '../footer.php';
?>
