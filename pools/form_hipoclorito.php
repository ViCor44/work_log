<?php
require_once '../header.php';

$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$month_for_cancel = substr($current_date, 0, 7);
$date_display = date('d/m/Y', strtotime($current_date));
$is_today = ($current_date === date('Y-m-d'));

$title = $is_today ? 'Registar Nível de Hipoclorito' : "Registar Nível de Hipoclorito - {$date_display}";

$sql_tanks = "SELECT id, name FROM tanks WHERE uses_hypochlorite = 1 ORDER BY name ASC";
$query_tanks = $conn->query($sql_tanks);
if ($query_tanks === false) {
    die("Erro na consulta SQL: " . $conn->error);
}
$raw_tanks = $query_tanks->fetch_all(MYSQLI_ASSOC);

$processed_tanks = [];
foreach ($raw_tanks as $tank) {
    $tank_id = $tank['id'];

    // Obter registo existente na data alvo
    $stmt = $conn->prepare("SELECT id, consumption_liters FROM hypochlorite_readings WHERE tank_id = ? AND DATE(reading_datetime) = ? ORDER BY reading_datetime DESC LIMIT 1");
    $stmt->bind_param("is", $tank_id, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    // Obter leitura anterior à data alvo
    $stmt = $conn->prepare("SELECT consumption_liters FROM hypochlorite_readings WHERE tank_id = ? AND reading_datetime < ? ORDER BY reading_datetime DESC LIMIT 1");
    $stmt->bind_param("is", $tank_id, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $prev_row = $result->fetch_assoc();
    $previous = $prev_row ? (float)$prev_row['consumption_liters'] : 0.0;
    $stmt->close();

    // Obter segunda leitura anterior à data alvo
    $stmt = $conn->prepare("SELECT consumption_liters FROM hypochlorite_readings WHERE tank_id = ? AND reading_datetime < ? ORDER BY reading_datetime DESC LIMIT 1,1");
    $stmt->bind_param("is", $tank_id, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $second_row = $result->fetch_assoc();
    $second = $second_row ? (float)$second_row['consumption_liters'] : 0.0;
    $stmt->close();

    $existing_id = $existing ? (int)$existing['id'] : null;
    $existing_level = $existing ? (float)$existing['consumption_liters'] : null;

    $processed_tanks[] = [
        'id' => $tank_id,
        'name' => $tank['name'],
        'previous_reading' => $previous,
        'second_reading' => $second,
        'existing_id' => $existing_id,
        'existing_level' => $existing_level
    ];
}
$tanks = $processed_tanks;
?>

<style>
    .tank-card-form {
        background-color: #ffc107; /* Amarelo/Laranja para este formulário */
        color: #343a40;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    /* Estilos adaptados para hipoclorito */
    .tank-card-form h5 { font-weight: bold; }
    .tank-card-form hr { border-top: 1px solid rgba(0,0,0,0.2); }
    .tank-card-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; }
    .tank-card-form .form-control { background-color: rgba(255,255,255,0.9); border: 1px solid #ccc; color: #333; font-weight: bold; }
    .form-actions { background-color: #f8f9fa; padding: 1rem; border-radius: 0.5rem; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 8px rgba(0,0,0,0.1); }
    .reading-details { font-size: 0.85rem; background-color: rgba(0,0,0,0.1); padding: 5px 10px; border-radius: 4px; margin-top: 10px; }
    .reading-details .diff-value { font-weight: bold; }
    .diff-pos { color: #198754; }
    .diff-neg { color: #dc3545; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex align-items-center mb-4">			            
        <i class="fas fa-flask fa-3x text-warning"></i>       
        <h1 class="h3 mb-0"><?= htmlspecialchars($title) ?></h1>
    </div>

    <?php if(count($tanks) > 0): ?>
    <form action="guardar_registos_batch.php" method="POST" id="hypo-form">
        <input type="hidden" name="tipo_registo" value="hipoclorito">
        <input type="hidden" name="target_date" value="<?= htmlspecialchars($current_date) ?>">

        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                    <div class="tank-card-form">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <hr class="mt-1 mb-3">
                        <div class="mb-2">
                            <label class="form-label">Nível Atual (Litros)</label>
                            <input type="number" step="0.01" class="form-control reading-input" 
                                   name="hipo[<?= $tank['id'] ?>]"
                                   value="<?= $tank['existing_level'] ? number_format($tank['existing_level'], 2, ',', '.') : '' ?>"
                                   data-tank-id="<?= $tank['id'] ?>"
                                   data-previous-reading="<?= $tank['previous_reading'] ?>"
                                   data-second-reading="<?= $tank['second_reading'] ?>">
                            <?php if ($tank['existing_id']): ?>
                                <input type="hidden" name="existing_id[<?= $tank['id'] ?>]" value="<?= $tank['existing_id'] ?>">
                            <?php endif; ?>
                        </div>
                        
                        <div class="reading-details">
                            <div>Referência Anterior: 
                                <strong id="previous-reading-<?= $tank['id'] ?>">
                                    <?= number_format($tank['previous_reading'], 0, ',', '.') ?>
                                </strong>
                            </div>
                            <div>Consumo: 
                                <span id="diff-<?= $tank['id'] ?>" class="diff-value"></span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="list_hipoclorito.php?month=<?= htmlspecialchars($month_for_cancel) ?>" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Consumos</button>
        </div>
    </form>
    <?php else: ?>
        <div class="alert alert-warning">Não existem tanques configurados para registo de consumo de hipoclorito.</div>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Garante que estamos a trabalhar no formulário correto, dando-lhe um ID
    const form = document.getElementById('hypo-form');
    if (!form) return;

    const allNumberInputs = Array.from(form.querySelectorAll('input[type="number"].reading-input'));
    const submitButton = form.querySelector('button[type="submit"]');
    
    // Como cada card só tem 1 input, a navegação horizontal funciona como a vertical
    const fieldsPerCard = 1;

    // Função para validar o formulário
    function validateForm() {
        let allFilled = true;
        allNumberInputs.forEach(input => {
            if (input.value.trim() === '') {
                allFilled = false;
            }
        });
        submitButton.disabled = !allFilled;
    }

    // Adiciona os "escutadores" de eventos a cada input
    allNumberInputs.forEach((input, index) => {
        // Evento para NAVEGAÇÃO COM TECLADO
        input.addEventListener('keydown', function(event) {
            let nextIndex = -1;
            switch (event.key) {
                case 'Enter':
                case 'ArrowDown':
                case 'ArrowRight':
                    event.preventDefault();
                    nextIndex = index + 1;
                    break;
                case 'ArrowUp':
                case 'ArrowLeft':
                    event.preventDefault();
                    nextIndex = index - 1;
                    break;
            }
            if (nextIndex >= 0 && nextIndex < allNumberInputs.length) {
                allNumberInputs[nextIndex].focus();
                allNumberInputs[nextIndex].select();
            } else if (event.key === 'Enter' && nextIndex >= allNumberInputs.length) {
                if (!submitButton.disabled) {
                    submitButton.focus();
                }
            }
        });

        // Evento para CÁLCULO DA DIFERENÇA e VALIDAÇÃO
        input.addEventListener('input', function() {
            // Lógica de cálculo do consumo (adaptada)
            const tankId = this.dataset.tankId;
            const previousReading = parseFloat(this.dataset.previousReading) || 0;
            const secondReading = parseFloat(this.dataset.secondReading) || 0;
            const currentReading = parseFloat(this.value);
            const diffSpan = document.getElementById('diff-' + tankId);
            
            if (!diffSpan) return;

            if (isNaN(currentReading) || this.value.length === 0) {
                diffSpan.textContent = '';
                diffSpan.className = 'diff-value';
            } else {
                if (currentReading > previousReading && previousReading > 0) {
                    diffSpan.className = 'diff-value refill-info';
                    if (secondReading > 0) {
                        const previousConsumption = secondReading - previousReading;
                        if (previousConsumption >= 0) {
                            diffSpan.textContent = `~ ${new Intl.NumberFormat('pt-PT').format(Math.round(previousConsumption))} (dia anterior)`;
                        } else {
                            diffSpan.textContent = 'Reabastecimento';
                        }
                    } else {
                        diffSpan.textContent = 'Reabastecimento';
                    }
                } else {
                    diffSpan.className = 'diff-value consumption-value';
                    if (previousReading > 0) {
                        const consumption = previousReading - currentReading;
                        diffSpan.textContent = new Intl.NumberFormat('pt-PT').format(Math.round(consumption));
                    } else {
                        diffSpan.textContent = '(primeiro registo)';
                    }
                }
            }
            // Valida o formulário após cada alteração
            validateForm();
        });
    });

    // Valida o formulário quando a página carrega e calcula diffs iniciais se houver valores
    validateForm();
    allNumberInputs.forEach(input => {
        if (input.value) {
            input.dispatchEvent(new Event('input'));
        }
    });
});
</script>

<?php
require_once '../footer.php';
?>