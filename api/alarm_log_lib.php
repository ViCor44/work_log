<?php

function ensure_alarm_event_log_table(mysqli $conn): bool
{
    return $conn->query("CREATE TABLE IF NOT EXISTS alarm_event_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tank_id INT NULL,
        user_id INT NULL,
        alarm_type VARCHAR(64) NOT NULL,
        event_type VARCHAR(32) NOT NULL,
        current_value DECIMAL(10,3) NULL,
        first_active_at DATETIME NULL,
        details_json TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_alarm_event_tank (tank_id, created_at),
        INDEX idx_alarm_event_type (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}

function log_alarm_event(mysqli $conn, ?int $tankId, ?int $userId, string $alarmType,
    string $eventType, ?float $currentValue = null, ?string $firstActiveAt = null, array $details = []): void
{
    ensure_alarm_event_log_table($conn);
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt = $conn->prepare("INSERT INTO alarm_event_log
        (tank_id,user_id,alarm_type,event_type,current_value,first_active_at,details_json)
        VALUES (?,?,?,?,?,?,?)");
    if (!$stmt) return;
    $stmt->bind_param('iissdss', $tankId, $userId, $alarmType, $eventType, $currentValue, $firstActiveAt, $json);
    $stmt->execute();
    $stmt->close();
}
