CREATE TABLE IF NOT EXISTS `hipoclorito_diario` (
    `id`                int(11) NOT NULL AUTO_INCREMENT,
    `tank_id`           int(11) NOT NULL,
    `data_referencia`   date NOT NULL COMMENT 'Dia a que se refere (9:00 desse dia -> 9:00 dia seguinte)',
    `hora_inicio`       datetime NOT NULL,
    `hora_fim`          datetime NOT NULL,
    `integral_dosagem`  float NOT NULL COMMENT 'Integral trapezoidal de cl_controller_state em %-hora',
    `qmax_lh`           float NOT NULL COMMENT 'Caudal maximo da bomba em L/h usado no calculo',
    `consumo_estimado_l` float NOT NULL COMMENT 'Estimativa de consumo hipoclorito em litros',
    `n_registos`        int(11) NOT NULL DEFAULT 0 COMMENT 'Numero de registos usados no calculo',
    `created_at`        timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_tank_data` (`tank_id`, `data_referencia`),
    KEY `idx_tank_id` (`tank_id`),
    KEY `idx_data_referencia` (`data_referencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Estimativas diarias de consumo de hipoclorito por tanque';
