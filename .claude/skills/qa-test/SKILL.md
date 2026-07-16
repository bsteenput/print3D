# Skill: qa-test

description: Fait tourner une batterie de tests de régression sur l'API Print3D en local (Docker), pour valider qu'un gros changement n'a rien cassé avant de committer.

## Quand l'invoquer

- Avant un commit qui touche à des zones sensibles (jobs, prix, stock, statuts, accès fichiers, auth) ou qui change plusieurs fichiers d'un coup.
- Quand l'utilisateur demande explicitement de "tout tester", "vérifier que rien n'est cassé", ou invoque `/qa-test`.
- En complément du test manuel dans le navigateur exigé par CLAUDE.md — ce skill couvre le backend (API) de façon exhaustive et reproductible, le test navigateur reste nécessaire pour l'UI/UX.

## Comportement

1. **Vérifier l'environnement Docker** :
   - `docker compose ps` — si les containers ne tournent pas, les démarrer avec `docker compose up -d --build` et attendre qu'ils soient `healthy`.
   - Si des migrations n'ont pas été appliquées (nouvelle migration ajoutée récemment), les appliquer :
     `docker exec -e DB_HOST=db -e DB_NAME=print3d -e DB_USER=print3d_user -e DB_PASS=print3d_pass print3d-php-1 php docker/migrate.php`
   - Note : le `Dockerfile` de dev (`docker/Dockerfile`, utilisé par `docker-compose.yml`) n'exécute PAS `docker/entrypoint.sh` automatiquement — contrairement à la prod (`Dockerfile` racine), les migrations ne s'appliquent pas toutes seules au démarrage en local. Il faut les lancer manuellement comme ci-dessus.

2. **S'assurer d'un mot de passe admin connu** — le hash placeholder de `database.sql` n'est pas utilisable tel quel :
   ```
   docker exec print3d-php-1 php docker/reset_password.php bertrand@example.com <mdp_temporaire>
   ```
   (Ceci ne touche que la base Docker locale, jamais la prod.)

3. **Lancer la suite de tests** :
   ```
   QA_ADMIN_PASSWORD=<mdp_temporaire> ./tools/qa_test.sh
   ```
   Le script (`tools/qa_test.sh`) :
   - crée ses propres données de test (client, printer, filament, jobs, fichier STL...) avec des emails uniques par run,
   - couvre : auth (login valide/invalide), clients, imprimantes, matériaux, settings, cycle de vie complet d'un job (création → statuts → items → stock → paiement → cadeau → suivi public → galerie), upload/téléchargement de fichiers avec contrôle d'accès, file d'attente (`/jobs/queue`, `/jobs/reorder`), dashboard, stats, et les refus d'accès admin-only pour un compte client,
   - **nettoie tout automatiquement à la fin**, même en cas d'échec (trap sur EXIT),
   - sort avec le code 0 si tout passe, 1 sinon.

4. **Lire le résultat** :
   - Si tout passe (`RESULTS: N passed, 0 failed`), le confirmer brièvement à l'utilisateur.
   - Si des tests échouent, avant de conclure à un bug applicatif, vérifier que ce n'est pas le script de test lui-même qui est en cause (endpoint mal orthographié, format de champ multipart incorrect, etc.) en reproduisant le test en isolation avec `curl` et en lisant la route PHP concernée. Ne rapporter comme bug réel que ce qui est confirmé en isolant la requête HTTP exacte qui échoue.
   - Résumer les résultats de façon concise (tableau ou liste courte), pas besoin de coller tout l'output brut du script à l'utilisateur.

5. **Ne jamais committer** avec des tests en échec sans en discuter avec l'utilisateur d'abord — CLAUDE.md exige un test extensif avant tout commit.

## Limites connues

- Ce skill teste l'API (backend) de façon automatisée. Il ne remplace pas un test visuel du frontend dans un navigateur (drag & drop, rendu STL, responsive mobile...) — pas d'outil de navigateur automatisé disponible dans cet environnement. Le signaler à l'utilisateur si le changement touche fortement l'UI.
- Le script suppose que `docker compose port php 80` retourne un port valide (containers démarrés via `docker-compose.yml` à la racine du repo).
- Étendre `tools/qa_test.sh` au fur et à mesure que de nouvelles fonctionnalités backend sont ajoutées, pour que la routine reste représentative de l'état réel de l'app.
