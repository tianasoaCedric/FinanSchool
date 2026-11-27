<?php

namespace App\Http\Controllers\Tiers;

use App\Http\Controllers\Controller;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Tiers\Lettrage;
use App\Services\Comptabilite\EcritureGuard;
use App\Services\Comptabilite\LettrageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Écran de lettrage : lignes d'un compte de tiers, lettrage manuel ou
 * automatique, délettrage.
 */
class LettrageController extends Controller
{
    /**
     * Comptes lettrables (classe 4) ayant des lignes validées, avec le nombre
     * de lignes non lettrées. GET /api/lettrage/comptes
     */
    public function comptes()
    {
        $debut = LettrageService::debutOuvert();
        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('s.Code_sous_compte', 'like', '4%')
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->groupBy('s.Id_Sous_compte', 's.Code_sous_compte', 's.Libelle')
            ->selectRaw('s."Id_Sous_compte" as id, s."Code_sous_compte" as code, s."Libelle" as libelle')
            ->selectRaw('COUNT(*) FILTER (WHERE l.id_lettrage IS NULL) as non_lettrees')
            ->selectRaw('ROUND(SUM(CASE WHEN l.id_lettrage IS NULL THEN l."Debit" - l."Credit" ELSE 0 END)::numeric, 2) as solde_non_lettre')
            ->orderBy('s.Code_sous_compte');
        if ($debut) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }
        return response()->json($q->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'code' => $r->code, 'libelle' => $r->libelle,
            'non_lettrees' => (int) $r->non_lettrees, 'solde_non_lettre' => (float) $r->solde_non_lettre,
        ]));
    }

    /**
     * Lignes validées d'un compte (et d'un tiers) à lettrer.
     * GET /api/lettrage/lignes?Id_Sous_compte=&id_tiers=&etat=non_lettre|lettre|tous
     */
    public function lignes(Request $request)
    {
        $request->validate([
            'Id_Sous_compte' => 'required_without:id_tiers|nullable|integer|exists:sous_comptes,Id_Sous_compte',
            'id_tiers' => 'nullable|integer|exists:tiers,id',
            'etat' => 'nullable|in:non_lettre,lettre,tous',
        ]);

        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->leftJoin('journals as j', 'j.Id_Journal', '=', 'm.Id_Journal')
            ->leftJoin('tiers as t', 't.id', '=', 'l.id_tiers')
            ->leftJoin('lettrages as lt', 'lt.id', '=', 'l.id_lettrage')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('s.Code_sous_compte', 'like', '4%')
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->select('l.Id_Ligne_ecriture as id', 'm.Id_Mouvement_ecriture as piece_id', 'm.Numero_piece', 'm.Date_mouvement as date',
                'm.Reference as reference', 'j.Code as journal', 's.Id_Sous_compte as id_sous_compte', 's.Code_sous_compte as compte',
                'l.Libelle as libelle', 'l.Debit as debit', 'l.Credit as credit',
                'l.id_tiers', 't.code as tiers_code', 't.nom as tiers_nom', 'l.id_lettrage', 'lt.code as lettrage')
            ->orderBy('m.Date_mouvement')->orderBy('l.Id_Ligne_ecriture');
        if ($request->filled('Id_Sous_compte')) {
            $q->where('l.Id_Sous_compte', $request->Id_Sous_compte);
        }
        if ($request->filled('id_tiers')) {
            $q->where('l.id_tiers', $request->id_tiers);
        }
        $etat = $request->input('etat', 'non_lettre');
        if ($etat === 'non_lettre') {
            $q->whereNull('l.id_lettrage');
        } elseif ($etat === 'lettre') {
            $q->whereNotNull('l.id_lettrage');
        }
        if ($debut = LettrageService::debutOuvert()) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }

        $lignes = $q->limit(2000)->get()->map(fn ($r) => [
            'id' => $r->id,
            'piece_id' => $r->piece_id,
            'piece' => $r->Numero_piece ?: 'BR-' . $r->piece_id,
            'date' => substr((string) $r->date, 0, 10),
            'journal' => $r->journal,
            'reference' => $r->reference,
            'Id_Sous_compte' => (int) $r->id_sous_compte,
            'compte' => $r->compte,
            'libelle' => $r->libelle,
            'debit' => (float) $r->debit,
            'credit' => (float) $r->credit,
            'tiers' => $r->id_tiers ? ['id' => $r->id_tiers, 'code' => $r->tiers_code, 'nom' => $r->tiers_nom] : null,
            'id_lettrage' => $r->id_lettrage,
            'lettrage' => $r->lettrage,
        ]);

        return response()->json([
            'lignes' => $lignes,
            'totaux' => [
                'debit' => round($lignes->sum('debit'), 2),
                'credit' => round($lignes->sum('credit'), 2),
                'solde' => round($lignes->sum('debit') - $lignes->sum('credit'), 2),
            ],
        ]);
    }

    /** POST /api/lettrage  { lignes: [id, id, …] } */
    public function store(Request $request)
    {
        $donnees = $request->validate([
            'lignes' => 'required|array|min:2|max:500',
            'lignes.*' => 'integer',
        ], ['lignes.min' => 'Sélectionnez au moins deux lignes.']);
        $l = LettrageService::lettrer($donnees['lignes']);
        return response()->json([
            'message' => "Lettrage {$l->code} enregistré (" . count($donnees['lignes']) . ' lignes, ' . number_format($l->montant, 2, ',', ' ') . ').',
            'lettrage' => ['id' => $l->id, 'code' => $l->code],
        ], 201);
    }

    /** POST /api/lettrage/auto  { Id_Sous_compte?, id_tiers? } */
    public function automatique(Request $request)
    {
        $donnees = $request->validate([
            'Id_Sous_compte' => 'nullable|integer|exists:sous_comptes,Id_Sous_compte',
            'id_tiers' => 'nullable|integer|exists:tiers,id',
        ]);
        $n = LettrageService::automatique($donnees['Id_Sous_compte'] ?? null, $donnees['id_tiers'] ?? null);
        return response()->json([
            'message' => $n ? "{$n} lettrage(s) automatique(s) effectué(s)." : 'Aucune correspondance trouvée (même référence ou même montant).',
            'nombre' => $n,
        ]);
    }

    /** DELETE /api/lettrage/{id} */
    public function destroy($id)
    {
        $l = Lettrage::findOrFail($id);
        LettrageService::delettrer($l);
        return response()->json(['message' => "Lettrage {$l->code} supprimé : les lignes sont de nouveau à lettrer."]);
    }
}
