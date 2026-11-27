<?php

// Routes du module Analyse : inchangées (fichier séparé)
require __DIR__ . '/apiAnalyse.php';

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\PlanCompte\ClasseController;
use App\Http\Controllers\PlanCompte\CompteController;
use App\Http\Controllers\PlanCompte\RubriqueController;
use App\Http\Controllers\PlanCompte\SousCompteController;
use App\Http\Controllers\Saisie\TypeJournalController;
use App\Http\Controllers\Saisie\ModePaiementController;
use App\Http\Controllers\Saisie\JournalController;
use App\Http\Controllers\Saisie\MouvementEcritureController;
use App\Http\Controllers\Saisie\LigneEcritureController;
use App\Http\Controllers\Saisie\PieceController;
use App\Http\Controllers\Saisie\ModeleEcritureController;
use App\Http\Controllers\Saisie\DeviseController;
use App\Http\Controllers\Saisie\GrandLivreController;
use App\Http\Controllers\general\BalanceController;
use App\Http\Controllers\calcul\IntervalleComptesCategorieController;
use App\Http\Controllers\calcul\ComptesCategorieController;
use App\Http\Controllers\calcul\UtilesController;
use App\Http\Controllers\general\CompteResultatNatureController;
use App\Http\Controllers\general\CompteResultatFonctionController;
use App\Http\Controllers\exercice\ExerciceComptableController;
use App\Http\Controllers\general\BilanActifController;
use App\Http\Controllers\general\BilanPassifController;
use App\Http\Controllers\general\FluxTresorerieController;
use App\Http\Controllers\general\VariationsCapitauxController;
use App\Http\Controllers\import\ImportEcritureController;
use App\Http\Controllers\tableau\DashboardController;
use App\Http\Controllers\Saisie\AmortissementController;
use App\Http\Controllers\ChatBot\AssistantController;
use App\Http\Controllers\notifications\NotificationController;
use App\Http\Controllers\notifications\NotificationsController;
use App\Http\Controllers\notifications\EvenementController;
use App\Http\Controllers\Simulation\SimulationController;
use App\Http\Controllers\exercice\ClotureController;

Route::middleware('api')->group(function () {

    // =====================================================================
    // Routes publiques : authentification et utilitaires
    // =====================================================================
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/request-verification', [AuthController::class, 'requestVerification']);
    Route::post('/verify-code', [AuthController::class, 'verifyCode']);
    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])
        ->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
    Route::get('password/reset/{token}', [ForgotPasswordController::class, 'showResetForm'])
        ->name('password.reset');

    Route::get('/errors/{id}', [AuthController::class, 'getErrorMessage']);
    Route::get('/test', fn () => response()->json(['message' => 'API is working']));
    Route::post('/example', fn (Request $request) => response()->json(['message' => 'POST request received']));

    // =====================================================================
    // Routes protégées : utilisateur connecté obligatoire
    // =====================================================================
    Route::middleware(['auth:sanctum', 'acces'])->group(function () {

        // ---- Assistant (chatbot) : il lit des données comptables ----
        Route::post('/chat/sending', [AssistantController::class, 'comptabilite']);
        Route::post('/chat/send', [AssistantController::class, 'analyse']);
        Route::get('/chat/history/{sessionId}', [AssistantController::class, 'historique']);
        Route::get('/chat/conversations', [AssistantController::class, 'conversations']);
        Route::delete('/chat/conversations/{sessionId}', [AssistantController::class, 'supprimer']);

        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // ---- Plan comptable et référentiels ----
        // Import / export du plan comptable (classes, rubriques, comptes, sous-comptes)
        Route::post('/plan-comptable/import', [\App\Http\Controllers\PlanCompte\ImportPlanComptableController::class, 'import']);
        Route::get('/plan-comptable/export', [\App\Http\Controllers\PlanCompte\ImportPlanComptableController::class, 'export']);
        Route::apiResource('comptes', CompteController::class);
        Route::apiResource('sous-comptes', SousCompteController::class);
        Route::apiResource('rubriques', RubriqueController::class);
        Route::apiResource('classes', ClasseController::class);

        Route::get('/devises', [DeviseController::class, 'index'])->name('devises.index');
        Route::post('/devises', [DeviseController::class, 'store'])->name('devises.store');
        Route::get('/devises/{id}', [DeviseController::class, 'show'])->name('devises.show');
        Route::put('/devises/{id}', [DeviseController::class, 'update'])->name('devises.update');
        Route::delete('/devises/{id}', [DeviseController::class, 'destroy'])->name('devises.destroy');

        Route::apiResource('type-journals', TypeJournalController::class);
        Route::apiResource('mode-paiements', ModePaiementController::class);
        Route::get('journals/{id}/ecritures', [JournalController::class, 'ecritures']);
        Route::apiResource('journals', JournalController::class);

        // ---- Tiers (clients, fournisseurs…) et lettrage ----
        Route::get('/tiers/prochain-code', [\App\Http\Controllers\Tiers\TiersController::class, 'prochainCode']);
        Route::get('/tiers-balance-agee', [\App\Http\Controllers\Tiers\TiersController::class, 'balanceAgee']);
        Route::get('/tiers/{id}/releve', [\App\Http\Controllers\Tiers\TiersController::class, 'releve'])->whereNumber('id');
        Route::apiResource('tiers', \App\Http\Controllers\Tiers\TiersController::class)->parameters(['tiers' => 'id']);
        Route::get('/lettrage/comptes', [\App\Http\Controllers\Tiers\LettrageController::class, 'comptes']);
        Route::get('/lettrage/lignes', [\App\Http\Controllers\Tiers\LettrageController::class, 'lignes']);
        Route::post('/lettrage/auto', [\App\Http\Controllers\Tiers\LettrageController::class, 'automatique']);
        Route::post('/lettrage', [\App\Http\Controllers\Tiers\LettrageController::class, 'store']);
        Route::delete('/lettrage/{id}', [\App\Http\Controllers\Tiers\LettrageController::class, 'destroy'])->whereNumber('id');

        // ---- Saisie par pièce complète et modèles d'écriture ----
        Route::get('/pieces/{id}/justificatif', [PieceController::class, 'justificatif']);
        Route::post('/pieces/{id}/contrepasser', [PieceController::class, 'contrepasser']);
        Route::apiResource('pieces', PieceController::class);
        Route::apiResource('modeles-ecriture', ModeleEcritureController::class)->except(['show']);

        // ---- Écran de validation des pièces ----
        Route::get('/validation/pieces', [\App\Http\Controllers\Saisie\ValidationController::class, 'index']);
        Route::post('/validation/valider', [\App\Http\Controllers\Saisie\ValidationController::class, 'valider']);

        // ---- Piste d'audit (lecture seule) ----
        Route::get('/audit/options', [\App\Http\Controllers\Saisie\AuditController::class, 'options']);
        Route::get('/audit', [\App\Http\Controllers\Saisie\AuditController::class, 'index']);

        // ---- Pièces (mouvements) ----
        Route::get('/mouvements-complets', [LigneEcritureController::class, 'getMouvementsComplets']);
        Route::delete('/mouvements/{id}', [LigneEcritureController::class, 'deleteMouvement']);
        Route::post('/mouvements/{id}/solder', [LigneEcritureController::class, 'solderMouvement']);
        Route::post('/mouvements/{id}/valider', [LigneEcritureController::class, 'validerMouvementComplet']);
        Route::post('/mouvements/{id}/valider-complet', [LigneEcritureController::class, 'validerMouvementComplet']);
        Route::apiResource('mouvements', MouvementEcritureController::class)->except(['destroy']);

        // ---- Lignes d'écriture et validation ----
        Route::post('/lignes/valider-toutes', [LigneEcritureController::class, 'validerToutesLesEcritures']);
        Route::post('/lignes/valider-periode', [LigneEcritureController::class, 'validerEcrituresPeriode']);
        Route::get('/lignes/rapport-validation', [LigneEcritureController::class, 'getRapportValidation']);
        Route::put('/lignes/batch-update', [LigneEcritureController::class, 'batchUpdate']);
        Route::post('/lignes-batch', [LigneEcritureController::class, 'saveLignesBatch']);
        Route::post('lignes/{id}/valider', [LigneEcritureController::class, 'valider']);
        Route::apiResource('lignes', LigneEcritureController::class);

        Route::get('/ecritures', [LigneEcritureController::class, 'indexWithRelations']);
        Route::get('/sous-comptes/search', [LigneEcritureController::class, 'searchSousComptes']);
        Route::get('/search-sous-comptes', [LigneEcritureController::class, 'searchSousComptes']);
        Route::get('/options-formulaires', [LigneEcritureController::class, 'getOptions']);

        // ---- Import ----
        Route::get('/ecritures/imports/historique', [ImportEcritureController::class, 'historique']);
        Route::post('/ecritures/import', [ImportEcritureController::class, 'import']);
        Route::post('/ecritures/import/validate', [ImportEcritureController::class, 'validateImport']);
        Route::post('/ecritures/import/analyse', [ImportEcritureController::class, 'analyse']);
        Route::post('/ecritures/import/lignes', [ImportEcritureController::class, 'importLignes']);
        Route::post('/ecritures/imports/{id}/annuler', [ImportEcritureController::class, 'annuler'])->whereNumber('id');

        // ---- Grand livre et balance ----
        Route::get('/grand-livre', [GrandLivreController::class, 'index']);
        Route::get('/grand-livre/compte/{codeCompte}', [GrandLivreController::class, 'getByCompte']);
        Route::get('/grand-livre/{codeCompte}/{codeSousCompte?}', [GrandLivreController::class, 'show']);
        Route::get('/compte/{codeCompte}/ecritures', [GrandLivreController::class, 'getEcrituresCompte']);
        Route::get('/compte/{codeCompte}/ecritures-simple', [GrandLivreController::class, 'getEcrituresCompteSimple']);

        Route::get('/balance-generale', [BalanceController::class, 'index']);
        Route::get('/balance-generale/{codeSousCompte}', [BalanceController::class, 'show']);

        // ---- Catégories de comptes ----
        Route::apiResource('/intervalle-comptes-categorie', IntervalleComptesCategorieController::class);
        Route::apiResource('/compte-categories', ComptesCategorieController::class);
        Route::post('/assigner-toutes-automatiquement', [ComptesCategorieController::class, 'assignerToutesCategoriesAutomatiquement']);
        Route::get('/somme-categorie', [UtilesController::class, 'getSommeParCategorie']);

        // ---- Exercices comptables ----
        Route::prefix('exercices')->group(function () {
            Route::get('/', [ExerciceComptableController::class, 'index']);
            Route::get('/ouvert', [ExerciceComptableController::class, 'getExerciceOuvert']);
            Route::get('/courant', [ExerciceComptableController::class, 'getExerciceCourant']);
            Route::get('/verifier/courant', [ExerciceComptableController::class, 'verifierExerciceCourant']);
            Route::post('/ouvrir-suivant', [ClotureController::class, 'ouvrirSuivant']);
            Route::get('/{id}', [ExerciceComptableController::class, 'show']);
            Route::get('/{id}/dates', [ExerciceComptableController::class, 'getDates']);
            Route::post('/{id}/ouvrir', [ExerciceComptableController::class, 'ouvrir']);
            // Clôture (lot 4) : écriture 6/7 → 12, à-nouveaux N+1, verrouillage
            Route::get('/{id}/cloture', [ClotureController::class, 'preparation']);
            Route::post('/{id}/cloturer', [ClotureController::class, 'cloturer']);
            Route::post('/{id}/annuler-cloture', [ClotureController::class, 'annuler']);
            Route::get('/{id}/dotations', [ClotureController::class, 'dotations']);
            Route::post('/{id}/dotations', [ClotureController::class, 'genererDotations']);
            // Organisation comptable (lot 5) : clôture mensuelle, affectation du résultat
            Route::get('/{id}/periodes', [ClotureController::class, 'periodes']);
            Route::post('/{id}/periodes/cloturer', [ClotureController::class, 'cloturerPeriode']);
            Route::get('/{id}/affectation', [ClotureController::class, 'affectation']);
            Route::post('/{id}/affectation', [ClotureController::class, 'affecter']);
        });

        // ---- États financiers ----
        Route::get('/compte-resultat/nature', [CompteResultatNatureController::class, 'index']);
        Route::get('/compte-resultat/fonction', [CompteResultatFonctionController::class, 'index']);
        Route::get('/bilan/actif', [BilanActifController::class, 'index']);
        Route::get('/bilan/passif', [BilanPassifController::class, 'index']);
        Route::get('/flux-tresorerie', [FluxTresorerieController::class, 'index']);
        Route::get('/variations-capitaux', [VariationsCapitauxController::class, 'index']);
        Route::get('/etats-financiers/controles', [\App\Http\Controllers\general\ControlesEtatsController::class, 'index']);

        // ---- Mon profil ----
        Route::get('/profil', [\App\Http\Controllers\ProfilController::class, 'show']);
        Route::put('/profil', [\App\Http\Controllers\ProfilController::class, 'update']);
        Route::put('/profil/mot-de-passe', [\App\Http\Controllers\ProfilController::class, 'motDePasse']);
        Route::delete('/profil/sessions', [\App\Http\Controllers\ProfilController::class, 'fermerSessions']);

        // ---- Utilisateurs et rôles (administrateurs) ----
        Route::get('/utilisateurs', [\App\Http\Controllers\UtilisateursController::class, 'index']);
        Route::post('/utilisateurs', [\App\Http\Controllers\UtilisateursController::class, 'store']);
        Route::put('/utilisateurs/{id}', [\App\Http\Controllers\UtilisateursController::class, 'update'])->whereNumber('id');

        // ---- Paramètres de l'application ----
        Route::get('/reglages', [\App\Http\Controllers\ParametresController::class, 'index']);
        Route::put('/reglages', [\App\Http\Controllers\ParametresController::class, 'update']);

        // ---- Tableau de bord ----
        Route::prefix('dashboard')->group(function () {
            Route::get('/evolution-ca', [DashboardController::class, 'evolutionCA']);
            Route::get('/evolution-tresorerie', [DashboardController::class, 'evolutionTresorerie']);
            Route::get('/composition-bilan', [DashboardController::class, 'compositionBilan']);
            Route::get('/decomposition-resultat', [DashboardController::class, 'decompositionResultat']);
            Route::get('/resume', [DashboardController::class, 'resumeDashboard']);
            Route::get('/synthese', [DashboardController::class, 'synthese']);
        });

        // ---- Immobilisations et amortissements ----
        Route::get('/taux-amortissement', [AmortissementController::class, 'getTaux']);
        Route::post('/taux-amortissement', [AmortissementController::class, 'storeTaux']);
        Route::put('/taux-amortissement/{id}', [AmortissementController::class, 'updateTaux']);
        Route::delete('/taux-amortissement/{id}', [AmortissementController::class, 'destroyTaux']);
        Route::post('/amortissement/{id}/sortie', [AmortissementController::class, 'sortie']);
        Route::delete('/amortissement/{id}/sortie', [AmortissementController::class, 'annulerSortie']);
        Route::apiResource('amortissement', AmortissementController::class);

        // ---- Centre de notifications (interface comptable) ----
        Route::prefix('centre-notifications')->controller(\App\Http\Controllers\notifications\CentreNotificationsController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/tout-lire', 'toutLire');
            Route::delete('/lues', 'supprimerLues');
            Route::patch('/{id}/lue', 'marquerLue')->whereNumber('id');
            Route::patch('/{id}/non-lue', 'marquerNonLue')->whereNumber('id');
            Route::delete('/{id}', 'supprimer')->whereNumber('id');
        });

        // ---- Notifications ----
        Route::apiResource('evenements', EvenementController::class);
        Route::apiResource('notifications', NotificationController::class)->except(['index']);
        Route::get('/notifications', [NotificationsController::class, 'index']);
        Route::patch('/notifications/{id}/read', [NotificationsController::class, 'markAsRead']);

        // ---- Simulations budgétaires ----
        Route::prefix('simulations')->group(function () {
            Route::get('/', [SimulationController::class, 'index']);
            Route::post('/', [SimulationController::class, 'store']);
            Route::get('/historical', [SimulationController::class, 'getHistoricalData']);
            Route::get('/{id}', [SimulationController::class, 'show']);
            Route::put('/{id}', [SimulationController::class, 'update']);
            Route::delete('/{id}', [SimulationController::class, 'destroy']);
        });
    });
});
