<?php
require_once '../header.php';

// ALTERAÇÃO 1: A query SQL agora também seleciona a coluna 'type'
$sql = "
    SELECT
        t.id,
        t.name,
        t.type, -- Adicionada a coluna 'type'
        (
            SELECT lr.meter_value
            FROM water_readings lr
            WHERE lr.tank_id = t.id
            ORDER BY lr.reading_datetime DESC
            LIMIT 1
        ) AS last_reading
    FROM
        tanks t
    WHERE
        t.water_reading_frequency > 0
    ORDER BY
        t.type, t.name ASC; -- Ordena primeiro por tipo, depois por nome
";

$query_result = $conn->query($sql);
if ($query_result === false) {
    die("Erro na consulta SQL: " . $conn->error);
}
$all_tanks = $query_result->fetch_all(MYSQLI_ASSOC);

// ALTERAÇÃO 2: Separar os tanques em dois arrays diferentes
$piscina_tanks = [];
$special_tanks = [];
$other_tanks = [];

foreach ($all_tanks as $tank) {
    if ($tank['name'] === 'Rede' || $tank['name'] === 'Edificio') {
        $special_tanks[] = $tank;
    } elseif ($tank['type'] === 'piscina') {
        $piscina_tanks[] = $tank;
    } else {
        $other_tanks[] = $tank;
    }
}
?>

<style>
    .tank-card-form { background-color: #0d6efd; color: white; border-radius: 8px; padding: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); height: 100%; display: flex; flex-direction: column; }
    .tank-card-form h5 { font-weight: bold; }
    .tank-card-form .form-label { margin-bottom: 0.2rem; font-size: 0.9rem; }
    .tank-card-form .form-control { background-color: rgba(255,255,255,0.9); border: 1px solid #ccc; color: #333; font-weight: bold; }
    .form-actions { background-color: #f8f9fa; padding: 1rem; border-radius: 0.5rem; position: sticky; bottom: 0; z-index: 10; box-shadow: 0 -4px 8px rgba(0,0,0,0.1); }
    .reading-details { font-size: 0.85rem; background-color: rgba(0,0,0,0.1); padding: 5px 10px; border-radius: 4px; margin-top: 10px; }
    .reading-details .diff-value { font-weight: bold; }
    .diff-pos { color: #0f5132; background-color: #d1e7dd; padding: 2px 4px; border-radius: 3px; }
    .diff-neg { color: #842029; background-color: #f8d7da; padding: 2px 4px; border-radius: 3px; }
</style>

<div class="container-fluid mt-4">
    <div class="d-flex align-items-center mb-4">			
		<i class="fas fa-cloud-sun fa-3x text-info"></i>		
        <h1 class="h3 mb-0 ms-3">Registar Leituras da Manhã</h1>
    </div>

    <form action="guardar_registos_batch.php" method="POST" id="water-form-manha">
        <input type="hidden" name="tipo_registo" value="agua_manha">

        <?php if(count($special_tanks) > 0): ?>
            <h4 class="mb-3">Contadores Gerais</h4>
            <div class="row">
                <?php foreach($special_tanks as $tank): ?>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="tank-card-form">
                            <h5><?= htmlspecialchars($tank['name']) ?></h5>
                            <hr class="text-white-50 mt-1 mb-3">
                            <div class="mb-2">
                                <label class="form-label">Valor do Contador (m³)</label>
                                <input type="number" step="0.001" class="form-control reading-input" 
                                       name="agua[<?= $tank['id'] ?>]"
                                       data-tank-id="<?= $tank['id'] ?>"
                                       data-last-reading="<?= htmlspecialchars(isset($tank['last_reading']) ? $tank['last_reading'] : '0') ?>">
                            </div>
                            <div class="reading-details">
                                <div>Última Leitura: 
                                    <strong id="last-reading-<?= $tank['id'] ?>">
                                        <?= !empty($tank['last_reading']) ? number_format($tank['last_reading'], 0, ',', '.') : 'N/A' ?>
                                    </strong>
                                </div>
                                <div>Diferença: 
                                    <span id="diff-<?= $tank['id'] ?>" class="diff-value"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr class="my-4">
        <?php endif; ?>

        <?php if(count($piscina_tanks) > 0): ?>
            <h4 class="mb-3">Piscinas</h4>
            <div class="row">
                <?php foreach($piscina_tanks as $tank): ?>
                    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="tank-card-form">
                            <h5><?= htmlspecialchars($tank['name']) ?></h5>
                            <hr class="text-white-50 mt-1 mb-3">
                            <div class="mb-2">
                                <label class="form-label">Valor do Contador (m³)</label>
                                <input type="number" step="0.001" class="form-control reading-input" 
                                       name="agua[<?= $tank['id'] ?>]"
                                       data-tank-id="<?= $tank['id'] ?>"
                                       data-last-reading="<?= htmlspecialchars(isset($tank['last_reading']) ? $tank['last_reading'] : '0') ?>">
                            </div>
                            <div class="reading-details">
                                <div>Última Leitura: 
                                    <strong id="last-reading-<?= $tank['id'] ?>">
                                        <?= !empty($tank['last_reading']) ? number_format($tank['last_reading'], 0, ',', '.') : 'N/A' ?>
                                    </strong>
                                </div>
                                <div>Diferença: 
                                    <span id="diff-<?= $tank['id'] ?>" class="diff-value"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr class="my-4">
        <?php endif; ?>


        <?php if(count($other_tanks) > 0): ?>
            <h4 class="mb-3">Outros Tanques</h4>
            <div class="row">
                <?php foreach($other_tanks as $tank): ?>
                     <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="tank-card-form">
                            <h5><?= htmlspecialchars($tank['name']) ?></h5>
                            <hr class="text-white-50 mt-1 mb-3">
                            <div class="mb-2">
                                <label class="form-label">Valor do Contador (m³)</label>
                                <input type="number" step="0.001" class="form-control reading-input" 
                                       name="agua[<?= $tank['id'] ?>]"
                                       data-tank-id="<?= $tank['id'] ?>"
                                       data-last-reading="<?= htmlspecialchars(isset($tank['last_reading']) ? $tank['last_reading'] : '0') ?>">
                            </div>
                            <div class="reading-details">
                                <div>Última Leitura: 
                                    <strong id="last-reading-<?= $tank['id'] ?>">
                                        <?= !empty($tank['last_reading']) ? number_format($tank['last_reading'], 0, ',', '.') : 'N/A' ?>
                                    </strong>
                                </div>
                                <div>Diferença: 
                                    <span id="diff-<?= $tank['id'] ?>" class="diff-value"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>


        <?php if(count($all_tanks) > 0): ?>
            <div class="form-actions text-end mt-4">
                <a href="registos.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Guardar Leituras da Manhã</button>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">Não existem tanques configurados para leitura de água.</div>
        <?php endif; ?>
    </form>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('water-form-manha');
    if (!form) return;

    const allNumberInputs = Array.from(form.querySelectorAll('input[type="number"].reading-input'));
    const submitButton = form.querySelector('button[type="submit"]');
    const fieldsPerCard = 1; // Apenas 1 input por card neste formulário

    // Função para validar o formulário e ativar/desativar o botão
    function validateForm() {
        let allFilled = true;
        allNumberInputs.forEach(input => {
            if (input.value.trim() === '') {
                allFilled = false;
            }
        });
        submitButton.disabled = !allFilled;
    }

    // Função para calcular a diferença
    function calculateDifference(inputElement) {
        const tankId = inputElement.dataset.tankId;
        const lastReading = parseFloat(inputElement.dataset.lastReading);
        const currentReading = parseFloat(inputElement.value);
        const diffSpan = document.getElementById('diff-' + tankId);
        
        if (!diffSpan) return;

        if (!isNaN(currentReading) && inputElement.value.trim() !== '') {
            if (lastReading > 0) {
                const difference = currentReading - lastReading;
                diffSpan.textContent = new Intl.NumberFormat('pt-PT').format(Math.round(difference));
                if (difference > 0) diffSpan.className = 'diff-value diff-pos';
                else if (difference < 0) diffSpan.className = 'diff-value diff-neg';
                else diffSpan.className = 'diff-value';
            } else {
                 diffSpan.textContent = '(primeira leitura)';
                 diffSpan.className = 'diff-value';
            }
        } else {
            diffSpan.textContent = '';
            diffSpan.className = 'diff-value';
        }
    }

    // Adiciona os "escutadores" de eventos a cada input
    allNumberInputs.forEach((input, index) => {
        // Evento para navegação com teclado
        input.addEventListener('keydown', function(event) {
            let nextIndex = -1;
            // Como só há 1 campo por card, Direita/Esquerda fazem o mesmo que Baixo/Cima
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

        // Evento para o cálculo da diferença e validação
        input.addEventListener('input', function() {
            calculateDifference(this);
            validateForm(); // Valida o formulário a cada alteração
        });
    });

    // Valida o formulário quando a página carrega
    validateForm();
});
</script>



<?php
require_once '../footer.php';
?>