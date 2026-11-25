<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Models\Saisie\MouvementEcriture;
use App\Services\Comptabilite\EcritureGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Écran de validation : file des pièces en brouillon, avec pour chacune le
 * motif qui empêche sa validation, et validation par lot.
 * Toutes les règles passent par EcritureGuard (et la validation est tracée
 * dans la piste d'audit).
 */
class ValidationController extends Controller
{
    private const LIMITE = 500;

    /**
     * GET /api/validation/pieces?date_debut&date_fin&journal&q
     * Pièces en brouillon (les plus anciennes d'abord) et synthèse.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'journal' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
        ]);

        $requete = MouvementEcriture::query()
            ->where(fn ($w) => $w
                ->whereHas('lignes', fn ($l) => $l->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut'))
                ->orWhereDoesntHave('lignes'))
            ->when($request->filled('date_debut'), fn ($w) => $w->where('Date_mouvement', '>=', $request->date_debut))
            ->when($request->filled('date_fin'), fn ($w) => $w->where('Date_mouvement', '<=', $request->date_fin))
            ->when($request->filled('journal'), fn ($w) => $w->where('Id_Journal', $request->journal))
            ->when($request->filled('q'), function ($w) use ($request) {
                $t = '%' . $request->q . '%';
                $w->where(fn ($x) => $x->where('Libelle', 'ILIKE', $t)->orWhere('Reference', 'ILIKE', $t)
                    ->orWhereHas('lignes', fn ($l) => $l->where('Libelle', 'ILIKE', $t)));
            });

        $total = (clone $requete)->count();
        $pieces = $requete->with(['journal:Id_Journal,Code,Libelle', 'createur:id,name', 'lignes.sousCompte:Id_Sous_compte,Code_sous_compte,Libelle'])
            ->orderBy('Date_mouvement')->orderBy('Id_Mouvement_ecriture')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn ($p) => $this->serialiser($p));

        $pretes = $pieces->whereNull('motif');
        return response()->json([
            'pieces' => $pieces->values(),
            'synthese' => [
                'total' => $total,
                'affichees' => $pieces->count(),
                'pretes' => $pretes->count(),
                'a_corriger' => $pieces->count() - $pretes->count(),
                'montant' => round($pieces->sum('total_debit'), 2),
                'montant_pret' => round($pretes->sum('total_debit'), 2),
            ],
        ]);
    }

    /**
     * POST /api/validation/valider  { ids: [..] }
     * Valide chaque pièce dans sa propre transaction : une pièce refusée
     * n'empêche pas les autres. Renvoie les pièces validées et ignorées.
     */
    public function valider(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:' . self::LIMITE,
            'ids.*' => 'integer',
        ]);

        $validees = [];
        $ignorees = [];
        foreach (array_unique($data['ids']) as $id) {
            try {
                $numero = DB::transaction(function () use ($id) {
                    $piece = MouvementEcriture::with('lignes')->lockForUpdate()->find($id);
                    if (!$piece) {
                        throw new \RuntimeException('Pièce introuvable');
                    }
                    EcritureGuard::validerPiece($piece, Auth::id());
                    return $piece->numeroAffiche();
                });
                $validees[] = ['id' => $id, 'numero' => $numero];
            } catch (\Illuminate\Validation\ValidationException $e) {
                $ignorees[] = ['id' => $id, 'numero' => MouvementEcriture::find($id)?->numeroAffiche() ?? "#{$id}", 'motif' => $e->getMessage()];
            } catch (\RuntimeException $e) {
                $ignorees[] = ['id' => $id, 'numero' => "#{$id}", 'motif' => $e->getMessage()];
            }
        }

        $n = count($validees);
        $message = $n === 0 ? 'Aucune pièce validée' : "{$n} pièce(s) validée(s)";
        if ($ignorees) {
            $message .= ' — ' . count($ignorees) . ' ignorée(s)';
        }
        return response()->json(['message' => $message, 'validees' => $validees, 'ignorees' => $ignorees]);
    }

    private function serialiser(MouvementEcriture $p): array
    {
        $lignes = $p->lignes->sortBy('Id_Ligne_ecriture')->values();
        return [
            'id' => $p->Id_Mouvement_ecriture,
            'numero' => $p->numeroAffiche(),
            'date' => substr((string) $p->Date_mouvement, 0, 10),
            'journal' => $p->journal ? ['id' => $p->journal->Id_Journal, 'code' => $p->journal->Code] : null,
            'libelle' => $p->Libelle ?: $lignes->first()?->Libelle,
            'reference' => $p->Reference,
            'saisi_par' => $p->createur?->name,
            'total_debit' => round((float) $lignes->sum('Debit'), 2),
            'total_credit' => round((float) $lignes->sum('Credit'), 2),
            // null : prête à être validée
            'motif' => EcritureGuard::controlerPieceValidable($p),
            'lignes' => $lignes->map(fn ($l) => [
                'compte' => $l->sousCompte?->Code_sous_compte,
                'libelle_compte' => $l->sousCompte?->Libelle,
                'libelle' => $l->Libelle,
                'debit' => (float) $l->Debit,
                'credit' => (float) $l->Credit,
            ])->all(),
        ];
    }
}
