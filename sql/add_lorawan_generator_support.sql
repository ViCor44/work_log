-- Suporte a monitores de gerador com estado ON/OFF e contacto de avaria.
ALTER TABLE lorawan_devices
    ADD COLUMN device_type VARCHAR(20) NOT NULL DEFAULT 'osmosis' AFTER dev_eui,
    ADD COLUMN fault_status VARCHAR(10) NULL AFTER equipment_status;

-- Adapta dispositivos já registados com nomes como "Gerador 1".
UPDATE lorawan_devices
SET device_type = 'generator'
WHERE LOWER(name) LIKE 'gerador%';
