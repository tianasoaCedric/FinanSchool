<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Saisie\Journal;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\ModePaiement;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\EcritureGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LigneEcritureController extends Controller
{
    /** Champs d'une ligne modifiables par l'utilisateur (jamais le statut). */
    private const CHAMPS_LIGNE = [
        'Libelle', 'Debit', 'Credit', 'Reference', 'Quantite',
        'Id_Mode_paiement', 'Id_Mouvement_ecriture', 'Id_Journal', 'Id_Sous_compte',
    ];

    private const REGLES_LIGNE = [
        'Libelle'               => 'required|string|max:255',
        'Debit'                 => 'required|numeric|min:0',
        'Credit'                => 'required|numeric|min:0',
        'Reference'             => 'nullable|string|max:50',
        'Quantite'              => 'nullable|integer',
        'Id_Mode_paiement'      => 'nullable|exists:mode_paiements,Id_Mode_paiement',
        'Id_Mouvement_ecriture' => 'required|exists:mouvement_ecritures,Id_Mouvement_ecriture',
        'Id_Journal'            => 'required|exists:journals,Id_Journal',
        'Id_Sous_compte'        => 'required|exists:sous_comptes,Id_Sous_compte',
    ];

    public function indexWithRelations(Request $request)
    {
        $query = LigneEcriture::with([
            'sousCompte.compte.rubrique.classe',
            'modePaiement',
            'mouvement',
            'journal'
        ]);

        // Filtre recherche globale
        if ($request->q) {
            $query->where(function ($q) use ($request) {
                $q->where('Libelle', 'LIKE', '%' . $request->q . '%')
                  ->orWhere('Reference', 'LIKE', '%' . $request->q . '%');
            });
        }

        // Filtre par date
        if ($request->date_debut) {
            $query->whereHas('mouvement', fn($q) => $q->where('Date_mouvement', '>=', $request->date_debut));
        }
        if ($request->date_fin) {
            $query->whereHas('mouvement', fn($q) => $q->where('Date_mouvement', '<=', $request->date_fin));
        }

        $results = $query->orderByDesc('Id_Ligne_ecriture')->paginate(50);
        return response()->json($results);
    }

    /**
     * Modification groupée de lignes non validées.
     */
    public function batchUpdate(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:ligne_ecritures,Id_Ligne_ecriture',
            'data.Libelle' => 'nullable|string|max:255',
            'data.Debit' => 'nullable|numeric|min:0',
            'data.Credit' => 'nullable|numeric|min:0',
            'data.Reference' => 'nullable|string|max:50',
        ]);

        // On garde les valeurs explicitement fournies, y compris 0 (array_filter les supprimait).
        $data = collect($request->input('data', []))
            ->only(['Libelle', 'Debit', 'Credit', 'Reference'])
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();

        $pieces = LigneEcriture::whereIn('Id_Ligne_ecriture', $request->ids)->pluck('Id_Mouvement_ecriture')->all();
        $modifiees = Audit::modifierPiece($pieces, function () use ($request, $data) {
            $lignes = LigneEcriture::whereIn('Id_Ligne_ecriture', $request->ids)->get();
            foreach ($lignes as $ligne) {
                EcritureGuard::assertLigneBrouillon(array_merge($ligne->only(self::CHAMPS_LIGNE), $data), $ligne);
                $ligne->update($data);
            }
            return $lignes->count();
        });

        return response()->json(['message' => "{$modifiees} écriture(s) modifiée(s)"]);
    }

    // ========== Mouvements ==========

    public function getMouvementsComplets(Request $request)
    {
        $limit = $request->query('limit', 50);

        try {
            $mouvements = MouvementEcriture::with([
                'lignes.sousCompte.compte.rubrique.classe',
                'lignes.modePaiement',
                'journal'
            ])
                // Mouvements sans lignes OU avec des lignes non validées
                ->where(function ($query) {
                    $query->whereHas('lignes', function ($q) {
                        $q->where('statut', '!=', 'valide')
                          ->orWhereNull('statut');
                    })
                    ->orWhereDoesntHave('lignes');
                })
                ->orderBy('Date_mouvement', 'desc')
                ->paginate($limit);

            $mouvements->getCollection()->transform(function ($mouvement) {
                $totalDebit = $mouvement->lignes->sum('Debit');
                $totalCredit = $mouvement->lignes->sum('Credit');
                $difference = abs($totalDebit - $totalCredit);

                $mouvement->totals = [
                    'debit' => $totalDebit,
                    'credit' => $totalCredit,
                    'difference' => $difference,
                ];
                $mouvement->journal_libelle = $mouvement->journal
                    ? "{$mouvement->journal->Code} - {$mouvement->journal->Libelle}"
                    : "Journal #{$mouvement->Id_Journal}";
                $mouvement->date_formatted = $mouvement->Date_mouvement
                    ? \Carbon\Carbon::parse($mouvement->Date_mouvement)->format('D j M Y')
                    : null;
                $mouvement->is_equilibre = $difference < EcritureGuard::tolerance() && $totalDebit > 0;
                $mouvement->is_valide = $this->isMouvementValide($mouvement);
                // Motif empêchant la validation (null si la pièce est validable)
                $mouvement->motif_non_validable = $mouvement->is_valide
                    ? null
                    : EcritureGuard::controlerPieceValidable($mouvement);

                foreach ($mouvement->lignes as $ligne) {
                    $ligne->debit_formatted = $this->formatMontant($ligne->Debit);
                    $ligne->credit_formatted = $this->formatMontant($ligne->Credit);
                    $ligne->sousCompteSearch = $ligne->sousCompte
                        ? "{$ligne->sousCompte->Code_sous_compte} - {$ligne->sousCompte->Libelle}"
                        : '';
                }

                return $mouvement;
            });

            return response()->json($mouvements);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors du chargement des mouvements',
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    public function index()
    {
        $mouvements = MouvementEcriture::with([
            'lignes.sousCompte.compte.rubrique.classe',
            'lignes.modePaiement',
            'journal'
        ])
            ->orderBy('Date_mouvement', 'desc')
            ->get();

        return response()->json($mouvements);
    }

    /**
     * Suppression d'une pièce non validée et de ses lignes.
     */
    public function deleteMouvement($mouvementId)
    {
        $mouvement = MouvementEcriture::with('lignes')->findOrFail($mouvementId);
        EcritureGuard::assertPieceModifiable($mouvement);

        DB::transaction(function () use ($mouvement) {
            Audit::piece(Audit::PIECE_SUPPRESSION, $mouvement, Audit::etatPiece($mouvement));
            $mouvement->lignes()->delete();
            $mouvement->delete();
        });

        return response()->json([
            'message' => 'Mouvement et ses lignes supprimés avec succès'
        ]);
    }

    /**
     * Validation d'une pièce complète : équilibre, lignes correctes, exercice ouvert.
     */
    public function validerMouvementComplet($mouvementId)
    {
        $nb = DB::transaction(function () use ($mouvementId) {
            $mouvement = MouvementEcriture::with('lignes')->lockForUpdate()->findOrFail($mouvementId);
            return EcritureGuard::validerPiece($mouvement, Auth::id());
        });

        return response()->json([
            'message' => 'Mouvement validé avec succès',
            'validated' => $nb,
        ]);
    }

    // ========== Lignes ==========

    /**
     * Ajoute une ligne de contrepartie (compte du journal) pour équilibrer la pièce.
     */
    public function solderMouvement($mouvementId)
    {
        $mouvement = MouvementEcriture::with(['lignes', 'journal'])->findOrFail($mouvementId);
        EcritureGuard::assertPieceModifiable($mouvement);

        $totalDebit = $mouvement->lignes->sum('Debit');
        $totalCredit = $mouvement->lignes->sum('Credit');
        $solde = round($totalDebit - $totalCredit, 2);

        if (abs($solde) < EcritureGuard::tolerance()) {
            return response()->json(['error' => 'Le mouvement est déjà équilibré.'], 400);
        }

        $idSousCompteContrepartie = $mouvement->journal?->Id_Sous_compte;
        if (!$idSousCompteContrepartie) {
            return response()->json(['error' => 'Aucun compte de contrepartie défini pour le journal.'], 400);
        }

        $ligne = Audit::modifierPiece($mouvement->Id_Mouvement_ecriture, fn () => LigneEcriture::create([
            'Libelle' => $mouvement->lignes->count() > 0 ? $mouvement->lignes[0]->Libelle : 'Soldé',
            'Debit' => $solde < 0 ? abs($solde) : 0,
            'Credit' => $solde > 0 ? abs($solde) : 0,
            'Reference' => null,
            'Quantite' => 1,
            'Id_Mode_paiement' => null,
            'Id_Mouvement_ecriture' => $mouvement->Id_Mouvement_ecriture,
            'Id_Journal' => $mouvement->Id_Journal,
            'Id_Sous_compte' => $idSousCompteContrepartie,
            'statut' => EcritureGuard::STATUT_BROUILLON,
        ]));

        return response()->json([
            'message' => 'Mouvement soldé avec contrepartie.',
            'ligne' => $ligne
        ]);
    }

    public function show($id)
    {
        return LigneEcriture::findOrFail($id);
    }

    public function store(Request $request)
    {
        $request->validate(self::REGLES_LIGNE);
        $data = $request->only(self::CHAMPS_LIGNE);
        EcritureGuard::assertLigneBrouillon($data);

        $ligne = Audit::modifierPiece(
            (int) $data['Id_Mouvement_ecriture'],
            fn () => LigneEcriture::create($data + ['statut' => EcritureGuard::STATUT_BROUILLON])
        );

        return response()->json([
            'message' => 'Ligne créée avec succès',
            'data'    => $ligne
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate(self::REGLES_LIGNE);
        $ligne = LigneEcriture::findOrFail($id);
        $data = $request->only(self::CHAMPS_LIGNE);
        EcritureGuard::assertLigneBrouillon($data, $ligne);

        Audit::modifierPiece((int) $ligne->Id_Mouvement_ecriture, fn () => $ligne->update($data));

        return response()->json([
            'message' => 'Ligne mise à jour avec succès',
            'data'    => $ligne
        ]);
    }

    public function destroy($id)
    {
        $ligne = LigneEcriture::findOrFail($id);
        if ($ligne->statut === EcritureGuard::STATUT_VALIDE) {
            throw ValidationException::withMessages(['ecriture' => ['Cette ligne est validée : elle ne peut plus être supprimée.']]);
        }
        $mouvement = MouvementEcriture::with('lignes')->findOrFail($ligne->Id_Mouvement_ecriture);
        EcritureGuard::assertPieceModifiable($mouvement);

        Audit::modifierPiece((int) $mouvement->Id_Mouvement_ecriture, fn () => $ligne->delete());

        return response()->json(['message' => 'Ligne supprimée avec succès']);
    }

    /**
     * Ancienne validation ligne par ligne : on valide désormais toujours la pièce
     * entière (une ligne seule ne peut pas être équilibrée).
     */
    public function valider($id)
    {
        $ligne = LigneEcriture::findOrFail($id);
        if ($ligne->statut === EcritureGuard::STATUT_VALIDE) {
            return response()->json(['error' => 'Cette écriture est déjà validée'], 400);
        }

        return $this->validerMouvementComplet($ligne->Id_Mouvement_ecriture);
    }

    public function saveLignesBatch(Request $request)
    {
        $lignes = $request->input('lignes', []);
        foreach ($lignes as $ligneData) {
            validator($ligneData, self::REGLES_LIGNE)->validate();
        }

        $pieces = collect($lignes)->pluck('Id_Mouvement_ecriture')->filter()->all();
        $results = Audit::modifierPiece($pieces, function () use ($lignes) {
            $results = [];
            foreach ($lignes as $ligneData) {
                $data = collect($ligneData)->only(self::CHAMPS_LIGNE)->all();

                if (isset($ligneData['Id_Ligne_ecriture'])) {
                    $ligne = LigneEcriture::find($ligneData['Id_Ligne_ecriture']);
                    if ($ligne) {
                        EcritureGuard::assertLigneBrouillon($data, $ligne);
                        $ligne->update($data);
                        $results[] = $ligne;
                    }
                } else {
                    EcritureGuard::assertLigneBrouillon($data);
                    $results[] = LigneEcriture::create($data + ['statut' => EcritureGuard::STATUT_BROUILLON]);
                }
            }
            return $results;
        });

        return response()->json([
            'message' => 'Lignes sauvegardées avec succès',
            'lignes'  => $results
        ]);
    }

    /**
     * Valide toutes les pièces en attente qui respectent les règles (équilibre,
     * lignes correctes, exercice ouvert). Les autres sont listées avec leur motif.
     */
    public function validerToutesLesEcritures()
    {
        return response()->json($this->validerPiecesEnAttente(MouvementEcriture::query()));
    }

    /**
     * Valide les pièces en attente d'une période (et éventuellement d'un journal).
     */
    public function validerEcrituresPeriode(Request $request)
    {
        $request->validate([
            'date_debut' => 'required|date',
            'date_fin'   => 'required|date|after_or_equal:date_debut',
            'journal_id' => 'nullable|exists:journals,Id_Journal'
        ]);

        $query = MouvementEcriture::query()
            ->whereBetween('Date_mouvement', [$request->date_debut, $request->date_fin]);
        if ($request->filled('journal_id')) {
            $query->where('Id_Journal', $request->journal_id);
        }

        $resultat = $this->validerPiecesEnAttente($query);
        $resultat['periode'] = \Carbon\Carbon::parse($request->date_debut)->format('d/m/Y')
            . ' - ' . \Carbon\Carbon::parse($request->date_fin)->format('d/m/Y');

        return response()->json($resultat);
    }

    /**
     * Valide pièce par pièce. Chaque pièce validable est validée dans sa propre
     * transaction, pour qu'une erreur sur l'une n'annule pas les autres.
     */
    private function validerPiecesEnAttente($query): array
    {
        $userId = Auth::id();
        $lignesValidees = 0;
        $piecesValidees = 0;
        $ignorees = [];

        $query->whereHas('lignes', function ($q) {
            $q->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut');
        })
            ->with('lignes')
            ->chunkById(200, function ($mouvements) use ($userId, &$lignesValidees, &$piecesValidees, &$ignorees) {
                foreach ($mouvements as $mouvement) {
                    $motif = EcritureGuard::controlerPieceValidable($mouvement);
                    if ($motif !== null) {
                        $ignorees[] = ['numero' => $mouvement->numeroAffiche(), 'motif' => $motif];
                        continue;
                    }
                    $lignesValidees += DB::transaction(fn () => EcritureGuard::validerPiece($mouvement, $userId));
                    $piecesValidees++;
                }
            }, 'Id_Mouvement_ecriture');

        $message = $piecesValidees === 0
            ? 'Aucune pièce validable'
            : "{$piecesValidees} pièce(s) validée(s) ({$lignesValidees} ligne(s))";
        if ($ignorees) {
            $message .= ' — ' . count($ignorees) . ' pièce(s) ignorée(s)';
        }

        return [
            'message' => $message,
            'validated' => $lignesValidees,
            'pieces_validees' => $piecesValidees,
            'pieces_ignorees' => $ignorees,
        ];
    }

    /**
     * Rapport avant validation.
     */
    public function getRapportValidation()
    {
        try {
            $nonValidee = fn ($q) => $q->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut');

            $piecesEnAttente = MouvementEcriture::with('lignes')
                ->whereHas('lignes', $nonValidee)
                ->get();
            $piecesValidables = $piecesEnAttente->filter(fn ($m) => EcritureGuard::controlerPieceValidable($m) === null);

            $stats = [
                'total_non_validees' => LigneEcriture::where($nonValidee)->count(),
                'valides' => LigneEcriture::where('statut', EcritureGuard::STATUT_VALIDE)->count(),
                'invalides' => LigneEcriture::where($nonValidee)
                    ->where(function ($q) {
                        // Lignes avec débit ET crédit, sans montant, ou négatives
                        $q->where(fn ($s) => $s->where('Debit', '>', 0)->where('Credit', '>', 0))
                          ->orWhere(fn ($s) => $s->where('Debit', '=', 0)->where('Credit', '=', 0))
                          ->orWhere('Debit', '<', 0)
                          ->orWhere('Credit', '<', 0);
                    })
                    ->count(),
                'mouvements_non_equilibres' => $piecesEnAttente
                    ->filter(fn ($m) => abs($m->lignes->sum('Debit') - $m->lignes->sum('Credit')) >= EcritureGuard::tolerance())
                    ->count(),
                'pieces_en_attente' => $piecesEnAttente->count(),
                'pieces_validables' => $piecesValidables->count(),
                // Nombre de lignes que « Valider toutes » validera réellement
                'validables' => $piecesValidables->sum(fn ($m) => $m->lignes->count()),
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Erreur lors de la génération du rapport',
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    public function getOptions()
    {
        try {
            $journals = Journal::select('Id_Journal', 'Code', 'Libelle')->get()
                ->map(fn($j) => [
                    'Id_Journal' => $j->Id_Journal,
                    'Code' => $j->Code,
                    'Libelle' => $j->Libelle,
                    'display_text' => "{$j->Code} - {$j->Libelle}"
                ]);
            $sousComptes = SousCompte::select('Id_Sous_compte', 'Code_sous_compte', 'Libelle')
                ->get()
                ->map(fn($c) => [
                    'Id_Sous_compte' => $c->Id_Sous_compte,
                    'Code_sous_compte' => $c->Code_sous_compte,
                    'Libelle' => $c->Libelle,
                    'display_text' => "{$c->Code_sous_compte} - {$c->Libelle}"
                ]);
            $modesPaiement = ModePaiement::select('Id_Mode_paiement', 'Libelle')->get();
            return response()->json([
                'journals'      => $journals,
                'sousComptes'   => $sousComptes,
                'modesPaiement' => $modesPaiement
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erreur lors du chargement des options',
                'message' => config('app.debug') ? $e->getMessage() : 'Erreur serveur'
            ], 500);
        }
    }

    public function searchSousComptes(Request $request)
    {
        $searchTerm = $request->get('q', '');
        if (strlen($searchTerm) < 2) return response()->json([]);
        $sousComptes = SousCompte::where('Code_sous_compte', 'LIKE', "%{$searchTerm}%")
            ->orWhere('Libelle', 'LIKE', "%{$searchTerm}%")
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'Id_Sous_compte' => $c->Id_Sous_compte,
                'Code_sous_compte' => $c->Code_sous_compte,
                'Libelle' => $c->Libelle,
                'display_text' => "{$c->Code_sous_compte} - {$c->Libelle}"
            ]);
        return response()->json($sousComptes);
    }

    // ========== Utilitaires internes ==========
    private function formatMontant($montant)
    {
        return number_format(floatval($montant), 2, ',', ' ');
    }

    private function isMouvementValide($mouvement)
    {
        return $mouvement->lignes && $mouvement->lignes->count() > 0 &&
            $mouvement->lignes->every(fn($l) => $l->statut === EcritureGuard::STATUT_VALIDE);
    }
}
