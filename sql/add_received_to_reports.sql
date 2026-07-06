-- Adiciona colunas para registar a receção do relatório pelo administrador
ALTER TABLE `reports`
    ADD COLUMN `received` TINYINT(1) NOT NULL DEFAULT 0 AFTER `printed`,
    ADD COLUMN `received_by` INT(11) NULL DEFAULT NULL AFTER `received`,
    ADD COLUMN `received_at` DATETIME NULL DEFAULT NULL AFTER `received_by`;
