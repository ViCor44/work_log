-- Criação da tabela para notas associadas ao histórico do controlador
CREATE TABLE IF NOT EXISTS controller_history_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_id INT NOT NULL,
    log_datetime DATETIME NOT NULL,
    note TEXT NOT NULL,
    user_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tank_time (tank_id, log_datetime),
    FOREIGN KEY (tank_id) REFERENCES tanks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);