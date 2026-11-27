<?php

namespace App\Http\Controllers\Tiers;

use App\Http\Controllers\Controller;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Tiers\Tiers;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\EcritureGuard;
use App\Services\Comptabilite\TiersService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fichier des tiers (clients, fournisseurs…), relevé de compte et balance âgée.
 */
class TiersController extends Controller
{
    private const CHAMPS_AUDIT = ['code', 'type', 'nom', 'nif', 'stat', 'adresse', 'telephone', 'email', 'delai_paiement', 'Id_Sous_compte', 'actif'];

    /**
     * Liste avec solde (débit − crédit) de chaque tiers.
     * GET /api/tiers?type=&q=&actif=&per_page=  (per_page=0 : liste complète pour les sélecteurs)
     */
    public function index(Request $request)
    {
        $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Tiers::TYPES))],
            'q' => 'nullable|string|max:100',
            'actif' => 'nullable|in:0,1,tous',
            'per_page' => 'nullable|integer|min:0|max:200',
        ]);

        $q = Tiers::with('compte:Id_Sous_compte,Code_sous_compte,Libelle')->orderBy('nom');
        if ($request->filled('type')) {
            $q->where('type', $request->type);
        }
        $actif = $request->input('actif', '1');
        if ($actif !== 'tous') {
            $q->where('actif', $actif === '1');
        }
        if ($request->filled('q')) {
            $t = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('nom', 'ILIKE', $t)->orWhere('code', 'ILIKE', $t)->orWhere('nif', 'ILIKE', $t));
        }

        $soldes = $this->soldes();
        $presenter = fn (Tiers $t) => $this->presenter($t) + ['solde' => $soldes[$t->id] ?? 0.0];

        if ((int) $request->input('per_page', 25) === 0) {
            return response()->json($q->get()->map($presenter));
        }
        $page = $q->paginate($request->input('per_page', 25));
        $page->getCollection()->transform($presenter);
        return response()->json($page);
    }

    public function show($id)
    {
        $t = Tiers::with('compte')->findOrFail($id);
        return response()->json($this->presenter($t) + ['solde' => $this->soldes([$t->id])[$t->id] ?? 0.0]);
    }

    /** Code proposé pour un nouveau tiers. GET /api/tiers/prochain-code?type= */
    public function prochainCode(Request $request)
    {
        $request->validate(['type' => ['required', Rule::in(array_keys(Tiers::TYPES))]]);
        return response()->json(['code' => TiersService::prochainCode($request->type)]);
    }

    public function store(Request $request)
    {
        $donnees = $this->valider($request);
        $donnees['code'] = $donnees['code'] ?? TiersService::prochainCode($donnees['type']);

        $t = DB::transaction(function () use ($donnees) {
            $t = Tiers::create($donnees);
            Audit::enregistrer(Audit::TIERS_CREATION, 'tiers', $t->id, "{$t->code} — {$t->nom}", null, $t->only(self::CHAMPS_AUDIT));
            return $t;
        });
        return response()->json(['message' => "Tiers {$t->code} — {$t->nom} créé.", 'tiers' => $this->presenter($t->load('compte'))], 201);
    }

    public function update(Request $request, $id)
    {
        $t = Tiers::findOrFail($id);
        $donnees = $this->valider($request, $t);

        DB::transaction(function () use ($t, $donnees) {
            $avant = $t->only(self::CHAMPS_AUDIT);
            $t->update($donnees);
            Audit::enregistrer(Audit::TIERS_MODIFICATION, 'tiers', $t->id, "{$t->code} — {$t->nom}", $avant, $t->only(self::CHAMPS_AUDIT));
        });
        return response()->json(['message' => "Tiers {$t->code} enregistré.", 'tiers' => $this->presenter($t->fresh('compte'))]);
    }

    /** Suppression d'un tiers jamais utilisé ; sinon, on le désactive. */
    public function destroy($id)
    {
        $t = Tiers::findOrFail($id);
        if ($t->lignes()->exists()) {
            throw ValidationException::withMessages(['tiers' => ["{$t->nom} figure dans des écritures : désactivez-le plutôt que de le supprimer."]]);
        }
        DB::transaction(function () use ($t) {
            Audit::enregistrer(Audit::TIERS_SUPPRESSION, 'tiers', $t->id, "{$t->code} — {$t->nom}", $t->only(self::CHAMPS_AUDIT), null);
            $t->delete();
        });
        return response()->json(['message' => "Tiers {$t->code} supprimé."]);
    }

    /**
     * Relevé de compte du tiers : lignes validées (et brouillons en option),
     * solde progressif, lettrage. Période par défaut : depuis le début du
     * dernier exercice ouvert par des à-nouveaux.
     * GET /api/tiers/{id}/releve?date_debut=&date_fin=&non_lettrees=1&brouillons=1
     */
    public function releve(Request $request, $id)
    {
        $request->validate([
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'non_lettrees' => 'nullable|boolean',
            'brouillons' => 'nullable|boolean',
        ]);
        $t = Tiers::findOrFail($id);
        $fin = $request->input('date_fin');
        $debut = $request->input('date_debut') ?: TiersService::fenetre($fin);

        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->leftJoin('journals as j', 'j.Id_Journal', '=', 'm.Id_Journal')
            ->leftJoin('lettrages as lt', 'lt.id', '=', 'l.id_lettrage')
            ->where('l.id_tiers', $t->id)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->when($fin, fn ($w) => $w->where('m.Date_mouvement', '<=', $fin))
            ->select('l.Id_Ligne_ecriture as id', 'm.Id_Mouvement_ecriture as piece_id', 'm.Numero_piece', 'm.Date_mouvement as date',
                'm.Reference as reference', 'j.Code as journal', 's.Code_sous_compte as compte', 'l.Libelle as libelle',
                'l.Debit as debit', 'l.Credit as credit', 'l.statut', 'lt.code as lettrage', 'l.id_lettrage')
            ->orderBy('m.Date_mouvement')->orderBy('l.Id_Ligne_ecriture');
        if ($debut) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }
        if (!$request->boolean('brouillons')) {
            $q->where('l.statut', EcritureGuard::STATUT_VALIDE);
        }
        if ($request->boolean('non_lettrees')) {
            $q->whereNull('l.id_lettrage');
        }

        $solde = 0.0;
        $lignes = $q->get()->map(function ($r) use (&$solde) {
            $solde = round($solde + (float) $r->debit - (float) $r->credit, 2);
            return [
                'id' => $r->id,
                'piece_id' => $r->piece_id,
                'piece' => $r->Numero_piece ?: 'BR-' . $r->piece_id,
                'date' => substr((string) $r->date, 0, 10),
                'echeance' => null,
                'journal' => $r->journal,
                'reference' => $r->reference,
                'compte' => $r->compte,
                'libelle' => $r->libelle,
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
                'solde' => $solde,
                'statut' => $r->statut,
                'lettrage' => $r->lettrage,
                'id_lettrage' => $r->id_lettrage,
            ];
        });
        // Échéance des factures (débit client / crédit fournisseur) non lettrées
        $lignes = $lignes->map(function ($l) use ($t) {
            $facture = $t->type === 'fournisseur' ? $l['credit'] > 0 : $l['debit'] > 0;
            if ($facture && !$l['lettrage']) {
                $l['echeance'] = Carbon::parse($l['date'])->addDays($t->delai_paiement)->toDateString();
            }
            return $l;
        });

        $nonLettre = round($lignes->whereNull('lettrage')->where('statut', EcritureGuard::STATUT_VALIDE)->sum(fn ($l) => $l['debit'] - $l['credit']), 2);
        return response()->json([
            'tiers' => $this->presenter($t->load('compte')),
            'periode' => ['debut' => $debut, 'fin' => $request->input('date_fin')],
            'lignes' => $lignes->values(),
            'totaux' => [
                'debit' => round($lignes->sum('debit'), 2),
                'credit' => round($lignes->sum('credit'), 2),
                'solde' => $solde,
                'non_lettre' => $nonLettre,
            ],
        ]);
    }

    /**
     * Balance âgée des clients (créances) ou des fournisseurs (dettes) à une
     * date : lignes non lettrées, réparties par ancienneté de l'échéance
     * (date + délai de paiement du tiers). Les règlements non lettrés
     * s'imputent sur les échéances les plus anciennes.
     * GET /api/tiers-balance-agee?type=client|fournisseur&date=
     */
    public function balanceAgee(Request $request)
    {
        $request->validate([
            'type' => 'required|in:client,fournisseur',
            'date' => 'nullable|date',
        ]);
        $date = Carbon::parse($request->input('date', now()->toDateString()))->toDateString();
        $sens = $request->type === 'client' ? 1 : -1; // créance client = débit ; dette fournisseur = crédit
        $debut = TiersService::fenetre($date);

        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('tiers as t', 't.id', '=', 'l.id_tiers')
            ->where('t.type', $request->type)
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->where('m.Date_mouvement', '<=', $date)
            // Lettrées après la date : encore ouvertes à la date
            ->where(fn ($w) => $w->whereNull('l.id_lettrage')
                ->orWhereIn('l.id_lettrage', DB::table('lettrages')->whereDate('created_at', '>', $date)->select('id')))
            ->select('l.id_tiers', 'm.Date_mouvement as date', DB::raw('(l."Debit" - l."Credit") as montant'))
            ->orderBy('m.Date_mouvement');
        if ($debut) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }

        $tranches = ['non_echu' => 0, 'j30' => 0, 'j60' => 0, 'j90' => 0, 'plus90' => 0];
        $tiers = Tiers::whereIn('id', (clone $q)->distinct()->pluck('l.id_tiers'))->get()->keyBy('id');
        $resultat = [];
        foreach ($q->get()->groupBy('id_tiers') as $idTiers => $lignes) {
            $t = $tiers[$idTiers];
            // Factures (sens normal) et règlements, en montants positifs
            $factures = [];
            $reglements = 0.0;
            foreach ($lignes as $l) {
                $m = round($sens * (float) $l->montant, 2);
                if ($m > 0) {
                    $factures[] = ['date' => substr((string) $l->date, 0, 10), 'reste' => $m];
                } else {
                    $reglements += -$m;
                }
            }
            foreach ($factures as &$f) { // imputation sur les plus anciennes
                $impute = min($f['reste'], $reglements);
                $f['reste'] = round($f['reste'] - $impute, 2);
                $reglements = round($reglements - $impute, 2);
            }
            unset($f);

            $ligne = ['id' => $t->id, 'code' => $t->code, 'nom' => $t->nom, 'delai_paiement' => $t->delai_paiement] + $tranches;
            foreach ($factures as $f) {
                if ($f['reste'] <= 0) {
                    continue;
                }
                $retard = Carbon::parse($f['date'])->addDays($t->delai_paiement)->diffInDays(Carbon::parse($date), false);
                $cle = match (true) {
                    $retard <= 0 => 'non_echu',
                    $retard <= 30 => 'j30',
                    $retard <= 60 => 'j60',
                    $retard <= 90 => 'j90',
                    default => 'plus90',
                };
                $ligne[$cle] = round($ligne[$cle] + $f['reste'], 2);
            }
            // Avance / trop-perçu non imputé
            $ligne['avance'] = $reglements;
            $ligne['total'] = round(array_sum(array_intersect_key($ligne, $tranches)) - $reglements, 2);
            if (abs($ligne['total']) >= 0.005 || $reglements > 0) {
                $resultat[] = $ligne;
            }
        }
        usort($resultat, fn ($a, $b) => $b['total'] <=> $a['total']);

        $totaux = ['avance' => 0.0, 'total' => 0.0] + $tranches;
        foreach ($resultat as $r) {
            foreach ($totaux as $k => $_) {
                $totaux[$k] = round($totaux[$k] + $r[$k], 2);
            }
        }
        return response()->json(['date' => $date, 'type' => $request->type, 'lignes' => $resultat, 'totaux' => $totaux]);
    }

    // ------------------------------------------------------------------

    private function valider(Request $request, ?Tiers $t = null): array
    {
        $donnees = $request->validate([
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('tiers', 'code')->ignore($t?->id)],
            'type' => ['required', Rule::in(array_keys(Tiers::TYPES))],
            'nom' => 'required|string|max:150',
            'nif' => 'nullable|string|max:50',
            'stat' => 'nullable|string|max:50',
            'adresse' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'delai_paiement' => 'nullable|integer|min:0|max:365',
            'Id_Sous_compte' => 'nullable|integer|exists:sous_comptes,Id_Sous_compte',
            'actif' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ], [
            'code.unique' => 'Ce code est déjà utilisé par un autre tiers.',
            'code.regex' => 'Le code ne peut contenir que des lettres, chiffres, « - », « _ » ou « . ».',
            'nom.required' => 'Le nom est obligatoire.',
        ]);
        if (!empty($donnees['Id_Sous_compte'])) {
            $code = SousCompte::find($donnees['Id_Sous_compte'])?->Code_sous_compte;
            if (!TiersService::accepteTiers($code)) {
                throw ValidationException::withMessages(['Id_Sous_compte' => ['Le compte collectif doit être un compte de classe 4 (401…, 411…).']]);
            }
        }
        if (isset($donnees['code'])) {
            $donnees['code'] = strtoupper($donnees['code']);
        }
        $donnees['delai_paiement'] = $donnees['delai_paiement'] ?? 30;
        $donnees['actif'] = $donnees['actif'] ?? true;
        return $donnees;
    }

    /** Solde (débit − crédit) par tiers, fenêtre des états financiers. */
    private function soldes(?array $ids = null): array
    {
        $s = [];
        foreach (TiersService::soldesParTiers() as $r) {
            if ($r['id_tiers'] === null || ($ids !== null && !in_array($r['id_tiers'], $ids, true))) {
                continue;
            }
            $s[$r['id_tiers']] = round(($s[$r['id_tiers']] ?? 0) + $r['solde'], 2);
        }
        return $s;
    }

    private function presenter(Tiers $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'type' => $t->type,
            'type_libelle' => Tiers::TYPES[$t->type] ?? $t->type,
            'nom' => $t->nom,
            'nif' => $t->nif,
            'stat' => $t->stat,
            'adresse' => $t->adresse,
            'telephone' => $t->telephone,
            'email' => $t->email,
            'delai_paiement' => $t->delai_paiement,
            'Id_Sous_compte' => $t->Id_Sous_compte,
            'compte' => $t->compte ? ['id' => $t->compte->Id_Sous_compte, 'code' => $t->compte->Code_sous_compte, 'libelle' => $t->compte->Libelle] : null,
            'actif' => $t->actif,
            'notes' => $t->notes,
        ];
    }
}
