-- Adiciona flag para indicar se uma piscina tem contador de rejeitado
ALTER TABLE tanks
ADD COLUMN has_reject_counter TINYINT(1) NOT NULL DEFAULT 0
AFTER requires_analysis;
