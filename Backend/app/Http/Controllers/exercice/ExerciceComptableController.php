<?php

namespace App\Http\Controllers\exercice;

use App\Models\exercice\ExerciceComptable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ExerciceComptableController extends Controller
{
    /**
     * Liste tous les exercices comptables
     * GET /api/exercices
     */
    public function index()
    {
        $exercices = ExerciceComptable::orderBy('Annee_fiscale', 'desc')->get();
        return response()->json($exercices);
    }

    /**
     * Récupère l'exercice actuellement ouvert
     * GET /api/exercices/ouvert
     */
    public function getExerciceOuvert()
    {
        $exercice = ExerciceComptable::getExerciceOuvert();
        
        if (!$exercice) {
            return response()->json([
                'error' => 'Aucun exercice ouvert'
            ], 404);
        }

        return response()->json($exercice);
    }

    /**
     * Récupère un exercice par ID
     * GET /api/exercices/{id}
     */
    public function show($id)
    {
        $exercice = ExerciceComptable::find($id);
        
        if (!$exercice) {
            return response()->json([
                'error' => 'Exercice non trouvé'
            ], 404);
        }

        return response()->json($exercice);
    }

    /**
     * Ouvre un exercice (N et N+1 peuvent être ouverts ensemble)
     * POST /api/exercices/{id}/ouvrir
     */
    public function ouvrir($id)
    {
        $exercice = ExerciceComptable::find($id);
        
        if (!$exercice) {
            return response()->json([
                'error' => 'Exercice non trouvé'
            ], 404);
        }

        // Une clôture définitive ne se défait que par « annuler la clôture » ;
        // au plus deux exercices consécutifs ouverts (voir le modèle).
        if ($motif = $exercice->motifRefusOuverture()) {
            return response()->json(['message' => $motif], 422);
        }

        $exercice->ouvrir();

        return response()->json([
            'message' => 'Exercice ouvert avec succès',
            'exercice' => $exercice
        ]);
    }

    // Clôture : voir ClotureController (POST /api/exercices/{id}/cloturer).

    /**
     * Récupère les dates de l'exercice pour filtrage
     * GET /api/exercices/{id}/dates
     */
    public function getDates($id)
    {
        $exercice = ExerciceComptable::find($id);
        
        if (!$exercice) {
            return response()->json([
                'error' => 'Exercice non trouvé'
            ], 404);
        }

        return response()->json([
            'id' => $exercice->Id_Exercice_comptable,
            'annee_fiscale' => $exercice->Annee_fiscale,
            'date_debut' => $exercice->Date_debut->format('Y-m-d'),
            'date_fin' => $exercice->Date_fin->format('Y-m-d'),
            'statut' => $exercice->Statut
        ]);
    }

    /**
     * Indique l'exercice couvrant la date du jour et son statut.
     * Lecture seule : ouvrir ou clôturer un exercice est une décision explicite
     * (POST /exercices/{id}/ouvrir ou /cloturer), jamais un effet de bord d'un GET.
     * GET /api/exercices/verifier/courant
     */
    public function verifierExerciceCourant()
    {
        $dateActuelle = Carbon::now();
        $exerciceCourant = ExerciceComptable::getExerciceByDate($dateActuelle);

        if (!$exerciceCourant) {
            return response()->json([
                'error' => 'Aucun exercice trouvé pour la date actuelle',
                'date_actuelle' => $dateActuelle->format('Y-m-d')
            ], 404);
        }

        return response()->json([
            'message' => $exerciceCourant->isOuvert()
                ? "L'exercice de la date du jour est ouvert"
                : "L'exercice de la date du jour est clôturé",
            'action' => 'AUCUNE_ACTION',
            'exercice' => $exerciceCourant,
            'exercice_ouvert' => ExerciceComptable::getExerciceOuvert(),
            'date_actuelle' => $dateActuelle->format('Y-m-d')
        ]);
    }

    /**
     * Récupère l'exercice courant basé sur la date actuelle (sans modification)
     * GET /api/exercices/courant
     */
    public function getExerciceCourant()
    {
        $dateActuelle = Carbon::now();
        
        // Trouver l'exercice qui contient la date actuelle
        $exerciceCourant = ExerciceComptable::getExerciceByDate($dateActuelle);
        
        if (!$exerciceCourant) {
            return response()->json([
                'error' => 'Aucun exercice trouvé pour la date actuelle',
                'date_actuelle' => $dateActuelle->format('Y-m-d')
            ], 404);
        }

        return response()->json([
            'exercice' => $exerciceCourant,
            'date_actuelle' => $dateActuelle->format('Y-m-d'),
            'est_ouvert' => $exerciceCourant->isOuvert()
        ]);
    }

        public static function getExerciceCourantStatic()
    {
        $dateActuelle = Carbon::now();

        $exerciceCourant = ExerciceComptable::getExerciceByDate($dateActuelle);

        if (!$exerciceCourant) {
            return null; // à tester côté appelant
        }

        return $exerciceCourant; // ->Id_Exercice_comptable, ->Date_debut, ->Date_fin, etc.
    }

    

    
}
