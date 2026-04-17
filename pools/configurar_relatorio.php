<?php
require_once '../core.php';

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
    // Se for array de secções (antigo formato), converte para ['sections' => ...]
    if (isset($decoded['sections'])) {
        $config = $decoded;
    } elseif (is_array($decoded)) {
        $config['sections'] = $decoded;
    }
}

// Garante que sempre existe $config['sections']
if (!isset($config['sections']) || !is_array($config['sections'])) {
    $config['sections'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['sections'] = $_POST['sections'];
    file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: configurar_relatorio.php?salvo=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Configurar Relatório de Água</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        .section { margin-bottom: 30px; border: 1px solid #ccc; padding: 15px; border-radius: 8px; }
        .section input[type=text] { font-size: 1em; padding: 2px 6px; }
        .tank-list { columns: 2; }
        .tank-item { margin-bottom: 4px; }
        .save-btn { font-size: 1.1em; padding: 8px 18px; }
    </style>
</head>
<body>
    <h1>Configurar Relatório de Água</h1>
    <?php if (isset($_GET['salvo'])): ?>
        <p style="color: green;">Configuração salva com sucesso!</p>
    <?php endif; ?>
    <form method="post">
        <?php foreach ($config['sections'] as $secIdx => $section): ?>
            <div class="section">
                <label>Nome da Secção:
                    <input type="text" name="sections[<?= $secIdx ?>][name]" value="<?= htmlspecialchars($section['name']) ?>">
                </label>
                <div class="tank-list">
                    <?php foreach ($tanks as $tank): ?>
                        <div class="tank-item">
                            <label>
                                <input type="checkbox" name="sections[<?= $secIdx ?>][tanks][]" value="<?= $tank['id'] ?>" <?= in_array($tank['id'], $section['tanks']) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($tank['name']) ?> (<?= htmlspecialchars($tank['type']) ?>)
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="save-btn">Salvar Configuração</button>
    </form>
</body>
</html>
