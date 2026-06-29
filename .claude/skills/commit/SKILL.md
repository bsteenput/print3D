# Skill: commit

description: Analyse les changements en cours et crée un commit git avec un message bien formaté en français.

## Comportement

Quand l'utilisateur invoque `/commit` (avec ou sans argument) :

1. Lance en parallèle :
   - `git status` pour voir les fichiers modifiés/non suivis
   - `git diff HEAD` pour voir les changements détaillés
   - `git log --oneline -5` pour s'inspirer du style des commits existants

2. Analyse les changements et détermine :
   - Le **type** : `feat`, `fix`, `refactor`, `docs`, `chore`, `test`, `style`
   - Le **scope** optionnel entre parenthèses si pertinent : ex. `feat(jobs):`, `fix(auth):`
   - Un **titre** court et précis (50 chars max), en français, à l'impératif
   - Un **corps** de 2-4 lignes expliquant le *pourquoi* et les points clés, si les changements sont non triviaux

3. Stage les fichiers pertinents (jamais `.env`, `config.local.php`, `uploads/`, fichiers de secrets)

4. Crée le commit avec le format :
   ```
   type(scope): titre court en français

   - Point clé 1
   - Point clé 2

   Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
   ```

5. Si l'utilisateur a passé un argument à `/commit`, l'utiliser comme guidance pour le message (ex. `/commit fix du bug de stock`).

## Règles

- Toujours vérifier qu'il y a des changements avant de committer
- Ne jamais inclure `uploads/`, `*.local.php`, `.env` dans le commit
- Si plusieurs features indépendantes sont modifiées, le signaler et demander si un seul commit ou plusieurs
- Préférer un titre précis à un titre générique ("maj jobs" est mauvais, "ajoute la déduction de stock à la complétion" est bon)
- Le corps est optionnel pour les changements évidents (ex. fix typo)
