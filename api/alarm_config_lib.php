<?php

function ensure_alarm_config_table(mysqli $conn): bool
{
    return $conn->query("CREATE TABLE IF NOT EXISTS controller_alarm_config (
        tank_id INT NOT NULL,
        chlorine_min DECIMAL(6,2) NOT NULL DEFAULT 1.00,
        chlorine_max DECIMAL(6,2) NOT NULL DEFAULT 3.00,
        ph_min DECIMAL(5,2) NOT NULL DEFAULT 7.00,
        ph_max DECIMAL(5,2) NOT NULL DEFAULT 7.80,
        modal_delay_minutes INT NOT NULL DEFAULT 5,
        modal_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sound_enabled TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tank_id),
        CONSTRAINT fk_alarm_config_tank FOREIGN KEY (tank_id) REFERENCES tanks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === true;
}

function default_alarm_config(): array
{
    return ['chlorine_min'=>1.0, 'chlorine_max'=>3.0, 'ph_min'=>7.0, 'ph_max'=>7.8,
        'modal_delay_minutes'=>5, 'modal_enabled'=>1, 'sound_enabled'=>1];
}

function get_alarm_config(mysqli $conn, int $tankId): array
{
    ensure_alarm_config_table($conn);
    $config = default_alarm_config();
    $stmt = $conn->prepare("SELECT chlorine_min, chlorine_max, ph_min, ph_max, modal_delay_minutes, modal_enabled, sound_enabled FROM controller_alarm_config WHERE tank_id=?");
    if (!$stmt) return $config;
    $stmt->bind_param('i', $tankId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? array_merge($config, $row) : $config;
}
