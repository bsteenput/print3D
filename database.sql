-- ============================================================
--  Print3D — Schéma MySQL
--  Compatible MySQL 8+ / MariaDB 10.6+
--  Infomaniak : créer la DB depuis le panel, puis importer ce fichier
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00';

-- ------------------------------------------------------------
--  Utilisateurs (admin + clients)
-- ------------------------------------------------------------
CREATE TABLE users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    email       VARCHAR(180)  NULL UNIQUE,
    password    VARCHAR(255)  NULL,              -- bcrypt
    role        ENUM('admin','client') NOT NULL DEFAULT 'client',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login  DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Imprimantes
-- ------------------------------------------------------------
CREATE TABLE printers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)   NOT NULL,           -- ex: "Ender 3 Pro"
    active      TINYINT(1)    NOT NULL DEFAULT 1,
    notes       TEXT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Bobines de filament
-- ------------------------------------------------------------
CREATE TABLE filaments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    material        VARCHAR(20)   NOT NULL,       -- PLA, PETG, TPU…
    color           VARCHAR(50)   NOT NULL,       -- Blanc, Noir…
    color_hex       CHAR(7)       NULL,           -- #FFFFFF
    brand           VARCHAR(80)   NULL,
    price_per_kg    DECIMAL(6,2)  NOT NULL,       -- €/kg
    stock_grams     INT UNSIGNED  NOT NULL DEFAULT 0,
    active          TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Jobs d'impression
-- ------------------------------------------------------------
CREATE TABLE jobs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(20)   NOT NULL UNIQUE,   -- JOB-0042
    client_id       INT UNSIGNED  NOT NULL,
    printer_id      INT UNSIGNED  NULL,
    filament_id     INT UNSIGNED  NULL,

    title           VARCHAR(200)  NOT NULL,
    description     TEXT          NULL,
    quantity        SMALLINT      NOT NULL DEFAULT 1,

    -- Statut
    status          ENUM(
                        'draft',       -- brouillon admin
                        'queued',      -- en file d'attente
                        'printing',    -- en cours
                        'done',        -- terminé, pas encore récupéré
                        'picked_up',   -- récupéré par le client
                        'cancelled'
                    ) NOT NULL DEFAULT 'queued',

    -- Impression
    layer_current   INT UNSIGNED  NULL,
    layer_total     INT UNSIGNED  NULL,
    grams_used      DECIMAL(7,2)  NULL,
    print_hours     DECIMAL(5,2)  NULL,           -- durée réelle

    -- Temps
    eta             DATETIME      NULL,           -- fin estimée
    started_at      DATETIME      NULL,
    finished_at     DATETIME      NULL,
    picked_up_at    DATETIME      NULL,

    -- Prix
    price_auto      DECIMAL(7,2)  NULL,           -- calculé (filament + temps)
    price_final     DECIMAL(7,2)  NULL,           -- peut être écrasé manuellement
    price_adjusted  TINYINT(1)    NOT NULL DEFAULT 0,  -- 1 si modifié manuellement

    -- Machine
    hourly_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0.80,  -- snapshot au moment du job

    -- Notes internes (non visibles par le client)
    notes_admin     TEXT          NULL,

    queue_order     INT UNSIGNED  NOT NULL DEFAULT 0,  -- ordre dans la file

    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (client_id)   REFERENCES users(id),
    FOREIGN KEY (printer_id)  REFERENCES printers(id) ON DELETE SET NULL,
    FOREIGN KEY (filament_id) REFERENCES filaments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Fichiers STL attachés à un job
-- ------------------------------------------------------------
CREATE TABLE job_files (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      INT UNSIGNED  NOT NULL,
    filename    VARCHAR(255)  NOT NULL,           -- nom original
    relative_path VARCHAR(500) NULL,               -- chemin dans le dossier uploadé (ex: 28mm/supp/x.stl)
    path        VARCHAR(500)  NOT NULL,           -- chemin relatif sur le serveur
    size_bytes  INT UNSIGNED  NULL,
    uploaded_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Objets individuels à imprimer dans un job
-- ------------------------------------------------------------
CREATE TABLE job_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      INT UNSIGNED  NOT NULL,
    file_id     INT UNSIGNED  NULL,                  -- STL lié (optionnel)
    name        VARCHAR(200)  NOT NULL,
    quantity    SMALLINT      NOT NULL DEFAULT 1,
    status      ENUM('pending','printing','done','failed') NOT NULL DEFAULT 'pending',
    notes       TEXT          NULL,
    sort_order  INT UNSIGNED  NOT NULL DEFAULT 0,

    FOREIGN KEY (job_id)  REFERENCES jobs(id)       ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES job_files(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Historique des changements de statut (timeline client)
-- ------------------------------------------------------------
CREATE TABLE job_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      INT UNSIGNED  NOT NULL,
    status      VARCHAR(50)   NOT NULL,
    message     VARCHAR(255)  NULL,               -- message optionnel affiché au client
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Paramètres globaux (taux horaire par défaut, etc.)
-- ------------------------------------------------------------
CREATE TABLE settings (
    key_name    VARCHAR(80)   PRIMARY KEY,
    value       VARCHAR(500)  NOT NULL,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (key_name, value) VALUES
    ('hourly_rate',       '0.80'),
    ('app_name',          'Print3D'),
    ('contact_email',     'bertrand@example.com'),
    ('notify_on_status',  '1');           -- envoyer email au client à chaque changement

-- ------------------------------------------------------------
--  Tentatives de connexion (rate limiting anti brute-force)
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Données de démo
-- ------------------------------------------------------------
INSERT INTO printers (name) VALUES ('Ender 3 Pro'), ('Bambu A1');

INSERT INTO filaments (material, color, color_hex, price_per_kg, stock_grams) VALUES
    ('PLA',  'Blanc',    '#F5F5F0', 22.00, 980),
    ('PLA',  'Rouge',    '#C0392B', 22.00, 450),
    ('PLA',  'Noir',     '#1A1A1A', 22.00, 750),
    ('PETG', 'Noir',     '#1A1A1A', 26.00, 820),
    ('PETG', 'Gris',     '#888888', 26.00, 310),
    ('TPU',  'Noir',     '#1A1A1A', 32.00, 200);

-- Admin
INSERT INTO users (name, email, password, role) VALUES
    ('Bertrand', 'bertrand@example.com',
     '$2y$12$placeholderHashRemplacerAvecPhpPasswordHash', 'admin');
