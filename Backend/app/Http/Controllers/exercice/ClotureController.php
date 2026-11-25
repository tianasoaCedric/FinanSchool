<?php

namespace App\Http\Controllers\exercice;

use App\Http\Controllers\Controller;
use App\Models\exercice\ExerciceComptable;
use App\Services\Comptabilite\ClotureService;
use App\Services\Comptabilite\DotationsService;
use App\Services\Comptabilite\AffectationService;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\EcritureGuard;
use App\Services\Comptabilite\PeriodesService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lot 4 — clôture d'exercice et dotations aux amortissements.
 * Les refus métier sont des ValidationException (HTTP 422 avec `message`).
 */
class ClotureController extends Controller
{
    public function __construct(
        private ClotureService $cloture,
        private DotationsService $dotations,
    ) {}

    /** GET /api/exercices/{id}/cloture — liste de contrôle et aperçu. */
    public function preparation(int $id)
    {
        return response()->json($this->cloture->preparation(ExerciceComptable::findOrFail($id)));
    }

    /** POST /api/exercices/{id}/cloturer — { ignorer_dotations?: bool } */
    public function cloturer(Request $request, int $id)
    {
        $data = $request->validate(['ignorer_dotations' => 'sometimes|boolean']);
        return response()->json($this->cloture->cloturer(
            ExerciceComptable::findOrFail($id),
            $request->user()?->id,
            (bool) ($data['ignorer_dotations'] ?? false)
        ));
    }

    /**
     * POST /api/exercices/{id}/annuler-cloture — { motif }
     * Une clôture est définitive : seul un administrateur peut l'annuler, avec
     * un motif écrit, inscrit dans la piste d'audit.
     */
    public function annuler(Request $request, int $id)
    {
        if (!$request->user()?->is_admin) {
            abort(403, "Seul un administrateur peut annuler la clôture d'un exercice.");
        }
        $data = $request->validate(['motif' => 'required|string|min:10|max:500'], [
            'motif.required' => "Indiquez le motif de l'annulation (il sera conservé dans la piste d'audit).",
            'motif.min' => 'Le motif doit faire au moins 10 caractères.',
        ]);

        return response()->json($this->cloture->annuler(ExerciceComptable::findOrFail($id), trim($data['motif'])));
    }

    /** GET /api/exercices/{id}/periodes — mois de l'exercice et leur statut. */
    public function periodes(int $id)
    {
        return response()->json((new PeriodesService())->liste(ExerciceComptable::findOrFail($id)));
    }

    /** POST /api/exercices/{id}/periodes/cloturer — { mois: "AAAA-MM" } (définitif) */
    public function cloturerPeriode(Request $request, int $id)
    {
        $data = $request->validate(['mois' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $service = new PeriodesService();
        $exercice = ExerciceComptable::findOrFail($id);
        $res = $service->cloturer($exercice, $data['mois']);
        return response()->json($res + ['periodes' => $service->liste($exercice->fresh())]);
    }

    /**
     * POST /api/exercices/ouvrir-suivant — ouvre (et crée au besoin) l'exercice
     * qui suit le dernier exercice, pour saisir N+1 pendant les travaux de clôture de N.
     */
    public function ouvrirSuivant()
    {
        $dernier = ExerciceComptable::orderByDesc('Annee_fiscale')->firstOrFail();
        $suivant = $dernier;
        if ($dernier->isOuvert() || $dernier->isClotureDefinitive()) {
            $debut = Carbon::parse($dernier->Date_fin)->addDay();
            $suivant = new ExerciceComptable([
                'Annee_fiscale' => $dernier->Annee_fiscale + 1,
                'Date_debut' => $debut->toDateString(),
                'Date_fin' => $debut->copy()->addYear()->subDay()->toDateString(),
                'Statut' => 'CLOTURE',
            ]);
        }
        if ($motif = $suivant->motifRefusOuverture()) {
            throw ValidationException::withMessages(['exercice' => [$motif]]);
        }
        DB::transaction(function () use ($suivant) {
            $suivant->ouvrir(); // enregistre aussi un exercice nouvellement créé
            Audit::enregistrer(Audit::EXERCICE_OUVERTURE, 'exercice', $suivant->Id_Exercice_comptable,
                "Exercice {$suivant->Annee_fiscale}", null, null, ['motif' => 'Saisie de N+1 pendant les travaux de clôture']);
        });

        return response()->json([
            'message' => "Exercice {$suivant->Annee_fiscale} ouvert : la saisie courante peut s'y faire pendant les travaux de clôture.",
            'exercice' => $this->cloture->resume($suivant->fresh()),
        ], 201);
    }

    /** GET /api/exercices/{id}/affectation — affectation du résultat de l'exercice clôturé {id}. */
    public function affectation(int $id)
    {
        return response()->json((new AffectationService())->preparation(ExerciceComptable::findOrFail($id)));
    }

    /**
     * POST /api/exercices/{id}/affectation
     * { date, reference, repartition: [{code, montant}], valider?: bool }
     */
    public function affecter(Request $request, int $id)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'reference' => 'required|string|max:100',
            'repartition' => 'required|array|min:1',
            'repartition.*.code' => 'required|string',
            'repartition.*.montant' => 'required|numeric|min:0',
            'valider' => 'sometimes|boolean',
        ], [
            'reference.required' => 'Indiquez la référence de la décision (ex. PV de l\'AG du …).',
        ]);
        $exercice = ExerciceComptable::findOrFail($id);
        $service = new AffectationService();
        $piece = $service->generer($exercice, Carbon::parse($data['date'])->toDateString(), $data['reference'],
            $data['repartition'], (bool) ($data['valider'] ?? false), $request->user()?->id);

        return response()->json([
            'message' => "Affectation du résultat {$exercice->Annee_fiscale} enregistrée (pièce {$piece->numeroAffiche()}).",
            'affectation' => $service->preparation($exercice->fresh()),
        ], 201);
    }

    /** GET /api/exercices/{id}/dotations — plan d'amortissement de l'exercice. */
    public function dotations(int $id)
    {
        return response()->json($this->dotations->calcul(ExerciceComptable::findOrFail($id)));
    }

    /** POST /api/exercices/{id}/dotations — génère la pièce (brouillon) ; { valider?: bool } */
    public function genererDotations(Request $request, int $id)
    {
        $data = $request->validate(['valider' => 'sometimes|boolean']);
        $exercice = ExerciceComptable::findOrFail($id);

        $piece = DB::transaction(function () use ($exercice, $request, $data) {
            $piece = $this->dotations->generer($exercice, $request->user()?->id);
            if ($data['valider'] ?? false) {
                EcritureGuard::validerPiece($piece->load('lignes'), $request->user()?->id);
            }
            return $piece;
        });

        return response()->json([
            'message' => ($data['valider'] ?? false)
                ? "Dotations {$exercice->Annee_fiscale} passées et validées (pièce {$piece->numeroAffiche()})."
                : "Pièce de dotations {$piece->numeroAffiche()} générée en brouillon : relisez-la puis validez-la.",
            'piece' => ['id' => $piece->Id_Mouvement_ecriture, 'numero' => $piece->numeroAffiche()],
            'plan' => $this->dotations->calcul($exercice),
        ], 201);
    }
}
