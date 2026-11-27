<?php
namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Models\PlanCompte\Amortissement;
use App\Models\PlanCompte\SousCompte;
use App\Models\PlanCompte\TauxAmortissement;
use App\Models\exercice\ExerciceComptable;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\DotationsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Registre des immobilisations, taux d'amortissement et sorties d'actif.
 * Les calculs sont faits par DotationsService (mêmes montants que les dotations comptabilisées).
 */
class AmortissementController extends Controller
{
    /** Champs qui déterminent le plan d'amortissement : figés une fois l'immobilisation amortie. */
    private const CHAMPS_PLAN = ['Id_Sous_compte', 'taux_amortissement_id', 'valeur_brute', 'date_acquisition', 'date_debut_utilisation', 'Id_Sous_compte_amortissement'];

    public function __construct(private DotationsService $dotations) {}

    // GET /api/amortissement?exercice=AAAA
    public function index(Request $request)
    {
        $annee = (int) $request->input('exercice', ExerciceComptable::getExerciceOuvert()?->Annee_fiscale ?? now()->year);
        $exercice = ExerciceComptable::where('Annee_fiscale', $annee)->first()
            ?? new ExerciceComptable(['Annee_fiscale' => $annee, 'Date_debut' => "{$annee}-01-01", 'Date_fin' => "{$annee}-12-31"]);
        $fin = Carbon::parse($exercice->Date_fin);
        $plan = collect($this->dotations->calcul($exercice)['immobilisations'])->keyBy('id');

        return response()->json(Amortissement::with(['tauxAmortissement', 'sousCompte', 'sousCompteAmortissement'])->orderBy('date_acquisition')->get()
            ->map(function ($immo) use ($plan, $fin) {
                $p = $plan[$immo->id] ?? null;
                $amortissable = $immo->sousCompte && DotationsService::estAmortissable($immo->sousCompte->Code_sous_compte);
                $cumul = $p ? round($p['cumul_anterieur'] + $p['dotation'], 2) : $this->dotations->cumulAu($immo, $fin);
                return [
                    'id' => $immo->id,
                    'compte' => $immo->sousCompte->Code_sous_compte . ' - ' . $immo->sousCompte->Libelle,
                    'Id_Sous_compte' => $immo->Id_Sous_compte,
                    'Id_Sous_compte_amortissement' => $immo->Id_Sous_compte_amortissement,
                    'compte_amortissement' => $amortissable ? DotationsService::compteAmortissement($immo)->Code_sous_compte : null,
                    'libelle' => $immo->libelle,
                    'amortissable' => $amortissable,
                    'taux_amortissement_id' => $immo->taux_amortissement_id,
                    'taux' => $immo->tauxAmortissement?->taux,
                    'categorie' => $immo->tauxAmortissement?->intitule,
                    'valeur_brute' => (float) $immo->valeur_brute,
                    'date_acquisition' => $immo->date_acquisition,
                    'date_debut_utilisation' => $immo->date_debut_utilisation,
                    'date_sortie' => $immo->date_sortie,
                    'prix_cession' => $immo->prix_cession !== null ? (float) $immo->prix_cession : null,
                    'id_piece_cession' => $immo->id_piece_cession,
                    'cumul_amortissement' => $cumul,
                    'dotation_exercice' => $p['dotation'] ?? 0,
                    'vnc' => round((float) $immo->valeur_brute - $cumul, 2),
                    'verrouillee' => $this->dotations->estVerrouillee($immo),
                ];
            }));
    }

    // POST /api/amortissement
    public function store(Request $request)
    {
        $data = $this->valider($request);
        $immo = Amortissement::create($data);
        Audit::enregistrer(Audit::IMMO_CREATION, 'immobilisation', $immo->id, $immo->libelle, null, $immo->only(array_merge(['libelle'], self::CHAMPS_PLAN)));
        return response()->json($immo->load(['tauxAmortissement', 'sousCompte']), 201);
    }

    // PUT /api/amortissement/{id}
    public function update(Request $request, $id)
    {
        $immo = Amortissement::with('sousCompte', 'tauxAmortissement')->findOrFail($id);
        $data = $this->valider($request);

        if ($this->dotations->estVerrouillee($immo)) {
            $modifies = collect(self::CHAMPS_PLAN)->filter(fn ($c) => (string) ($immo->$c ?? '') !== (string) ($data[$c] ?? '')
                && !(in_array($c, ['valeur_brute']) && (float) $immo->$c === (float) $data[$c])
                && !(str_starts_with($c, 'date_') && $immo->$c && $data[$c] && Carbon::parse($immo->$c)->isSameDay(Carbon::parse($data[$c]))));
            if ($modifies->isNotEmpty()) {
                throw ValidationException::withMessages(['immobilisation' => [
                    'Immobilisation déjà amortie en comptabilité (ou sortie) : seul le libellé est modifiable. '
                    . 'Pour corriger sa base, contre-passez les dotations concernées puis modifiez la fiche.',
                ]]);
            }
        }

        $avant = $immo->only(array_merge(['libelle'], self::CHAMPS_PLAN));
        $immo->update($data);
        Audit::enregistrer(Audit::IMMO_MODIFICATION, 'immobilisation', $immo->id, $immo->libelle, $avant, $immo->only(array_merge(['libelle'], self::CHAMPS_PLAN)));
        return response()->json($immo->load(['tauxAmortissement', 'sousCompte']));
    }

    // DELETE /api/amortissement/{id}
    public function destroy($id)
    {
        $immo = Amortissement::with('sousCompte', 'tauxAmortissement')->findOrFail($id);
        if ($this->dotations->estVerrouillee($immo)) {
            throw ValidationException::withMessages(['immobilisation' => [
                'Immobilisation déjà amortie en comptabilité (ou sortie) : suppression impossible. Utilisez « Sortie de l\'actif ».',
            ]]);
        }
        Audit::enregistrer(Audit::IMMO_SUPPRESSION, 'immobilisation', $immo->id, $immo->libelle, $immo->only(array_merge(['libelle'], self::CHAMPS_PLAN)), null);
        $immo->delete();
        return response()->json(['message' => 'Immobilisation supprimée avec succès']);
    }

    // POST /api/amortissement/{id}/sortie — { date_sortie, prix_cession?, Id_Sous_compte_contrepartie? }
    public function sortie(Request $request, $id)
    {
        $data = $request->validate([
            'date_sortie' => 'required|date',
            'prix_cession' => 'nullable|numeric|min:0',
            'Id_Sous_compte_contrepartie' => 'nullable|exists:sous_comptes,Id_Sous_compte',
        ]);
        $immo = Amortissement::with('sousCompte', 'tauxAmortissement')->findOrFail($id);
        $piece = $this->dotations->sortir($immo, $data['date_sortie'], (float) ($data['prix_cession'] ?? 0),
            $data['Id_Sous_compte_contrepartie'] ?? null, $request->user()?->id);

        return response()->json([
            'message' => "Sortie enregistrée : pièce {$piece->numeroAffiche()} générée en brouillon, à relire puis valider.",
            'piece' => ['id' => $piece->Id_Mouvement_ecriture, 'numero' => $piece->numeroAffiche()],
        ], 201);
    }

    // DELETE /api/amortissement/{id}/sortie
    public function annulerSortie($id)
    {
        $this->dotations->annulerSortie(Amortissement::findOrFail($id));
        return response()->json(['message' => 'Sortie annulée.']);
    }

    // ---- Taux d'amortissement -------------------------------------------

    // GET /api/taux-amortissement
    public function getTaux()
    {
        return TauxAmortissement::orderBy('intitule')->get()->map(fn ($t) => $t->toArray() + [
            'utilise' => Amortissement::where('taux_amortissement_id', $t->id)->exists(),
        ]);
    }

    // POST /api/taux-amortissement
    public function storeTaux(Request $request)
    {
        return response()->json(TauxAmortissement::create($this->validerTaux($request)), 201);
    }

    // PUT /api/taux-amortissement/{id}
    public function updateTaux(Request $request, $id)
    {
        $taux = TauxAmortissement::findOrFail($id);
        $data = $this->validerTaux($request);
        if ((float) $data['taux'] !== (float) $taux->taux && Amortissement::where('taux_amortissement_id', $taux->id)->exists()) {
            throw ValidationException::withMessages(['taux' => ['Ce taux est utilisé par des immobilisations : créez une nouvelle catégorie plutôt que de changer le taux.']]);
        }
        $taux->update($data);
        return response()->json($taux);
    }

    // DELETE /api/taux-amortissement/{id}
    public function destroyTaux($id)
    {
        $taux = TauxAmortissement::findOrFail($id);
        if (Amortissement::where('taux_amortissement_id', $taux->id)->exists()) {
            throw ValidationException::withMessages(['taux' => ['Ce taux est utilisé par des immobilisations.']]);
        }
        $taux->delete();
        return response()->json(['message' => 'Taux supprimé.']);
    }

    // ---------------------------------------------------------------------

    private function valider(Request $request): array
    {
        $data = $request->validate([
            'libelle' => 'required|string|max:255',
            'Id_Sous_compte' => 'required|exists:sous_comptes,Id_Sous_compte',
            'taux_amortissement_id' => 'nullable|exists:taux_amortissement,id',
            'valeur_brute' => 'required|numeric|gt:0',
            'date_acquisition' => 'required|date',
            'date_debut_utilisation' => 'nullable|date|after_or_equal:date_acquisition',
            'Id_Sous_compte_amortissement' => 'nullable|exists:sous_comptes,Id_Sous_compte',
        ], [
            'date_debut_utilisation.after_or_equal' => "La mise en service ne peut pas précéder l'acquisition.",
            'valeur_brute.gt' => 'La valeur brute doit être positive.',
        ]);

        $code = SousCompte::find($data['Id_Sous_compte'])->Code_sous_compte;
        if (!DotationsService::estImmobilisation($code)) {
            throw ValidationException::withMessages(['Id_Sous_compte' => ["Le compte {$code} n'est pas un compte d'immobilisation (classe 2, hors 28 et 29)."]]);
        }
        if (DotationsService::estAmortissable($code)) {
            if (empty($data['taux_amortissement_id'])) {
                throw ValidationException::withMessages(['taux_amortissement_id' => ["Le compte {$code} est amortissable : choisissez un taux."]]);
            }
            if (!empty($data['Id_Sous_compte_amortissement'])) {
                $codeAmort = SousCompte::find($data['Id_Sous_compte_amortissement'])->Code_sous_compte;
                if (!str_starts_with($codeAmort, '28')) {
                    throw ValidationException::withMessages(['Id_Sous_compte_amortissement' => ["Le compte d'amortissement doit être un compte 28 ({$codeAmort} indiqué)."]]);
                }
            }
        } else {
            // Terrains, en-cours, immobilisations financières : pas d'amortissement
            $data['taux_amortissement_id'] = null;
            $data['Id_Sous_compte_amortissement'] = null;
        }
        $data['date_debut_utilisation'] = $data['date_debut_utilisation'] ?? $data['date_acquisition'];
        return $data;
    }

    private function validerTaux(Request $request): array
    {
        $data = $request->validate([
            'intitule' => 'required|string|max:255',
            'taux' => 'required|numeric|gt:0|max:100',
            'duree' => 'nullable|numeric|gt:0',
        ]);
        $data['duree'] = $data['duree'] ?? round(100 / $data['taux'], 2);
        $data['unite_duree'] = 'ans';
        return $data;
    }
}
