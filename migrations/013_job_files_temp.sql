-- Fichiers de travail (brouillons) : STL sources uploadés temporairement pour être
-- combinés/vérifiés (ex: UVTools pour la résine) avant l'upload du fichier final.
ALTER TABLE job_files ADD COLUMN is_temp TINYINT(1) NOT NULL DEFAULT 0 AFTER filename;
