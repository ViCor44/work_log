-- =====================================================================
-- Tabelas de suporte ao envio de SMS de alarme via modem Teltonika
-- Execução: importar em phpMyAdmin ou via CLI (mysql -u root cmms < ...)
-- =====================================================================

-- Log de todos os SMS tentados (sucesso e falha).
CREATE TABLE IF NOT EXISTS `sms_log` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `ts`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `to_number`  VARCHAR(32) NOT NULL,
    `message`    TEXT NOT NULL,
    `status`     VARCHAR(16) NOT NULL,           -- 'sent', 'failed', 'skipped'
    `response`   TEXT NULL,                      -- resposta bruta do modem / erro
    `tank_id`    INT NULL,
    `alarm_type` VARCHAR(64) NULL,
    INDEX `idx_ts` (`ts`),
    INDEX `idx_tank_alarm` (`tank_id`, `alarm_type`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Estado atual de cada tipo de alarme por tanque.
-- Serve para detetar transições OK -> ALARME (edge detection) e
-- para debounce entre envios sucessivos do mesmo alarme.
CREATE TABLE IF NOT EXISTS `controller_alarm_state` (
    `tank_id`         INT NOT NULL,
    `alarm_type`      VARCHAR(64) NOT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 0,
    `first_active_at` TIMESTAMP NULL DEFAULT NULL,
    `last_seen_at`    TIMESTAMP NULL DEFAULT NULL,
    `last_sms_at`     TIMESTAMP NULL DEFAULT NULL,
    `last_cleared_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`tank_id`, `alarm_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Flag por utilizador — quem recebe SMS de alarmes.
-- Se a coluna já existir num setup MariaDB antigo (<10.0.2 sem IF NOT EXISTS),
-- o ALTER pode ser executado manualmente uma única vez.
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `receive_sms_alarms` TINYINT(1) NOT NULL DEFAULT 0;

-- Preferências SMS por utilizador (granularidade por tipo de alarme)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `receive_sms_controller` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `receive_sms_chemical` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `receive_sms_lora_offline` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `receive_sms_equipment_off` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `sms_alarm_min_minutes` INT NOT NULL DEFAULT 17;
