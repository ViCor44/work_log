<?php
// Obter todos os tanques para o dropdown
$tanks_stmt = $conn->query("SELECT id, name, requires_analysis, water_reading_frequency, uses_hypochlorite FROM tanks ORDER BY name ASC");
$tanks = $tanks_stmt->fetch_all(MYSQLI_ASSOC);
?>

<div class="card shadow-sm border-top-0 rounded-0 rounded-bottom">
    <div class="card-body p-4">
        
        <?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
            <div class="alert alert-success">Registo guardado com sucesso!</div>
        <?php endif; ?>

        <form action="guardar_registo.php" method="POST">
            <div class="mb-4">
                <label for="tank_id" class="form-label fs-5">Selecione o Tanque</label>
                <select class="form-select" id="tank_selector" name="tank_id" required>
                    <option value="" selected disabled>-- Por favor, escolha um tanque --</option>
                    <?php foreach ($tanks as $tank): ?>
                        <option value="<?= $tank['id'] ?>"
                                data-requires-analysis="<?= $tank['requires_analysis'] ?>"
                                data-water-frequency="<?= $tank['water_reading_frequency'] ?>"
                                data-uses-hypochlorite="<?= $tank['uses_hypochlorite'] ?>">
                            <?= htmlspecialchars($tank['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="registration_sections" style="display: none;">
                
                <fieldset class="mb-4" id="analysis_section" style="display: none;">
                    <legend class="fs-5">Análises</legend>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="ph_level" class="form-label">Nível de pH</label>
                            <input type="number" step="0.01" class="form-control" name="ph_level">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="chlorine_level" class="form-label">Nível de Cloro (ppm)</label>
                            <input type="number" step="0.01" class="form-control" name="chlorine_level">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="temperature" class="form-label">Temperatura (°C)</label>
                            <input type="number" step="0.1" class="form-control" name="temperature">
                        </div>
                        <div class="col-12">
                             <label for="analysis_notes" class="form-label">Notas da Análise</label>
                             <textarea class="form-control" name="analysis_notes" rows="2"></textarea>
                        </div>
                    </div>
                </fieldset>
                
                <fieldset class="mb-4" id="water_section" style="display: none;">
                    <legend class="fs-5">Contagens de Água</legend>
                    </fieldset>
                
                <fieldset class="mb-4" id="hypochlorite_section" style="display: none;">
                    <legend class="fs-5">Hipoclorito</legend>
                    <div class="row">
                        <div class="col-md-6">
                            <label for="hypo_consumption" class="form-label">Consumo de Hipoclorito (L)</label>
                            <input type="number" step="0.01" class="form-control" name="hypo_consumption">
                        </div>
                    </div>
                </fieldset>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary">Guardar Registo</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tankSelector = document.getElementById('tank_selector');
    const registrationSections = document.getElementById('registration_sections');
    
    const analysisSection = document.getElementById('analysis_section');
    const waterSection = document.getElementById('water_section');
    const hypochloriteSection = document.getElementById('hypochlorite_section');

    tankSelector.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (!selectedOption.value) {
            registrationSections.style.display = 'none';
            return;
        }

        const requiresAnalysis = selectedOption.dataset.requiresAnalysis === '1';
        const waterFrequency = parseInt(selectedOption.dataset.waterFrequency, 10);
        const usesHypochlorite = selectedOption.dataset.usesHypochlorite === '1';
        
        // Mostrar/esconder secções inteiras
        analysisSection.style.display = requiresAnalysis ? 'block' : 'none';
        waterSection.style.display = waterFrequency > 0 ? 'block' : 'none';
        hypochloriteSection.style.display = usesHypochlorite ? 'block' : 'none';

        // Lógica para os campos de água
        const waterFieldsContainer = waterSection.querySelector('legend').parentNode;
        // Limpar campos antigos
        waterFieldsContainer.querySelectorAll('.water-reading-field').forEach(el => el.remove());

        if (waterFrequency === 1) {
            waterFieldsContainer.innerHTML += `
                <div class="row water-reading-field">
                    <div class="col-md-6">
                        <label for="water_reading_day" class="form-label">Leitura do Contador (m³)</label>
                        <input type="number" step="0.001" class="form-control" name="water_reading_day">
                    </div>
                </div>`;
        } else if (waterFrequency === 2) {
            waterFieldsContainer.innerHTML += `
                <div class="row water-reading-field">
                    <div class="col-md-6 mb-3">
                        <label for="water_reading_morning" class="form-label">Leitura da Manhã (m³)</label>
                        <input type="number" step="0.001" class="form-control" name="water_reading_morning">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="water_reading_afternoon" class="form-label">Leitura da Tarde (m³)</label>
                        <input type="number" step="0.001" class="form-control" name="water_reading_afternoon">
                    </div>
                </div>`;
        }
        
        registrationSections.style.display = 'block';
    });
});
</script>