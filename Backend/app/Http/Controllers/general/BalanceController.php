<?php

namespace App\Http\Controllers\general;

use App\Http\Controllers\Controller;
use App\Models\exercice\ExerciceComptable;
use App\Models\general\Balance;
use App\Services\Comptabilite\LivresComptables;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BalanceController extends Controller
{

        // public function index(Request $request)
        // {
        //     $dateDebut = $request->query('date_debut');
        //     $dateFin = $request->query('date_fin');

        //     if ($dateDebut && $dateFin) {
        //         $balance = DB::table('vue_balance_generale')
        //             ->whereBetween('date_mouvement', [$dateDebut, $dateFin])
        //             ->get();
        //     } else {
        //         $balance = DB::table('vue_balance_generale')->get();
        //     }

        //     return response()->json($balance);
        // }
    /**
     * Balance générale (PCG 2005) : par sous-compte, solde d'ouverture
     * (à-nouveaux), mouvements de la période et solde de fin. Écritures
     * validées uniquement. Voir LivresComptables.
     * GET /api/balance-generale?date_debut&date_fin&classe_compte
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'classe_compte' => 'nullable|string|max:2',
        ]);
        [$debut, $fin] = self::periode($request);

        return response()->json((new LivresComptables())->balance($debut, $fin, $request->classe_compte ?: null));
    }

    /** Période demandée, sinon celle de l'exercice ouvert le plus récent (ou du dernier exercice). */
    public static function periode(Request $request): array
    {
        $ex = ExerciceComptable::getExerciceOuvert() ?? ExerciceComptable::orderByDesc('Annee_fiscale')->first();
        $debut = $request->date_debut ?: ($ex ? Carbon::parse($ex->Date_debut)->toDateString() : now()->startOfYear()->toDateString());
        $fin = $request->date_fin ?: ($ex ? Carbon::parse($ex->Date_fin)->toDateString() : now()->endOfYear()->toDateString());
        return [$debut, $fin];
    }




    // Afficher un sous-compte précis
    public function show($codeSousCompte)
    {
        $sousCompte = Balance::where('code_sous_compte', $codeSousCompte)->first();

        if (!$sousCompte) {
            return response()->json(['message' => 'Sous-compte introuvable'], 404);
        }

        return response()->json($sousCompte);
    }
}
