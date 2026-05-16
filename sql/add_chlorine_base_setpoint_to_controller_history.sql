-- Adiciona coluna chlorine_base_setpoint à tabela controller_history.
-- Esta coluna guarda o SP base fixo definido pelo utilizador (excluindo os
-- offsets dinâmicos), para que a análise PID use o desvio real do cloro
-- em relação ao alvo pretendido, e não em relação ao SP dinâmico variável.
--
-- Executar UMA VEZ no servidor:
--   mysql -u <user> -p <db> < sql/add_chlorine_base_setpoint_to_controller_history.sql

ALTER TABLE controller_history
    ADD COLUMN IF NOT EXISTS chlorine_base_setpoint FLOAT NULL DEFAULT NULL
    COMMENT 'SP base fixo do utilizador (sem offsets dinâmicos). NULL = SP dinâmico inativo nesse ciclo.';
