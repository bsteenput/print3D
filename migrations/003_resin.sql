-- ============================================================
--  Migration 003 : Support impression résine
--  Ajoute print_type + price_per_litre sur filaments
--  Ajoute print_type + ml_used sur jobs
-- ============================================================

ALTER TABLE filaments
  MODIFY COLUMN price_per_kg    DECIMAL(6,2) NULL,
  ADD COLUMN print_type         ENUM('fdm','resin') NOT NULL DEFAULT 'fdm' AFTER material,
  ADD COLUMN price_per_litre    DECIMAL(6,2) NULL AFTER price_per_kg;

ALTER TABLE jobs
  ADD COLUMN print_type ENUM('fdm','resin') NOT NULL DEFAULT 'fdm' AFTER filament_id,
  ADD COLUMN ml_used    DECIMAL(7,2)        NULL                   AFTER grams_used;
