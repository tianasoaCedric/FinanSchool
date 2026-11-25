<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Règles d'intégrité des écritures comptables (partie double, PCG 2005).
 *
 * Point unique de vérité utilisé par la saisie, la validation (unitaire et en
 * masse), le solde automatique et l'import. Toute violation lève une
 * ValidationException (réponse HTTP 422 avec un `message` lisible).
 */
class EcritureGuard
{
    /** Tolérance d'arrondi pour l'équilibre débit / crédit. */
    public const TOLERANCE = 0.01;

    public const STATUT_VALIDE = 'valide';
    public const STATUT_BROUILLON = 'brouillon';

    /**
     * Retourne l'exercice OUVERT contenant la date, sinon lève une erreur.
     */
    public static function exerciceOuvertPour($date): ExerciceComptable
    {
        $jour = Carbon::parse($date)->toDateString();
        $motif = self::motifDateFermee($jour);
        if ($motif !== null) {
            self::refuser($motif . '.');
        }
        return ExerciceComptable::getExerciceByDate($jour);
    }

    /**
     * Null si l'on peut passer une écriture à cette date, sinon le motif :
     * pas d'exercice, exercice clôturé, ou mois déjà clôturé (clôture
     * périodique : la chronologie des enregistrements est figée).
     */
    public static function motifDateFermee($date): ?string
    {
        $jour = Carbon::parse($date)->toDateString();
        $exercice = ExerciceComptable::getExerciceByDate($jour);

        if (!$exercice) {
            return "Aucun exercice comptable ne couvre la date du " . self::formatDate($jour);
        }
        if (!$exercice->isOuvert()) {
            return "L'exercice {$exercice->Annee_fiscale} est clôturé : aucune écriture ne peut y être saisie ou modifiée";
        }
        if ($exercice->periode_cloturee_au && $jour <= $exercice->periode_cloturee_au->toDateString()) {
            return 'La période est clôturée jusqu\'au ' . $exercice->periode_cloturee_au->format('d/m/Y')
                . ' : datez l\'écriture après cette date';
        }
        return null;
    }

    /**
     * Pièce justificative (PCG : toute écriture s'appuie sur une pièce
     * justificative datée et référencée). Les pièces générées par
     * l'application portent leur propre référence.
     */
    public static function aUneReference(MouvementEcriture $mouvement): bool
    {
        if (trim((string) $mouvement->Reference) !== '') {
            return true;
        }
        // Pièces importées avant l'en-tête : référence portée par les lignes
        return $mouvement->lignes->contains(fn ($l) => trim((string) $l->Reference) !== '');
    }

    /**
     * Contrôles avant d'enregistrer (créer ou modifier) une ligne en brouillon.
     * Une ligne à 0 / 0 est tolérée pendant la saisie ; elle sera refusée à la validation.
     */
    public static function assertLigneBrouillon(array $data, ?LigneEcriture $existante = null): MouvementEcriture
    {
        $debit = (float) ($data['Debit'] ?? 0);
        $credit = (float) ($data['Credit'] ?? 0);

        if ($debit < 0 || $credit < 0) {
            self::refuser('Les montants doivent être positifs : utilisez le débit ou le crédit pour indiquer le sens.');
        }
        if ($debit > 0 && $credit > 0) {
            self::refuser('Une ligne ne peut pas être à la fois au débit et au crédit.');
        }
        if ($existante && $existante->statut === self::STATUT_VALIDE) {
            self::refuser('Cette ligne est validée : elle ne peut plus être modifiée.');
        }

        $mouvementId = $data['Id_Mouvement_ecriture'] ?? $existante?->Id_Mouvement_ecriture;
        $mouvement = MouvementEcriture::with('lignes')->find($mouvementId);
        if (!$mouvement) {
            self::refuser('Pièce comptable introuvable.');
        }
        if ($existante && (int) $existante->Id_Mouvement_ecriture !== (int) $mouvement->Id_Mouvement_ecriture) {
            self::refuser("Une ligne ne peut pas être déplacée vers une autre pièce.");
        }

        self::assertPieceModifiable($mouvement);

        if (isset($data['Id_Journal']) && (int) $data['Id_Journal'] !== (int) $mouvement->Id_Journal) {
            self::refuser('Le journal de la ligne doit être celui de la pièce.');
        }

        return $mouvement;
    }

    /**
     * Une pièce est modifiable si elle n'a aucune ligne validée et si son
     * exercice est ouvert.
     */
    public static function assertPieceModifiable(MouvementEcriture $mouvement): void
    {
        $mouvement->loadMissing('lignes');
        if ($mouvement->lignes->contains(fn ($l) => $l->statut === self::STATUT_VALIDE)) {
            self::refuser("La pièce {$mouvement->numeroAffiche()} est validée : elle ne peut plus être modifiée ni supprimée.");
        }
        self::exerciceOuvertPour($mouvement->Date_mouvement);
    }

    /**
     * Indique si une pièce peut être validée. Retourne null si oui, sinon le motif.
     * Ne lève pas d'exception : utilisé aussi par la validation en masse.
     */
    public static function controlerPieceValidable(MouvementEcriture $mouvement): ?string
    {
        $mouvement->loadMissing('lignes');
        $lignes = $mouvement->lignes;

        if ($lignes->isNotEmpty() && $lignes->every(fn ($l) => $l->statut === self::STATUT_VALIDE)) {
            return 'Pièce déjà validée';
        }
        if ($lignes->count() < 2) {
            return 'Une pièce doit comporter au moins deux lignes';
        }
        foreach ($lignes as $ligne) {
            $d = (float) $ligne->Debit;
            $c = (float) $ligne->Credit;
            if ($d < 0 || $c < 0) {
                return 'Montant négatif sur une ligne';
            }
            if (($d > 0) === ($c > 0)) {
                return 'Chaque ligne doit avoir un montant soit au débit, soit au crédit';
            }
            if (empty($ligne->Id_Sous_compte)) {
                return 'Ligne sans compte';
            }
        }

        $totalDebit = round((float) $lignes->sum('Debit'), 2);
        $totalCredit = round((float) $lignes->sum('Credit'), 2);
        if (abs($totalDebit - $totalCredit) >= self::TOLERANCE) {
            return sprintf(
                'Pièce déséquilibrée : débit %s ≠ crédit %s',
                self::formatMontant($totalDebit),
                self::formatMontant($totalCredit)
            );
        }

        if (!self::aUneReference($mouvement)) {
            return 'Référence de la pièce justificative manquante (n° de facture, reçu, relevé…)';
        }

        return self::motifDateFermee($mouvement->Date_mouvement);
    }

    /**
     * Valide toutes les lignes d'une pièce après contrôle et lui attribue son
     * numéro définitif (PCG : numérotation continue des écritures validées ;
     * un brouillon supprimé ne laisse donc pas de trou). Lève une erreur si la
     * pièce n'est pas validable. À appeler dans une transaction.
     */
    public static function validerPiece(MouvementEcriture $mouvement, ?int $userId): int
    {
        $motif = self::controlerPieceValidable($mouvement);
        if ($motif !== null) {
            self::refuser("Validation impossible ({$mouvement->numeroAffiche()}) : {$motif}.");
        }

        if (trim((string) $mouvement->Numero_piece) === '') {
            $mouvement->loadMissing('journal');
            $mouvement->Numero_piece = self::prochainNumeroPiece(
                $mouvement->journal,
                (int) Carbon::parse($mouvement->Date_mouvement)->format('Y')
            );
            $mouvement->save();
        }

        $nb = LigneEcriture::where('Id_Mouvement_ecriture', $mouvement->Id_Mouvement_ecriture)
            ->where(fn ($q) => $q->where('statut', '!=', self::STATUT_VALIDE)->orWhereNull('statut'))
            ->update([
                'statut' => self::STATUT_VALIDE,
                'date_validation' => now(),
                'valide_par' => $userId,
            ]);

        Audit::piece(Audit::PIECE_VALIDATION, $mouvement);

        return $nb;
    }

    /**
     * Crée (en brouillon) l'écriture inverse d'une pièce : même journal, mêmes
     * comptes, débit et crédit permutés, liée à la pièce d'origine.
     * Utilisée pour la contre-passation manuelle et l'extourne automatique
     * des régularisations. À appeler dans une transaction.
     */
    public static function contrePasser(MouvementEcriture $origine, string $date, string $type, ?int $userId, ?string $libelle = null): MouvementEcriture
    {
        $origine->loadMissing(['lignes', 'journal']);
        $reference = trim((string) $origine->Reference) !== '' ? $origine->Reference : $origine->Numero_piece;
        $libelle = $libelle ?: ($type === MouvementEcriture::TYPE_EXTOURNE_AUTO ? 'Extourne' : 'Contre-passation')
            . " de {$origine->Numero_piece}" . ($origine->Libelle ? " — {$origine->Libelle}" : '');

        // Numéro attribué à la validation (voir validerPiece)
        $piece = MouvementEcriture::create([
            'Date_mouvement' => $date,
            'Id_Journal' => $origine->Id_Journal,
            'Libelle' => mb_substr($libelle, 0, 255),
            'Reference' => mb_substr($reference, 0, 100),
            'type_piece' => $type,
            'id_piece_origine' => $origine->Id_Mouvement_ecriture,
            'created_by' => $userId,
        ]);
        foreach ($origine->lignes as $l) {
            LigneEcriture::create([
                'Libelle' => mb_substr("Extourne — {$l->Libelle}", 0, 255),
                'Debit' => $l->Credit,
                'Credit' => $l->Debit,
                'Reference' => mb_substr($reference, 0, 50),
                'Id_Mode_paiement' => $l->Id_Mode_paiement,
                'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                'Id_Journal' => $origine->Id_Journal,
                'Id_Sous_compte' => $l->Id_Sous_compte,
                'statut' => self::STATUT_BROUILLON,
            ]);
        }

        Audit::piece(Audit::PIECE_CONTRE_PASSATION, $piece, null, [
            'piece_origine' => $origine->Numero_piece,
            'type' => $type === MouvementEcriture::TYPE_EXTOURNE_AUTO ? 'extourne automatique' : 'contre-passation',
        ]);

        return $piece;
    }

    /**
     * Prochain numéro de pièce pour un journal et une année : {CODE}{AAAA}-{NNNN}.
     * Séquence propre au journal et à l'année, calculée sous verrou.
     * Réservé aux pièces validées : appelé par validerPiece() et par les
     * pièces système validées d'office (clôture, à-nouveaux).
     * À appeler dans une transaction.
     */
    public static function prochainNumeroPiece(Journal $journal, int $annee): string
    {
        $prefixe = $journal->Code . $annee . '-';

        // Verrou applicatif sur (journal, année) : deux saisies simultanées
        // attendent l'une l'autre au lieu d'obtenir le même numéro.
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [(int) $journal->Id_Journal, $annee]);

        $max = MouvementEcriture::where('Numero_piece', 'like', $prefixe . '%')
            ->pluck('Numero_piece')
            ->map(fn ($n) => (int) substr($n, strlen($prefixe)))
            ->max() ?? 0;

        return $prefixe . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------

    private static function refuser(string $message): never
    {
        throw ValidationException::withMessages(['ecriture' => [$message]]);
    }

    private static function formatDate(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    private static function formatMontant(float $montant): string
    {
        return number_format($montant, 2, ',', ' ');
    }
}
