<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Models\exercice\ExerciceComptable;
use App\Models\Simulation\Simulation;
use App\Services\Comptabilite\EtatsFinanciers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Simulations budgétaires : à partir du réalisé d'un exercice de référence
 * (comptes de charges 6 et de produits 7, écritures validées), on applique
 * des hypothèses d'évolution pour obtenir un budget prévisionnel.
 */
class SimulationController extends Controller
{
    /** GET /api/simulations — scénarios avec leurs totaux. */
    public function index()
    {
        $simulations = Simulation::with(['lignes:id_simulation_ligne,id_simulation,type,nature_charge,moyenne_historique,montant_simule', 'exercice', 'createur:id,name'])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($simulations->map(fn ($s) => $this->resume($s)));
    }

    /** GET /api/simulations/{id} — scénario complet. */
    public function show($id)
    {
        $s = Simulation::with(['lignes.sousCompte.compte', 'exercice', 'createur:id,name'])->findOrFail($id);

        return response()->json($this->resume($s) + [
            'lignes' => $s->lignes->sortBy(fn ($l) => $l->sousCompte?->Code_sous_compte ?? 'zzz')->values()->map(fn ($l) => [
                'id_sous_compte' => $l->id_sous_compte,
                'code' => $l->sousCompte?->Code_sous_compte,
                'compte' => $l->sousCompte?->compte?->Code_compte,
                'libelle_compte' => $l->sousCompte?->compte?->Libelle,
                'libelle' => $l->libelle,
                'type' => $l->type,
                'nature_charge' => $l->nature_charge,
                'reference' => $l->moyenne_historique,
                'coefficient' => $l->coefficient,
                'montant_simule' => $l->montant_simule,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->valider($request);
        $simulation = DB::transaction(function () use ($data) {
            $s = Simulation::create($this->entete($data) + ['created_by' => Auth::id()]);
            $this->enregistrerLignes($s, $data['lignes']);
            return $s;
        });
        return $this->show($simulation->id_simulation)->setStatusCode(201);
    }

    /** PUT /api/simulations/{id} — modifie le scénario (en-tête, hypothèses, lignes). */
    public function update(Request $request, $id)
    {
        $s = Simulation::findOrFail($id);
        $data = $this->valider($request);
        DB::transaction(function () use ($s, $data) {
            $s->update($this->entete($data));
            $s->lignes()->delete();
            $this->enregistrerLignes($s, $data['lignes']);
        });
        return $this->show($s->id_simulation);
    }

    public function destroy($id)
    {
        Simulation::findOrFail($id)->delete();
        return response()->json(['message' => 'Simulation supprimée']);
    }

    /**
     * GET /api/simulations/historical?id_exercice=…
     * Réalisé de l'exercice par sous-compte de charges (6) et de produits (7),
     * écritures validées hors écriture de clôture. Si l'exercice est en cours,
     * le montant de référence est projeté sur 12 mois.
     */
    public function getHistoricalData(Request $request)
    {
        $request->validate(['id_exercice' => 'nullable|exists:exercice_comptable,Id_Exercice_comptable']);
        $ex = $request->filled('id_exercice')
            ? ExerciceComptable::findOrFail($request->id_exercice)
            : (ExerciceComptable::getExerciceByDate(date('Y-m-d')) ?? ExerciceComptable::orderByDesc('Annee_fiscale')->firstOrFail());

        $debut = Carbon::parse($ex->Date_debut)->startOfDay();
        $fin = Carbon::parse($ex->Date_fin)->startOfDay();
        $aujourdhui = Carbon::today();
        $enCours = $aujourdhui->between($debut, $fin);
        $jusquA = $enCours ? $aujourdhui : $fin;
        $avancement = min(1, ($debut->diffInDays($jusquA) + 1) / ($debut->diffInDays($fin) + 1));

        $soldes = (new EtatsFinanciers())->soldes($debut->toDateString(), $jusquA->toDateString());
        $codes = array_filter(array_map('strval', array_keys($soldes)), fn ($c) => in_array($c[0], ['6', '7'], true));

        $comptes = DB::table('sous_comptes as s')
            ->join('comptes as c', 'c.Id_Compte', '=', 's.Id_Compte')
            ->whereIn('s.Code_sous_compte', $codes)
            ->orderBy('s.Code_sous_compte')
            ->get(['s.Id_Sous_compte', 's.Code_sous_compte', 's.Libelle', 'c.Code_compte', 'c.Libelle as libelle_compte']);

        $lignes = $comptes->map(function ($c) use ($soldes, $enCours, $avancement) {
            $produit = $c->Code_sous_compte[0] === '7';
            // Montant positif dans le sens normal du compte (crédit pour 7, débit pour 6)
            $realise = round($produit ? -$soldes[$c->Code_sous_compte] : $soldes[$c->Code_sous_compte], 2);
            return [
                'id_sous_compte' => $c->Id_Sous_compte,
                'code' => $c->Code_sous_compte,
                'libelle' => $c->Libelle,
                'compte' => $c->Code_compte,
                'libelle_compte' => $c->libelle_compte,
                'type' => $produit ? 'produit' : 'charge',
                // Achats consommés : proportionnels à l'activité ; le reste est supposé fixe
                'nature_charge' => !$produit && str_starts_with($c->Code_sous_compte, '60') ? 'variable' : 'fixe',
                'realise' => $realise,
                'reference' => $enCours && $avancement > 0 ? round($realise / $avancement, 2) : $realise,
            ];
        })->values();

        return response()->json([
            'exercice' => [
                'id' => $ex->Id_Exercice_comptable,
                'annee' => $ex->Annee_fiscale,
                'debut' => $debut->toDateString(),
                'fin' => $fin->toDateString(),
                'en_cours' => $enCours,
                'arrete_au' => $jusquA->toDateString(),
                'avancement' => round($avancement * 100, 1),
            ],
            'lignes' => $lignes,
        ]);
    }

    // ------------------------------------------------------------------

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom_simulation' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'id_exercice_comptable' => 'required|exists:exercice_comptable,Id_Exercice_comptable',
            'annee_cible' => 'nullable|integer|min:2000|max:2200',
            'hypotheses' => 'nullable|array',
            'hypotheses.produits' => 'nullable|numeric|min:-100|max:1000',
            'hypotheses.charges_fixes' => 'nullable|numeric|min:-100|max:1000',
            'hypotheses.charges_variables' => 'nullable|numeric|min:-100|max:1000',
            'lignes' => 'required|array|min:1',
            'lignes.*.libelle' => 'required|string|max:255',
            'lignes.*.type' => 'required|in:produit,charge',
            'lignes.*.nature_charge' => 'required|in:fixe,variable',
            'lignes.*.reference' => 'required|numeric',
            'lignes.*.coefficient' => 'required|numeric|min:0|max:1000',
            'lignes.*.montant_simule' => 'required|numeric',
            'lignes.*.id_sous_compte' => 'nullable|exists:sous_comptes,Id_Sous_compte',
        ], [
            'nom_simulation.required' => 'Donnez un nom au scénario.',
            'lignes.required' => 'Le scénario ne contient aucun compte : choisissez un exercice de référence avec des écritures validées.',
        ]);
    }

    private function entete(array $d): array
    {
        return [
            'nom_simulation' => $d['nom_simulation'],
            'description' => $d['description'] ?? null,
            'id_exercice_comptable' => $d['id_exercice_comptable'],
            'annee_cible' => $d['annee_cible'] ?? null,
            'hypotheses' => $d['hypotheses'] ?? null,
        ];
    }

    private function enregistrerLignes(Simulation $s, array $lignes): void
    {
        foreach ($lignes as $l) {
            $s->lignes()->create([
                'libelle' => $l['libelle'],
                'type' => $l['type'],
                'nature_charge' => $l['nature_charge'],
                'moyenne_historique' => round((float) $l['reference'], 2),
                'coefficient' => round((float) $l['coefficient'], 4),
                'montant_simule' => round((float) $l['montant_simule'], 2),
                'id_sous_compte' => $l['id_sous_compte'] ?? null,
            ]);
        }
    }

    private function resume(Simulation $s): array
    {
        $somme = fn (string $type, string $champ) => round($s->lignes->where('type', $type)->sum($champ), 2);
        $produits = $somme('produit', 'montant_simule');
        $charges = $somme('charge', 'montant_simule');
        $refProduits = $somme('produit', 'moyenne_historique');
        $refCharges = $somme('charge', 'moyenne_historique');

        return [
            'id' => $s->id_simulation,
            'nom' => $s->nom_simulation,
            'description' => $s->description,
            'id_exercice_comptable' => $s->id_exercice_comptable,
            'exercice_reference' => $s->exercice?->Annee_fiscale,
            'annee_cible' => $s->annee_cible,
            'hypotheses' => $s->hypotheses ?? ['produits' => 0, 'charges_fixes' => 0, 'charges_variables' => 0],
            'auteur' => $s->createur?->name,
            'modifie_le' => $s->updated_at,
            'nb_lignes' => $s->lignes->count(),
            'totaux' => [
                'produits' => $produits,
                'charges' => $charges,
                'resultat' => round($produits - $charges, 2),
                'charges_fixes' => round($s->lignes->where('type', 'charge')->where('nature_charge', 'fixe')->sum('montant_simule'), 2),
                'charges_variables' => round($s->lignes->where('type', 'charge')->where('nature_charge', 'variable')->sum('montant_simule'), 2),
                'reference_produits' => $refProduits,
                'reference_charges' => $refCharges,
                'reference_resultat' => round($refProduits - $refCharges, 2),
            ],
        ];
    }
}
