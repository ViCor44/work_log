-- Índice usado pelos gráficos, gauges e monitorização de controladores.
ALTER TABLE controller_history
    ADD INDEX IF NOT EXISTS idx_controller_history_tank_time (tank_id, log_datetime);
