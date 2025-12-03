<?php
// Este script é desenhado para ser executado pelo servidor, não por um browser.
// Usamos dirname(__DIR__) para garantir que o caminho para o core.php está sempre correto.
require_once dirname(__DIR__) . '/core.php';

// 1. Busca todas as centrais de medida registadas na base de dados
$meters_stmt = $conn->query("SELECT id, local, ip_address FROM centrais_de_medida");
$meters = $meters_stmt->fetch_all(MYSQLI_ASSOC);

// Prepara a query de INSERT uma vez para ser reutilizada dentro do ciclo.
// Isto é mais eficiente do que preparar a query a cada iteração.
$stmt_insert = $conn->prepare("
    INSERT INTO power_meter_history (
        meter_id, voltageLLAvg, currentAvg, activePowerTotal,
        voltageAB, voltageBC, voltageCA, voltageAN, voltageBN, voltageCN,
        voltageLNAvg, currentA, currentB, currentC, activePowerA,
        activePowerB, activePowerC, powerFactorTotal, frequency
    ) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

echo "A iniciar a busca de dados das Centrais de Medida...\n";

// 2. Faz um ciclo por cada central de medida encontrada
foreach ($meters as $meter) {
    $meter_id = $meter['id'];
    $ip = $meter['ip_address'];
	$url = "http://" . $ip . "/ajax_inputs";
    
    // 3. Usa cURL para ir buscar o conteúdo do XML/JSON.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Aumentado para 10 segundos
    $response_text = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response_text) {
        // Converte a resposta (assumindo XML) para um array
        try {
            $xml = new SimpleXMLElement($response_text);
            $data = json_decode(json_encode($xml), true);

            if ($data) {
                // 4. Insere os dados na tabela de histórico
                $stmt_insert->bind_param("idddddddddddddddddd", 
                    $meter_id,
                    $data['voltageLLAvg'], $data['currentAvg'], $data['activePowerTotal'],
                    $data['voltageAB'], $data['voltageBC'], $data['voltageCA'],
                    $data['voltageAN'], $data['voltageBN'], $data['voltageCN'],
                    $data['voltageLNAvg'], $data['currentA'], $data['currentB'],
                    $data['currentC'], $data['activePowerA'], $data['activePowerB'],
                    $data['activePowerC'], $data['powerFactorTotal'], $data['frequency']
                );
                $stmt_insert->execute();
                echo "Dados da central '". $meter['local'] ."' inseridos com sucesso.\n";
            }
        } catch (Exception $e) {
            echo "Erro ao processar XML para a central '". $meter['local'] ."': " . $e->getMessage() . "\n";
        }
    } else {
        echo "Falha ao contactar a central '". $meter['local'] ."' no IP: " . $ip . "\n";
    }
}

$stmt_insert->close();
$conn->close();
echo "Processo concluído.\n";
?>