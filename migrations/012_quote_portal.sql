-- ============================================================
--  Migration 012 : Portail public de devis
--  - Nouveau statut 'quote' (demande de devis à traiter)
--  - Table quote_attempts pour le rate limiting du formulaire public
-- ============================================================

ALTER TABLE jobs
  MODIFY COLUMN status ENUM(
      'quote',       -- demande de devis reçue via le portail public
      'draft',       -- brouillon admin
      'queued',      -- en file d'attente
      'printing',    -- en cours
      'done',        -- terminé, pas encore récupéré
      'picked_up',   -- récupéré par le client
      'cancelled'
  ) NOT NULL DEFAULT 'queued';

CREATE TABLE IF NOT EXISTS quote_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
