<?php
require_once '../header.php';

// Determina o período baseado na hora atual
$current_hour = date('H');
$periodo = ($current_hour < 13) ? 'manha' : 'tarde';

// Busca apenas os tanques que precisam de análises
$stmt = $conn->query("SELECT id, name FROM tanks WHERE requires_analysis = 1 ORDER BY name");
$tanks = $stmt->fetch_all(MYSQLI_ASSOC);
?>

<style>
    .tank-card-form {
        background-color: #4682B4; /* SteelBlue */
        color: white;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .tank-card-form h5 {
        font-weight: bold;
    }
   .tank-card-form .form-label {
	    margin-bottom: 0.2rem;
	    font-size: 0.9rem;
	    color: #000; /* Cor da fonte preta para máximo contraste */
	    font-weight: 500; /* Fonte ligeiramente mais grossa */
	    text-shadow: 0 0 3px rgba(255, 255, 255, 0.6); /* Sombra/brilho branco para destacar do fundo */
	}
    .tank-card-form .form-control {
        background-color: rgba(255,255,255,0.9);
        border: 1px solid #ccc;
        color: #333;
    }
    .form-actions {
        background-color: #f8f9fa;
        padding: 1rem;
        border-radius: 0.5rem;
        position: sticky;
        bottom: 0;
        z-index: 10;
        box-shadow: 0 -4px 8px rgba(0,0,0,0.1);
    }
</style>

<div class="container-fluid mt-4">
	<div class="d-flex align-items-center mb-4">
	    <i class="fas fa-vial fa-3x text-primary me-3"></i>
	    <h1 class="h3 mb-0">Registar Análises - <span class="text-primary"><?= ucfirst($periodo) ?></span></h1>
	</div>

    <?php if(count($tanks) > 0): ?>
    <form action="guardar_registos_batch.php" method="POST" id="analysis-form">
        <input type="hidden" name="tipo_registo" value="analise">
        <input type="hidden" name="periodo" value="<?= $periodo ?>">

        <div class="row">
            <?php foreach($tanks as $tank): ?>
                <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-4">
                    <div class="tank-card-form">
                        <h5><?= htmlspecialchars($tank['name']) ?></h5>
                        <hr class="text-white-50 mt-1 mb-3">
                        <div class="mb-2">
                            <label class="form-label">pH</label>
                            <input type="number" step="0.01" class="form-control" name="ph_level[<?= $tank['id'] ?>]">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Cloro (ppm)</label>
                            <input type="number" step="0.01" class="form-control" name="chlorine_level[<?= $tank['id'] ?>]">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Temp. (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="temperature[<?= $tank['id'] ?>]">
                        </div>
                        
                        <div class="mb-2">
                            <label class="form-label">Condutividade (mS/cm)</label>
                            <input type="number" step="0.01" class="form-control" name="conductivity[<?= $tank['id'] ?>]">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Sólidos Dissolv. (mg/l)</label>
                            <input type="number" step="0.01" class="form-control" name="dissolved_solids[<?= $tank['id'] ?>]">
                        </div>
                        </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions text-end mt-4">
            <a href="registos.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar Todas as Análises</button>
        </div>
    </form>
    <?php else: ?>
        <div class="alert alert-warning">Não existem tanques configurados para registo de análises.</div>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('analysis-form');

    if (form) {
        // --- Lógica de Navegação por Teclado (Enter e Setas) ---
        const inputs = Array.from(form.querySelectorAll('input[type="number"]'));
        const fieldsPerCard = 5; // pH, Cloro, Temp, Condutividade, Sólidos

        if (inputs.length > 0) {
            inputs.forEach((input, index) => {
                input.addEventListener('keydown', function(event) {
                    let nextIndex = -1;
                    switch (event.key) {
                        case 'Enter':
                        case 'ArrowDown':
                            event.preventDefault();
                            nextIndex = index + 1;
                            break;
                        case 'ArrowUp':
                            event.preventDefault();
                            nextIndex = index - 1;
                            break;
                        case 'ArrowRight':
                            event.preventDefault();
                            nextIndex = index + fieldsPerCard;
                            break;
                        case 'ArrowLeft':
                            event.preventDefault();
                            nextIndex = index - fieldsPerCard;
                            break;
                    }
                    if (nextIndex >= 0 && nextIndex < inputs.length) {
                        inputs[nextIndex].focus();
                        inputs[nextIndex].select();
                    } else if (event.key === 'Enter' && nextIndex >= inputs.length) {
                        form.querySelector('button[type="submit"]').focus();
                    }
                });
            });
        }

        // --- Lógica de Preenchimento Automático dos Sólidos Dissolvidos ---
        const conductivityInputs = form.querySelectorAll('input[name^="conductivity"]');
        conductivityInputs.forEach(condInput => {
            condInput.addEventListener('input', function() {
                const tankId = this.name.match(/\[(\d+)\]/)[1];
                const solidsInput = form.querySelector(`input[name="dissolved_solids[${tankId}]"]`);
                if (solidsInput) {
                    const conductivityValue = parseFloat(this.value);
                    if (!isNaN(conductivityValue) && this.value.trim() !== '') {
                        const solidsValue = (conductivityValue / 2).toFixed(2);
                        solidsInput.value = solidsValue;
                    } else {
                        solidsInput.value = '';
                    }
                }
            });
        });

        // --- Lógica de Validação de Preenchimento Obrigatório ---
        const allNumberInputs = form.querySelectorAll('input[type="number"]');
        const submitButton = form.querySelector('button[type="submit"]');

        function validateForm() {
            let allFilled = true;
            allNumberInputs.forEach(input => {
                if (input.value.trim() === '') {
                    allFilled = false;
                }
            });
            submitButton.disabled = !allFilled;
        }

        form.addEventListener('input', validateForm);
        validateForm(); // Verifica o estado inicial ao carregar
    }
});
</script>

<?php
require_once '../footer.php';
?>