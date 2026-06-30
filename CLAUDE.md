# Print3D

Application de gestion d'impressions 3D (FDM et résine) pour un usage personnel. Bertrand gère ses propres imprimantes et enregistre les jobs pour lui-même et ses proches (famille, amis). Pas d'usage professionnel ni de comptabilité.

## Stack

- **Backend** : PHP 8.4, pas de framework — un router maison dans `api/index.php`
- **Base de données** : MySQL 8 / MariaDB 10.6+
- **Frontend** : SPA vanilla JS dans `public/js/app.js` (un seul fichier), HTML dans `public/index.html`
- **Auth** : JWT maison (HS256, sans librairie), deux rôles : `admin` et `client`
- **Déploiement** : Docker (PHP-Apache), hébergé sur Coolify

## Structure

```
api/
  index.php          — router, dispatch vers routes/
  helpers.php        — JWT, auth, calc_price_auto(), handle_stl_upload(), notify_client_status()
  routes/            — un fichier par ressource (jobs, filaments, clients, printers, settings, dashboard, monitor, files, auth)
config/
  config.php         — constantes (DB, JWT_SECRET, MAIL_*, APP_URL, UPLOAD_DIR, MAX_FILE_SIZE)
  config.local.php   — surcharge locale (ignorée par Docker, non versionnée)
  db.php             — singleton PDO via db()
docker/
  entrypoint.sh      — lance migrate.php puis apache2-foreground
  migrate.php        — applique les migrations SQL manquantes au démarrage
migrations/
  NNN_nom.sql        — migrations numérotées, appliquées une seule fois
public/
  index.html         — shell HTML + nav
  js/app.js          — toute l'UI (router hash, vues, modals)
  css/app.css
uploads/             — fichiers STL/3MF uploadés (persisté via volume Docker)
database.sql         — schéma initial complet (référence, pas ré-exécuté en prod)
```

## Migrations SQL

### Règles à suivre absolument

1. **Ne jamais modifier un fichier `.sql` existant** dans `migrations/`. Le système de migration (`docker/migrate.php`) enregistre chaque fichier appliqué dans la table `schema_migrations` par nom de fichier — une fois appliqué, il n'est plus jamais rejoué.

2. **Toujours créer un nouveau fichier** avec le prochain numéro : `migrations/004_nom.sql`, `005_nom.sql`, etc.

3. **Nommage** : `NNN_description_courte.sql` où `NNN` est un entier sur 3 chiffres, en ordre croissant strict. Le tri est alphabétique donc le zéro-padding est obligatoire.

4. **Les migrations s'exécutent au démarrage du container** — elles doivent être idempotentes ou utiliser `IF NOT EXISTS` / `IF EXISTS` quand c'est possible.

5. **`database.sql` est la référence du schéma initial**, pas une migration. Il n'est pas appliqué automatiquement en prod — seulement lors d'une installation from scratch.

### Exemple de migration correcte

```sql
-- migrations/004_photos.sql
ALTER TABLE jobs ADD COLUMN photos_count TINYINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS job_photos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id     INT UNSIGNED NOT NULL,
    filename   VARCHAR(255) NOT NULL,
    path       VARCHAR(500) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Conventions de code

### API (PHP)

- Chaque route PHP reçoit `$method`, `$id`, `$sub`, `$sub_id` depuis le router — ne pas re-parser `$_SERVER['REQUEST_URI']`.
- Toujours terminer par `json_ok()` ou `json_err()` (elles appellent `exit`).
- Utiliser `body()` pour lire le JSON du body POST/PUT.
- Les requêtes SQL avec des variables utilisateur passent par des `prepare()`/`execute()` — jamais d'interpolation directe sauf pour des entiers castés `(int)`.
- `$is_admin` est déjà calculé en haut de chaque route.

### Frontend (JS)

- Tout est dans `app.js`. Pas de build, pas de bundler.
- Les fonctions globales appelées depuis le HTML inline (`onclick="..."`) sont exposées via `window.nomFonction`.
- Les données temporaires inter-fonctions passent par `window._xxx` (ex: `window._editJobFilaments`).
- `esc()` obligatoire sur toute valeur affichée dans le HTML généré.
- `openModal(title, bodyHtml, actions)` pour toutes les modals — jamais de `<dialog>` natif.

## Calcul du prix automatique

`calc_price_auto(qty, hours, price_per_unit, hourly_rate)` dans `helpers.php` :

- **FDM** : `qty` = grammes, `price_per_unit` = `price_per_kg` → coût = `(grams/1000) × price_per_kg + hours × hourly_rate`
- **Résine** : `qty` = ml, `price_per_unit` = `price_per_litre` → coût = `(ml/1000) × price_per_litre + hours × hourly_rate`

Le `print_type` (`fdm` ou `resin`) est stocké à la fois sur le job et sur le matériau.

## Workflow de développement

- **Autonomie** : tu peux réfléchir et implémenter de manière autonome sans demander de validation à chaque étape.
- **Test obligatoire avant tout commit** : avant de créer un commit, tester l'implémentation de manière extensive sur le site qui tourne en local — tester le chemin nominal ET les cas limites, vérifier qu'il n'y a pas de régression dans les fonctionnalités existantes.
- **Commits réguliers** : committer dès qu'une fonctionnalité ou une correction est stable et testée. Ne jamais accumuler trop de changements non commités.
- **Ne jamais pousser** : laisser Bertrand pousser les commits vers le remote. Ne pas exécuter `git push`.

## Déploiement (Coolify)

- Le container lit les variables d'environnement `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`, `APP_URL`, `MAIL_FROM`, `MAIL_FROM_NAME`.
- Les migrations s'appliquent automatiquement à chaque redémarrage — un nouveau fichier `migrations/NNN_xxx.sql` est donc suffisant pour mettre à jour le schéma en prod.
- Le dossier `uploads/` est monté en volume pour persister les fichiers entre les rebuilds.
- **Piège connu** : si `database.sql` n'a pas été importé manuellement avant le premier démarrage, le login renvoie une 500 (les tables n'existent pas). Solution : importer `database.sql` depuis le panel Coolify/phpMyAdmin avant le premier déploiement.
