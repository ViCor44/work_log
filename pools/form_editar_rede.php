<?php
date_default_timezone_set('Europe/Lisbon'); // Define o fuso horário para Portugal
require_once '../header.php';

// Valida a data recebida pela URL
$edit_date = isset($_GET['date']) ? $_GET['date'] : null;
if (!$edit_date) {
    die("Data não especificada.");
}

// Encontra o ID do tanque 'Rede'
$stmt_tank = $conn->prepare("SELECT id FROM tanks WHERE name = 'Rede' LIMIT 1");
$stmt_tank->execute();
$result_tank = $stmt_tank->get_result();
$rede_tank_id = $result_tank->num_rows > 0 ? $result_tank->fetch_assoc()['id'] : null;
$stmt_tank->close();

if (!$rede_tank_id) {
    die("Tanque 'Rede' não encontrado.");
}

// Busca TODOS os registos do tanque 'Rede' para a data especificada
$sql = "SELECT id, reading_datetime, meter_value FROM water_readings WHERE tank_id = ? AND DATE(reading_datetime) = ? ORDER BY reading_datetime ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $rede_tank_id, $edit_date);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separa os dados em Manhã e Tarde com base na hora do registo
$reading_manha = null;
$reading_tarde = null;

foreach ($results as $row) {
    // Cria um objeto DateTime a partir da string vinda da base de dados.
    // Esta é a forma mais segura de manipular datas e horas.
    $dateTimeObj = new DateTime($row['reading_datetime']);
    
    // Extrai a hora (formato 00-23) a partir do objeto.
    $hour = (int)$dateTimeObj->format('H');

    // Compara a hora extraída.
    if ($hour < 13) {
        $reading_manha = $row;
    } else {
        $reading_tarde = $row;
    }
}
?>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="mb-0">Editar/Adicionar Leituras da Rede para o dia <?= htmlspecialchars(date('d/m/Y', strtotime($edit_date))) ?></h3>
        </div>
        <div class="card-body">
            <form action="guardar_edicao_rede.php" method="POST">
                <input type="hidden" name="edit_date" value="<?= htmlspecialchars($edit_date) ?>">
                <input type="hidden" name="tank_id" value="<?= htmlspecialchars($rede_tank_id) ?>">

                <div class="mb-3">
                    <label for="meter_value_manha" class="form-label fw-bold">Leitura da Manhã (m³)</label>
                    <input type="hidden" name="record_id_manha" value="<?= isset($reading_manha['id']) ? $reading_manha['id'] : '' ?>">
                    <input type="number" step="any" class="form-control form-control-lg" id="meter_value_manha" name="meter_value_manha" value="<?= isset($reading_manha['meter_value']) ? htmlspecialchars($reading_manha['meter_value']) : '' ?>" placeholder="Insira a leitura da manhã">
                </div>

                <div class="mb-3">
                    <label for="meter_value_tarde" class="form-label fw-bold">Leitura da Tarde (m³)</label>
                    <input type="hidden" name="record_id_tarde" value="<?= isset($reading_tarde['id']) ? $reading_tarde['id'] : '' ?>">
                    <input type="number" step="any" class="form-control form-control-lg" id="meter_value_tarde" name="meter_value_tarde" value="<?= isset($reading_tarde['meter_value']) ? htmlspecialchars($reading_tarde['meter_value']) : '' ?>" placeholder="Insira a leitura da tarde">
                </div>

                <div class="text-end mt-4">
                    <a href="list_rede.php" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-success">Guardar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once '../footer.php';
?>