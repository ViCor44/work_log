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
    'has_controller' => 0, // Valor padrão adicionado
    'controller_ip' => ''  // Valor padrão adicionado
];

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $is_editing = true;
    $tank_id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM tanks WHERE id = ?");
    $stmt->bind_param("i", $tank_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tank = $result->fetch_assoc();
    $stmt->close();
    
    if (!$tank) {
        // Se o ID for inválido, redireciona para a lista
        header("Location: gerir_tanques.php");
        exit;
    }
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
							    <option value="piscina" ...>Piscina</option>
							    <option value="outro" ...>Outro Contador</option>
							    <option value="lora" <?= $tank['type'] == 'lora' ? 'selected' : '' ?>>Equipamento LoRa</option>
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
    const controllerRadios = document.querySelectorAll('input[name="has_controller"]');
    const ipContainer = document.getElementById('ip_field_container');
    const ipInput = document.getElementById('controller_ip');

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

    // Executa a função ao carregar a página
    toggleIpField();
});
</script>
<?php
// 4. INCLUIR O RODAPÉ
require_once '../footer.php';
?>