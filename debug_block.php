<?php
require_once 'core.php';

echo "=== DEBUG: Verificação de bloqueio PID - TODOS OS REGISTROS RECENTES ===\n\n";

$stmt_all = $conn->prepare("SELECT tank_id, changed_at, p, i, d, reason FROM tank_pid_changes ORDER BY changed_at DESC LIMIT 20");
if ($stmt_all) {
    if ($stmt_all->execute()) {
        $all_changes = $stmt_all->get_result()->fetch_all(MYSQLI_ASSOC);
        echo "Total de registros encontrados: " . count($all_changes) . "\n\n";

        foreach ($all_changes as $index => $change) {
            $changeTime = strtotime($change['changed_at']);
            $hoursSinceChange = (time() - $changeTime) / 3600;
            $isBlocked = $hoursSinceChange < 72;

            echo "Registro " . ($index + 1) . " (Tank " . $change['tank_id'] . "):\n";
            echo "  Data: " . $change['changed_at'] . "\n";
            echo "  P: " . $change['p'] . ", I: " . $change['i'] . ", D: " . $change['d'] . "\n";
            echo "  Motivo: " . $change['reason'] . "\n";
            echo "  Horas atrás: " . round($hoursSinceChange, 2) . "\n";
            echo "  Bloqueado: " . ($isBlocked ? 'SIM' : 'NÃO') . "\n\n";
        }
    } else {
        echo "Erro ao executar query: " . $stmt_all->error . "\n";
    }
    $stmt_all->close();
} else {
    echo "Erro ao preparar query\n";
}

echo "Horário atual: " . date('Y-m-d H:i:s') . "\n";
?>