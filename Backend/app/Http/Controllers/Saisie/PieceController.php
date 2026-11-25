<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\EcritureGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Saisie par pièce complète : en-tête + lignes envoyés en une seule fois.
 * Toutes les règles de partie double passent par EcritureGuard.
 */
class PieceController extends Controller
{
    private const DISQUE = 'local';

    /**
     * Liste des pièces avec filtres (statut, journal, période, recherche).
     * GET /api/pieces
     */
    public function index(Request $request)
    {
        $request->validate([
            'statut' => 'nullable|in:brouillon,valide,tous',
            'journal' => 'nullable|exists:journals,Id_Journal',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $sousRequeteTotaux = LigneEcriture::select('Id_Mouvement_ecriture')
            ->selectRaw('SUM("Debit") as total_debit, SUM("Credit") as total_credit, COUNT(*) as nb_lignes')
            ->selectRaw("BOOL_AND(statut = 'valide') as est_valide")
            ->selectRaw('MAX(valide_par) as valide_par')
            // Libellé de repli pour les pièces saisies avant l'en-tête complet
            ->selectRaw('MIN("Libelle") as libelle_ligne')
            ->groupBy('Id_Mouvement_ecriture');

        $query = MouvementEcriture::query()
            ->leftJoinSub($sousRequeteTotaux, 't', 't.Id_Mouvement_ecriture', '=', 'mouvement_ecritures.Id_Mouvement_ecriture')
            ->with(['journal:Id_Journal,Code,Libelle', 'createur:id,name'])
            ->select('mouvement_ecritures.*', 't.total_debit', 't.total_credit', 't.nb_lignes', 't.est_valide', 't.valide_par', 't.libelle_ligne')
            ->selectRaw('EXISTS (SELECT 1 FROM mouvement_ecritures x WHERE x.id_piece_origine = mouvement_ecritures."Id_Mouvement_ecriture") as contre_passee');

        $statut = $request->input('statut', 'tous');
        if ($statut === 'valide') {
            $query->where('t.est_valide', true);
        } elseif ($statut === 'brouillon') {
            $query->where(fn ($q) => $q->where('t.est_valide', false)->orWhereNull('t.est_valide'));
        }
        if ($request->filled('journal')) {
            $query->where('mouvement_ecritures.Id_Journal', $request->journal);
        }
        if ($request->filled('date_debut')) {
            $query->where('Date_mouvement', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->where('Date_mouvement', '<=', $request->date_fin);
        }
        if ($request->filled('q')) {
            $terme = '%' . $request->q . '%';
            $query->where(function ($q) use ($terme) {
                $q->where('Numero_piece', 'ILIKE', $terme)
                  ->orWhere('mouvement_ecritures.Libelle', 'ILIKE', $terme)
                  ->orWhere('mouvement_ecritures.Reference', 'ILIKE', $terme)
                  ->orWhereHas('lignes', fn ($l) => $l->where('Libelle', 'ILIKE', $terme));
            });
        }

        $pieces = $query->orderByDesc('Date_mouvement')
            ->orderByDesc('mouvement_ecritures.Id_Mouvement_ecriture')
            ->paginate($request->input('per_page', 25));

        $valideurs = User::whereIn('id', $pieces->pluck('valide_par')->filter()->unique())->pluck('name', 'id');

        $pieces->getCollection()->transform(fn ($m) => [
            'id' => $m->Id_Mouvement_ecriture,
            'numero' => $m->numeroAffiche(),
            'date' => $m->Date_mouvement,
            'journal' => $m->journal ? ['id' => $m->journal->Id_Journal, 'code' => $m->journal->Code, 'libelle' => $m->journal->Libelle] : null,
            'libelle' => $m->Libelle ?: $m->libelle_ligne,
            'reference' => $m->Reference,
            'total_debit' => (float) $m->total_debit,
            'total_credit' => (float) $m->total_credit,
            'nb_lignes' => (int) $m->nb_lignes,
            'equilibree' => $m->nb_lignes > 0 && abs((float) $m->total_debit - (float) $m->total_credit) < EcritureGuard::TOLERANCE,
            'statut' => $m->est_valide ? 'valide' : 'brouillon',
            'type_piece' => $m->type_piece ?? MouvementEcriture::TYPE_NORMALE,
            'contre_passee' => (bool) $m->contre_passee,
            'saisi_par' => $m->createur?->name,
            'valide_par' => $m->valide_par ? ($valideurs[$m->valide_par] ?? null) : null,
            'a_justificatif' => (bool) $m->justificatif_nom,
        ]);

        return response()->json($pieces);
    }

    /**
     * Détail d'une pièce (pour le formulaire).
     * GET /api/pieces/{id}
     */
    public function show($id)
    {
        $piece = MouvementEcriture::with(['journal', 'createur:id,name', 'lignes.sousCompte'])->findOrFail($id);
        return response()->json($this->serialiser($piece));
    }

    /**
     * Création d'une pièce complète.
     * POST /api/pieces  (JSON, ou multipart avec `lignes` en JSON + `justificatif`)
     */
    public function store(Request $request)
    {
        $donnees = $this->valider($request);

        $piece = DB::transaction(function () use ($request, $donnees) {
            EcritureGuard::exerciceOuvertPour($donnees['Date_mouvement']);
            $journal = Journal::findOrFail($donnees['Id_Journal']);

            $piece = MouvementEcriture::create([
                'Date_mouvement' => $donnees['Date_mouvement'],
                'Id_Journal' => $journal->Id_Journal,
                'Libelle' => $donnees['Libelle'] ?? null,
                'Reference' => $donnees['Reference'] ?? null,
                'a_extourner' => (bool) ($donnees['a_extourner'] ?? false),
                'created_by' => Auth::id(),
                // Numéro définitif attribué à la validation (EcritureGuard::validerPiece)
            ]);

            $this->enregistrerLignes($piece, $donnees['lignes']);
            $this->enregistrerJustificatif($piece, $request);
            Audit::piece(Audit::PIECE_CREATION, $piece);
            $this->validerSiDemande($piece, $donnees['action']);

            return $piece;
        });

        return response()->json([
            'message' => $donnees['action'] === 'valider'
                ? "Pièce {$piece->numeroAffiche()} enregistrée et validée"
                : "Pièce {$piece->numeroAffiche()} enregistrée en brouillon",
            'piece' => $this->serialiser($piece->fresh(['journal', 'createur:id,name', 'lignes.sousCompte'])),
        ], 201);
    }

    /**
     * Modification d'une pièce en brouillon (en-tête + remplacement des lignes).
     * PUT /api/pieces/{id}  (ou POST avec _method=PUT en multipart)
     */
    public function update(Request $request, $id)
    {
        $donnees = $this->valider($request);

        $piece = DB::transaction(function () use ($request, $donnees, $id) {
            $piece = MouvementEcriture::with('lignes')->lockForUpdate()->findOrFail($id);
            EcritureGuard::assertPieceModifiable($piece);
            EcritureGuard::exerciceOuvertPour($donnees['Date_mouvement']);
            $avant = Audit::etatPiece($piece);

            $journal = Journal::findOrFail($donnees['Id_Journal']);

            // Un brouillon n'a pas encore de numéro : changer sa date ou son
            // journal n'a donc aucun effet sur la numérotation.
            $piece->update([
                'Date_mouvement' => $donnees['Date_mouvement'],
                'Id_Journal' => $journal->Id_Journal,
                'Libelle' => $donnees['Libelle'] ?? null,
                'Reference' => $donnees['Reference'] ?? null,
                'a_extourner' => (bool) ($donnees['a_extourner'] ?? false),
            ]);

            $piece->lignes()->delete();
            $this->enregistrerLignes($piece, $donnees['lignes']);
            if ($request->boolean('supprimer_justificatif')) {
                $this->supprimerJustificatif($piece);
            }
            $this->enregistrerJustificatif($piece, $request);
            Audit::piece(Audit::PIECE_MODIFICATION, $piece, $avant);
            $this->validerSiDemande($piece, $donnees['action']);

            return $piece;
        });

        return response()->json([
            'message' => $donnees['action'] === 'valider'
                ? "Pièce {$piece->numeroAffiche()} enregistrée et validée"
                : "Pièce {$piece->numeroAffiche()} enregistrée",
            'piece' => $this->serialiser($piece->fresh(['journal', 'createur:id,name', 'lignes.sousCompte'])),
        ]);
    }

    /**
     * Suppression d'une pièce en brouillon.
     * DELETE /api/pieces/{id}
     */
    public function destroy($id)
    {
        $piece = MouvementEcriture::with('lignes')->findOrFail($id);
        EcritureGuard::assertPieceModifiable($piece);

        DB::transaction(function () use ($piece) {
            Audit::piece(Audit::PIECE_SUPPRESSION, $piece, Audit::etatPiece($piece));
            $this->supprimerJustificatif($piece);
            $piece->lignes()->delete();
            $piece->delete();
        });

        return response()->json(['message' => "Pièce {$piece->numeroAffiche()} supprimée"]);
    }

    /**
     * Contre-passation d'une pièce validée (PCG : une écriture validée ne se
     * modifie ni ne se supprime ; on la corrige par une écriture inverse, puis
     * on saisit l'écriture exacte).
     * POST /api/pieces/{id}/contrepasser  { Date_mouvement, action?: brouillon|valider }
     */
    public function contrepasser(Request $request, $id)
    {
        $donnees = $request->validate([
            'Date_mouvement' => 'required|date',
            'Libelle' => 'nullable|string|max:255',
            'action' => 'nullable|in:brouillon,valider',
        ]);

        $extourne = DB::transaction(function () use ($donnees, $id) {
            $origine = MouvementEcriture::with('lignes')->lockForUpdate()->findOrFail($id);
            $motif = self::motifNonContrepassable($origine);
            if ($motif) {
                throw ValidationException::withMessages(['ecriture' => [$motif]]);
            }
            if ($donnees['Date_mouvement'] < substr($origine->Date_mouvement, 0, 10)) {
                throw ValidationException::withMessages(['Date_mouvement' => ['La contre-passation ne peut pas être antérieure à la pièce d\'origine.']]);
            }
            EcritureGuard::exerciceOuvertPour($donnees['Date_mouvement']);

            $extourne = EcritureGuard::contrePasser(
                $origine, $donnees['Date_mouvement'], MouvementEcriture::TYPE_EXTOURNE, Auth::id(),
                $donnees['Libelle'] ?? null
            );
            if (($donnees['action'] ?? 'valider') === 'valider') {
                EcritureGuard::validerPiece($extourne->load('lignes'), Auth::id());
            }
            return $extourne;
        });

        return response()->json([
            'message' => "Pièce {$extourne->numeroAffiche()} : contre-passation enregistrée. Saisissez maintenant l'écriture correcte.",
            'piece' => $this->serialiser($extourne->fresh(['journal', 'createur:id,name', 'lignes.sousCompte'])),
        ], 201);
    }

    private static function motifNonContrepassable(MouvementEcriture $piece): ?string
    {
        $piece->loadMissing('lignes');
        if ($piece->lignes->isEmpty() || $piece->lignes->contains(fn ($l) => $l->statut !== EcritureGuard::STATUT_VALIDE)) {
            return 'Seule une pièce validée se contre-passe ; un brouillon se modifie directement.';
        }
        if (in_array($piece->type_piece, MouvementEcriture::TYPES_TECHNIQUES, true)) {
            return 'Les écritures de clôture et d\'à-nouveaux se corrigent en annulant la clôture.';
        }
        if (in_array($piece->type_piece, [MouvementEcriture::TYPE_EXTOURNE, MouvementEcriture::TYPE_EXTOURNE_AUTO], true)) {
            return 'Une contre-passation ne se contre-passe pas : saisissez l\'écriture correcte.';
        }
        $existante = MouvementEcriture::where('id_piece_origine', $piece->Id_Mouvement_ecriture)->first();
        if ($existante) {
            return "Pièce déjà contre-passée par {$existante->numeroAffiche()}.";
        }
        return null;
    }

    /**
     * Téléchargement du justificatif (authentifié).
     * GET /api/pieces/{id}/justificatif
     */
    public function justificatif($id)
    {
        $piece = MouvementEcriture::findOrFail($id);
        if (!$piece->justificatif_path || !Storage::disk(self::DISQUE)->exists($piece->justificatif_path)) {
            abort(404, 'Aucun justificatif pour cette pièce');
        }
        return Storage::disk(self::DISQUE)->download($piece->justificatif_path, $piece->justificatif_nom);
    }

    // ------------------------------------------------------------------

    private function valider(Request $request): array
    {
        // En multipart, les lignes arrivent sous forme de chaîne JSON.
        if (is_string($request->input('lignes'))) {
            $request->merge(['lignes' => json_decode($request->input('lignes'), true) ?? []]);
        }

        $donnees = $request->validate([
            'Date_mouvement' => 'required|date',
            'Id_Journal' => 'required|exists:journals,Id_Journal',
            'Libelle' => 'nullable|string|max:255',
            'Reference' => 'nullable|string|max:100',
            'a_extourner' => 'nullable|boolean',
            'action' => 'nullable|in:brouillon,valider',
            'justificatif' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
            'lignes' => 'required|array|min:1',
            'lignes.*.Id_Sous_compte' => 'required|exists:sous_comptes,Id_Sous_compte',
            'lignes.*.Libelle' => 'nullable|string|max:255',
            'lignes.*.Debit' => 'nullable|numeric|min:0',
            'lignes.*.Credit' => 'nullable|numeric|min:0',
            'lignes.*.Id_Mode_paiement' => 'nullable|exists:mode_paiements,Id_Mode_paiement',
            'lignes.*.Quantite' => 'nullable|integer|min:0',
        ], [
            'lignes.required' => 'La pièce doit comporter au moins une ligne.',
            'lignes.*.Id_Sous_compte.required' => 'Chaque ligne doit avoir un compte.',
            'lignes.*.Debit.min' => 'Les montants doivent être positifs.',
            'lignes.*.Credit.min' => 'Les montants doivent être positifs.',
            'justificatif.mimes' => 'Le justificatif doit être un PDF ou une image (jpg, png, webp).',
            'justificatif.max' => 'Le justificatif ne doit pas dépasser 5 Mo.',
        ]);

        foreach ($donnees['lignes'] as $i => $ligne) {
            $d = (float) ($ligne['Debit'] ?? 0);
            $c = (float) ($ligne['Credit'] ?? 0);
            if ($d > 0 && $c > 0) {
                throw ValidationException::withMessages([
                    "lignes.$i" => ['Ligne ' . ($i + 1) . ' : une ligne ne peut pas être à la fois au débit et au crédit.'],
                ]);
            }
        }

        $donnees['action'] = $donnees['action'] ?? 'brouillon';
        return $donnees;
    }

    private function enregistrerLignes(MouvementEcriture $piece, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            LigneEcriture::create([
                'Libelle' => trim($ligne['Libelle'] ?? '') !== '' ? $ligne['Libelle'] : ($piece->Libelle ?: 'Écriture'),
                'Debit' => round((float) ($ligne['Debit'] ?? 0), 2),
                'Credit' => round((float) ($ligne['Credit'] ?? 0), 2),
                'Reference' => $piece->Reference ? mb_substr($piece->Reference, 0, 50) : null,
                'Quantite' => $ligne['Quantite'] ?? null,
                'Id_Mode_paiement' => $ligne['Id_Mode_paiement'] ?? null,
                'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                'Id_Journal' => $piece->Id_Journal,
                'Id_Sous_compte' => $ligne['Id_Sous_compte'],
                'statut' => EcritureGuard::STATUT_BROUILLON,
            ]);
        }
    }

    private function validerSiDemande(MouvementEcriture $piece, string $action): void
    {
        if ($action === 'valider') {
            EcritureGuard::validerPiece($piece->load('lignes'), Auth::id());
        }
    }

    private function enregistrerJustificatif(MouvementEcriture $piece, Request $request): void
    {
        if (!$request->hasFile('justificatif')) {
            return;
        }
        $this->supprimerJustificatif($piece);
        $fichier = $request->file('justificatif');
        $chemin = $fichier->store("justificatifs/{$piece->Id_Mouvement_ecriture}", self::DISQUE);
        $piece->update([
            'justificatif_path' => $chemin,
            'justificatif_nom' => $fichier->getClientOriginalName(),
        ]);
    }

    private function supprimerJustificatif(MouvementEcriture $piece): void
    {
        if ($piece->justificatif_path) {
            Storage::disk(self::DISQUE)->delete($piece->justificatif_path);
            $piece->update(['justificatif_path' => null, 'justificatif_nom' => null]);
        }
    }

    private function serialiser(MouvementEcriture $piece): array
    {
        $lignes = $piece->lignes->sortBy('Id_Ligne_ecriture')->values();
        $estValide = $lignes->isNotEmpty() && $lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE);
        $valideur = $estValide ? User::find($lignes->first()->valide_par) : null;
        $totalDebit = round((float) $lignes->sum('Debit'), 2);
        $totalCredit = round((float) $lignes->sum('Credit'), 2);

        $origine = $piece->id_piece_origine ? MouvementEcriture::find($piece->id_piece_origine) : null;
        $contrePassee = MouvementEcriture::where('id_piece_origine', $piece->Id_Mouvement_ecriture)->first();

        return [
            'id' => $piece->Id_Mouvement_ecriture,
            'numero' => $piece->numeroAffiche(),
            'type_piece' => $piece->type_piece ?? MouvementEcriture::TYPE_NORMALE,
            'a_extourner' => (bool) $piece->a_extourner,
            'origine' => $origine ? ['id' => $origine->Id_Mouvement_ecriture, 'numero' => $origine->numeroAffiche()] : null,
            'contre_passee_par' => $contrePassee ? ['id' => $contrePassee->Id_Mouvement_ecriture, 'numero' => $contrePassee->numeroAffiche()] : null,
            'motif_non_contrepassable' => $estValide ? self::motifNonContrepassable($piece) : null,
            'Date_mouvement' => $piece->Date_mouvement,
            'Id_Journal' => $piece->Id_Journal,
            'journal' => $piece->journal ? [
                'id' => $piece->journal->Id_Journal,
                'code' => $piece->journal->Code,
                'libelle' => $piece->journal->Libelle,
                'Id_Sous_compte' => $piece->journal->Id_Sous_compte,
            ] : null,
            'Libelle' => $piece->Libelle,
            'Reference' => $piece->Reference,
            'justificatif_nom' => $piece->justificatif_nom,
            'statut' => $estValide ? 'valide' : 'brouillon',
            'modifiable' => !$estValide,
            'saisi_par' => $piece->createur?->name,
            'date_saisie' => $piece->date_saisie,
            'valide_par' => $valideur?->name,
            'date_validation' => $estValide ? $lignes->first()->date_validation : null,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'motif_non_validable' => $estValide ? null : EcritureGuard::controlerPieceValidable($piece),
            'lignes' => $lignes->map(fn ($l) => [
                'id' => $l->Id_Ligne_ecriture,
                'Id_Sous_compte' => $l->Id_Sous_compte,
                'compte' => $l->sousCompte ? ['code' => $l->sousCompte->Code_sous_compte, 'libelle' => $l->sousCompte->Libelle] : null,
                'Libelle' => $l->Libelle,
                'Debit' => (float) $l->Debit,
                'Credit' => (float) $l->Credit,
                'Id_Mode_paiement' => $l->Id_Mode_paiement,
                'Quantite' => $l->Quantite,
            ])->all(),
        ];
    }
}
