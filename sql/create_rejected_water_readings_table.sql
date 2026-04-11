-- Criação da tabela para leituras do contador de água rejeitada
CREATE TABLE IF NOT EXISTS rejected_water_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_id INT NOT NULL,
    user_id INT NOT NULL,
    reading_datetime DATETIME NOT NULL,
    meter_value INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tank_datetime (tank_id, reading_datetime),
    KEY user_id (user_id),
    FOREIGN KEY (tank_id) REFERENCES tanks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
