-- Adiciona coluna de volume em m³ para piscinas
ALTER TABLE tanks
ADD COLUMN volume_m3 DECIMAL(8, 2) DEFAULT NULL
AFTER has_reject_counter;
