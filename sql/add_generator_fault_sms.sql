-- Preferência independente para SMS de avaria dos geradores LoRa.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS receive_sms_generator_fault TINYINT(1) NOT NULL DEFAULT 1
    AFTER receive_sms_equipment_off;
