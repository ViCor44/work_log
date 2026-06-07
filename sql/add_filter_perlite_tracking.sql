ALTER TABLE filter_equipment
    ADD COLUMN last_perlite_change_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN last_charging_cycles FLOAT NULL DEFAULT NULL;
