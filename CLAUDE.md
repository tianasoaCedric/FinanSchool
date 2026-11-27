# FinanSchool — contexte projet

Application de **comptabilité générale et d'analyse financière multi-secteur** (entreprises, associations, établissements scolaires… ; plan comptable PCG 2005, Madagascar). Le nom et certains modules (indicateurs pédagogiques, effectif élèves) viennent de l'origine scolaire du projet, mais l'application ne s'y limite pas. Langue du code, des commentaires, de l'UI et des échanges : **français**.

## Stack
- **Backend/** : Laravel 12 (PHP 8.2), Sanctum (auth par jeton Bearer), Reverb + Pusher (temps réel / notifications), PostgreSQL 16.
- **Frontend/** : Vue 3 + Vite 7, Pinia, vue-router, axios, SCSS, Chart.js / ApexCharts, exports xlsx / jsPDF / html2pdf, laravel-echo.
- **docker/** : image PHP (`docker/php`) et scripts d'init DB (`docker/db/02-sync-migrations.sql`).
- **Sql/**, fichiers `.csv` / `KPI *.txt` / `Formules.txt` à la racine : données de référence (plan comptable, axes/centres analytiques, formules des KPI). Pas du code applicatif.

## Lancer le projet (Docker uniquement)
- `docker compose up --build` → frontend http://localhost:5173, backend http://localhost:8000, Postgres `localhost:5433` (postgres/postgres, base `finance`).
- `docker` peut ne pas être dans le PATH : ajouter `%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin`.
- Le backend tourne avec `php -S` depuis `public/` (pas `artisan serve`, qui ignore DB_HOST). Un service `scheduler` exécute `schedule:work`.
- La base est restaurée depuis `Backend/export_base.sql` + `02-sync-migrations.sql` au premier démarrage (volume vide), puis `migrate`. Elle **ne peut pas** être reconstruite par les migrations seules.
- Attention à la casse des chemins (Linux dans les conteneurs, Windows sur le poste).

## Tests (IMPORTANT)
- Uniquement : `docker compose exec -T -e DB_DATABASE=finance_test backend php artisan test tests/Feature/Comptabilite`
- **Ne jamais lancer la suite complète** : `Tests\Unit\ChatMessageTest` utilise RefreshDatabase → efface toute la base (et sans `DB_DATABASE=finance_test`, la base de dev `finance`).

## Backend — organisation
- Routes : `routes/api.php` (comptabilité) qui inclut `routes/apiAnalyse.php` (module analyse) ; `notifications*.php`.
- Contrôleurs par domaine dans `app/Http/Controllers/` : `PlanCompte` (classes, comptes, rubriques, sous-comptes, devises), `Saisie` (journaux, pièces, mouvements/lignes d'écriture, modèles, amortissements, grand livre), `general` (balance, bilan actif/passif, compte de résultat nature/fonction, flux de trésorerie, variation des capitaux, contrôles), `exercice` (exercices, clôture), `import`, `Export`, `tableau` (dashboard), `Analyse` + `ParametresAnalytique` (analytique, indicateurs, diagnostic), `ChatBot`, `Simulation`, `notifications`, `Auth`.
- Modèles dans `app/Models/<Domaine>/` (mêmes sous-dossiers que les contrôleurs).
- Logique métier comptable centralisée dans `app/Services/Comptabilite/` :
  - `EcritureGuard` : **point unique** des règles d'intégrité des écritures (partie double, équilibre à 0,01 près, exercice ouvert, statuts `brouillon`/`valide`) ; lève une `ValidationException` (422). Toute saisie / validation / import doit passer par lui.
  - `EtatsFinanciers`, `LivresComptables`, `ClotureService`, `PeriodesService`, `DotationsService`, `AffectationService`, `ComptesSysteme`.
- Écritures multi-tables faites dans `DB::transaction`.
- Commandes planifiées (`app/Console/Commands`) : notifications de clôture/ouverture d'exercice, import, comptes non affectés.

## Frontend — organisation
- URL API unique : `src/config/api.js` (`API_BASE`, surchargeable par `VITE_API_URL`). `src/config/http.js` ajoute le Bearer et redirige vers `/` sur 401.
- Routes : `src/router/index.js` (lazy-load) + `src/router/routesAnalyse.js` (module analyse).
- Composants en atomic design : `components/atoms`, `molecules`, `template`, `pages/<module>`, `ui` (`UiButton`, `UiBadge` — à utiliser pour les boutons/badges des modules hors analyse).
- Logique d'appel API dans `src/composables/use*.js` et `src/services/` ; store Pinia dans `src/stores/`.
- SCSS : variables/mixins injectés automatiquement depuis `@/assets/styles/index` (ne pas mettre de CSS généré dedans). Alias `@` = `src/`.

## Règles à respecter
- **Ne jamais modifier l'interface du module d'analyse financière** : routes de `routesAnalyse.js` (/analyse, /couts, /profits, /comparatif, /indicateur-*, /non-affected, /code-analytique, /parametres, /effectif-eleve, /GetStarted) et leurs composants (`pages/analyses`, `pages/indicateurs`, `pages/configAnalytique`, `pages/loginAnalyse`, `template/Page-analyse.vue`, `molecules/Analyse/*`). Avant de restyler un composant partagé ou du CSS global, vérifier qu'il n'est pas rendu par ces pages.
- Les routes API du module analyse restent dans `apiAnalyse.php`, inchangées.
- Branche de travail : `dev` ; branche principale : `main`.
