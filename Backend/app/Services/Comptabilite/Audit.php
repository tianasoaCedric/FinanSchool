<?php

namespace App\Services\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Piste d'audit comptable : enregistre qui a fait quoi, quand, et l'état
 * avant / après (table audit_comptable, en ajout seul).
 *
 * L'enregistrement se fait dans la transaction de l'action : si l'action
 * échoue et est annulée, sa trace l'est aussi. Seules les actions réellement
 * effectuées apparaissent donc dans la piste.
 */
class Audit
{
    // Pièces
    public const PIECE_CREATION = 'piece.creation';
    public const PIECE_MODIFICATION = 'piece.modification';
    public const PIECE_SUPPRESSION = 'piece.suppression';
    public const PIECE_VALIDATION = 'piece.validation';
    public const PIECE_CONTRE_PASSATION = 'piece.contre_passation';
    public const IMPORT_ECRITURES = 'import.ecritures';

    // Exercices et périodes
    public const EXERCICE_OUVERTURE = 'exercice.ouverture';
    public const EXERCICE_CLOTURE = 'exercice.cloture';
    public const EXERCICE_ANNULATION_CLOTURE = 'exercice.annulation_cloture';
    public const PERIODE_CLOTURE = 'periode.cloture';
    public const DOTATIONS_GENERATION = 'dotations.generation';
    public const RESULTAT_AFFECTATION = 'resultat.affectation';

    // Plan comptable et journaux
    public const PLAN_CREATION = 'plan.creation';
    public const PLAN_MODIFICATION = 'plan.modification';
    public const PLAN_SUPPRESSION = 'plan.suppression';

    // Utilisateurs
    public const UTILISATEUR_DROITS = 'utilisateur.droits';

    /** Libellés affichés dans l'écran de consultation. */
    public const LIBELLES = [
        self::PIECE_CREATION => 'Création de pièce',
        self::PIECE_MODIFICATION => 'Modification de pièce',
        self::PIECE_SUPPRESSION => 'Suppression de pièce',
        self::PIECE_VALIDATION => 'Validation de pièce',
        self::PIECE_CONTRE_PASSATION => 'Contre-passation',
        self::IMPORT_ECRITURES => "Import d'écritures",
        self::EXERCICE_OUVERTURE => "Ouverture d'exercice",
        self::EXERCICE_CLOTURE => "Clôture d'exercice",
        self::EXERCICE_ANNULATION_CLOTURE => 'Annulation de clôture',
        self::PERIODE_CLOTURE => 'Clôture de période',
        self::DOTATIONS_GENERATION => 'Génération des dotations',
        self::RESULTAT_AFFECTATION => 'Affectation du résultat',
        self::PLAN_CREATION => 'Création (plan comptable)',
        self::PLAN_MODIFICATION => 'Modification (plan comptable)',
        self::PLAN_SUPPRESSION => 'Suppression (plan comptable)',
        self::UTILISATEUR_DROITS => "Droits d'un utilisateur",
    ];

    public static function enregistrer(
        string $action,
        string $objetType,
        ?int $objetId,
        ?string $objetLibelle,
        ?array $avant = null,
        ?array $apres = null,
        array $details = []
    ): void {
        $user = Auth::user();

        DB::table('audit_comptable')->insert([
            'date_action' => now(),
            'user_id' => $user?->id,
            'user_nom' => $user?->name,
            'action' => $action,
            'objet_type' => $objetType,
            'objet_id' => $objetId,
            'objet_libelle' => $objetLibelle !== null ? mb_substr($objetLibelle, 0, 255) : null,
            'avant' => $avant !== null ? json_encode($avant, JSON_UNESCAPED_UNICODE) : null,
            'apres' => $apres !== null ? json_encode($apres, JSON_UNESCAPED_UNICODE) : null,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    // ---- Pièces -------------------------------------------------------

    /** État lisible d'une pièce (en-tête + lignes), pour l'avant / après. */
    public static function etatPiece(MouvementEcriture $piece): array
    {
        $piece->load(['journal', 'lignes.sousCompte']);
        $lignes = $piece->lignes->sortBy('Id_Ligne_ecriture')->values();
        $validee = $lignes->isNotEmpty() && $lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE);

        return [
            'numero' => $piece->numeroAffiche(),
            'date' => substr((string) $piece->Date_mouvement, 0, 10),
            'journal' => $piece->journal?->Code,
            'libelle' => $piece->Libelle,
            'reference' => $piece->Reference,
            'statut' => $validee ? 'valide' : 'brouillon',
            'total_debit' => round((float) $lignes->sum('Debit'), 2),
            'total_credit' => round((float) $lignes->sum('Credit'), 2),
            'lignes' => $lignes->map(fn ($l) => [
                'compte' => $l->sousCompte?->Code_sous_compte,
                'libelle' => $l->Libelle,
                'debit' => (float) $l->Debit,
                'credit' => (float) $l->Credit,
            ])->all(),
        ];
    }

    public static function piece(string $action, MouvementEcriture $piece, ?array $avant = null, array $details = []): void
    {
        $apres = $action === self::PIECE_SUPPRESSION ? null : self::etatPiece($piece);
        self::enregistrer(
            $action,
            'piece',
            $piece->Id_Mouvement_ecriture,
            'Pièce ' . $piece->numeroAffiche(),
            $avant,
            $apres,
            $details
        );
    }

    /**
     * Exécute une modification d'une ou plusieurs pièces en brouillon et la
     * trace (état avant / après de chaque pièce), le tout dans une transaction.
     */
    public static function modifierPiece(int|array $pieceIds, callable $modification): mixed
    {
        $ids = array_values(array_unique(array_map('intval', (array) $pieceIds)));

        return DB::transaction(function () use ($ids, $modification) {
            $avant = [];
            foreach (MouvementEcriture::whereIn('Id_Mouvement_ecriture', $ids)->get() as $piece) {
                $avant[$piece->Id_Mouvement_ecriture] = self::etatPiece($piece);
            }

            $resultat = $modification();

            foreach (MouvementEcriture::whereIn('Id_Mouvement_ecriture', array_keys($avant))->get() as $piece) {
                self::piece(self::PIECE_MODIFICATION, $piece, $avant[$piece->Id_Mouvement_ecriture]);
            }
            return $resultat;
        });
    }
}
