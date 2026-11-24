<?php
namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Models\PlanCompte\Amortissement; // adapt if model named Amortissement
use App\Models\PlanCompte\SousCompte;
use App\Models\PlanCompte\TauxAmortissement;
use App\Models\exercice\ExerciceComptable;
use App\Services\Comptabilite\DotationsService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AmortissementController extends Controller
{
    // GET /api/amortissement
    public function index(Request $request)
    {
        // Même calcul que les dotations comptabilisées (prorata temporis au jour)
        $annee = (int) $request->input('exercice', ExerciceComptable::getExerciceOuvert()?->Annee_fiscale ?? now()->year);
        $exercice = ExerciceComptable::where('Annee_fiscale', $annee)->first()
            ?? new ExerciceComptable(['Annee_fiscale' => $annee, 'Date_debut' => "{$annee}-01-01", 'Date_fin' => "{$annee}-12-31"]);
        $plan = collect((new DotationsService())->calcul($exercice)['immobilisations'])->keyBy('id');

        $immos = Amortissement::with(['tauxAmortissement', 'sousCompte'])->get();

        $result = $immos->map(function($immo) use ($plan) {
            $taux = $immo->tauxAmortissement->taux;
            $valeurBrute = $immo->valeur_brute;
            $p = $plan[$immo->id] ?? null;
            // Immobilisation hors classe 2 (non amortissable) : pas de plan
            $cumul = $p ? $p['cumul_anterieur'] + $p['dotation'] : 0;

            return [
                'id' => $immo->id,
                'compte' => $immo->sousCompte->Code_sous_compte . ' - ' . $immo->sousCompte->Libelle,
                'libelle' => $immo->libelle,
                'taux' => $taux,
                'valeur_brute' => $valeurBrute,
                'date_acquisition' => $immo->date_acquisition,
                'date_debut_utilisation' => $immo->date_debut_utilisation,
                'cumul_amortissement' => round($cumul,2),
                'dotation_exercice' => $p['dotation'] ?? 0,
                'vnc' => $p['vnc_fin'] ?? (float) $valeurBrute,
            ];
        });

        return response()->json($result);
    }

    // POST /api/amortissement
    public function store(Request $request)
    {
        $validated = $request->validate([
            'libelle' => 'required|string',
            'Id_Sous_compte' => 'required|exists:sous_comptes,Id_Sous_compte',
            'taux_amortissement_id' => 'required|exists:taux_amortissement,id',
            'valeur_brute' => 'required|numeric',
            'date_acquisition' => 'required|date',
            'date_debut_utilisation' => 'nullable|date'
        ]);

        $immobilisation = Amortissement::create([
            'libelle' => $validated['libelle'],
            'Id_Sous_compte' => $validated['Id_Sous_compte'],
            'taux_amortissement_id' => $validated['taux_amortissement_id'],
            'valeur_brute' => $validated['valeur_brute'],
            'date_acquisition' => $validated['date_acquisition'],
            'date_debut_utilisation' => $validated['date_debut_utilisation'] ?? $validated['date_acquisition'],
        ]);

        return response()->json($immobilisation->load(['tauxAmortissement', 'sousCompte']));
    }

    // GET /api/taux-amortissement
    public function getTaux()
    {
        return TauxAmortissement::all();
    }

        // PUT /api/amortissement/{id}
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'libelle' => 'required|string',
            'Id_Sous_compte' => 'required|exists:sous_comptes,Id_Sous_compte',
            'taux_amortissement_id' => 'required|exists:taux_amortissement,id',
            'valeur_brute' => 'required|numeric',
            'date_acquisition' => 'required|date',
            'date_debut_utilisation' => 'nullable|date'
        ]);

        $immo = Amortissement::findOrFail($id);
        
        $immo->update([
            'libelle' => $validated['libelle'],
            'Id_Sous_compte' => $validated['Id_Sous_compte'],
            'taux_amortissement_id' => $validated['taux_amortissement_id'],
            'valeur_brute' => $validated['valeur_brute'],
            'date_acquisition' => $validated['date_acquisition'],
            'date_debut_utilisation' => $validated['date_debut_utilisation'] ?? $validated['date_acquisition'],
        ]);

        return response()->json($immo->load(['tauxAmortissement', 'sousCompte']));
    }

    // DELETE /api/amortissement/{id}
    public function destroy($id)
    {
        $immo = Amortissement::findOrFail($id);
        $immo->delete();
        
        return response()->json(['message' => 'Immobilisation supprimée avec succès']);
    }

}
