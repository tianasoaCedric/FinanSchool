# FinanSchool

Application web de **comptabilité générale et d'analyse financière** destinée aux établissements scolaires. Elle suit le plan comptable PCG 2005.

## Fonctionnalités

- **Plan comptable** : classes, comptes, sous-comptes, rubriques et devises.
- **Saisie comptable** : journaux, pièces et écritures en partie double, modèles d'écriture, modes de paiement et amortissements. L'équilibre débit/crédit et l'exercice ouvert sont contrôlés à chaque saisie.
- **Import** d'écritures depuis des fichiers.
- **États financiers** : balance, grand livre, bilan actif et passif, compte de résultat (par nature et par fonction), tableau des flux de trésorerie et variation des capitaux propres.
- **Exercices comptables** : ouverture et clôture, avec des notifications automatiques.
- **Analyse financière** : axes, centres et codes analytiques, affectations, coûts et profits, comparatif annuel, effectifs d'élèves.
- **Indicateurs** : indicateurs généraux, de liquidité, de solvabilité, de rentabilité et pédagogiques, avec des niveaux d'alerte et un diagnostic.
- **Tableau de bord**, **simulation** et **assistant conversationnel** (chatbot).
- **Exports** en Excel et en PDF.
- **Notifications en temps réel**.

## Stack technique

| Partie | Technologies |
|---|---|
| Backend | Laravel 12 (PHP 8.2), Laravel Sanctum, Laravel Reverb / Pusher |
| Frontend | Vue 3, Vite 7, Pinia, Vue Router, Axios, SCSS |
| Graphiques et exports | Chart.js, ApexCharts, xlsx, jsPDF, html2pdf |
| Base de données | PostgreSQL 16 |
| Environnement | Docker Compose |

## Démarrage rapide

**Prérequis** : [Docker Desktop](https://www.docker.com/products/docker-desktop/). Aucune installation de PHP, Node ou PostgreSQL n'est nécessaire.

```bash
git clone <url-du-depot>
cd FinanSchool
docker compose up --build
```

| Service | Adresse |
|---|---|
| Frontend | http://localhost:5173 |
| API backend | http://localhost:8000/api |
| PostgreSQL | `localhost:5433`, base `finance` (utilisateur `postgres` / mot de passe `postgres`) |

Au **premier démarrage**, la base est restaurée à partir de `Backend/export_base.sql` et de `docker/db/02-sync-migrations.sql`, puis les migrations Laravel sont appliquées. Pour repartir d'une base neuve :

```bash
docker compose down -v
docker compose up --build
```

Le service `scheduler` exécute les tâches planifiées de Laravel (`schedule:work`), comme les notifications de clôture d'exercice.

### Configuration

- **Backend** : `Backend/.env` (voir `Backend/.env.example`). Dans Docker, les variables de base de données sont fournies par `docker-compose.yml`.
- **Frontend** : l'URL de l'API est définie dans `Frontend/src/config/api.js`. Vous pouvez la remplacer avec la variable `VITE_API_URL`, par exemple dans `Frontend/.env`.

## Structure du projet

```
FinanSchool/
├── Backend/                  # API Laravel
│   ├── app/Http/Controllers/ # Contrôleurs par domaine (PlanCompte, Saisie, general, Analyse…)
│   ├── app/Models/           # Modèles Eloquent
│   ├── app/Services/         # Logique métier (Comptabilite/ : intégrité, états financiers, clôture…)
│   ├── routes/               # api.php, apiAnalyse.php, notifications*.php
│   └── tests/                # Tests PHPUnit
├── Frontend/                 # Application Vue 3
│   └── src/
│       ├── components/       # atoms / molecules / template / pages / ui
│       ├── composables/      # Appels API et logique réutilisable (use*.js)
│       ├── router/           # Routes (comptabilité + module analyse)
│       ├── services/         # Services front
│       └── stores/           # Stores Pinia
├── docker/                   # Image PHP et scripts d'initialisation de la base
├── Sql/                      # Scripts SQL de référence (plan comptable, données)
└── docker-compose.yml
```

## Tests

Lancez les tests comptables sur la base dédiée `finance_test` :

```bash
docker compose exec -T -e DB_DATABASE=finance_test backend php artisan test tests/Feature/Comptabilite
```

> ⚠️ **Ne lancez pas la suite complète (`php artisan test` sans chemin).** Certains tests réinitialisent la base de données. Sans `DB_DATABASE=finance_test`, ils videraient la base de développement `finance`.

## Build de production (frontend)

```bash
cd Frontend
npm install
npm run build   # génère Frontend/dist
```

## Branches

- `main` : branche principale (stable)
- `dev` : développement en cours
