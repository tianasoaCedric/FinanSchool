<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\Saisie\LigneEcriture;
use App\Models\Tiers\Lettrage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lettrage des comptes de tiers : rapprocher des lignes validées d'un même
 * compte et d'un même tiers dont le total des débits égale celui des crédits
 * (facture ↔ règlement, avoir ↔ facture…).
 *
 * Règles :
 *  - au moins deux lignes, toutes validées et non encore lettrées ;
 *  - même sous-compte et même tiers ;
 *  - débits = crédits (à la tolérance d'équilibre près) ;
 *  - lignes d'exercices non clôturés (l'historique clôturé est repris par
 *    les à-nouveaux, qui se lettrent à leur tour).
 * Un code (A, B, … Z, AA…) est attribué par compte. Le délettrage est libre
 * tant que l'exercice des lignes est ouvert. Tout est tracé dans l'audit.
 */
class LettrageService
{
    /** Lettre un ensemble de lignes. À appeler hors ou dans une transaction. */
    public static function lettrer(array $ligneIds, bool $automatique = false): Lettrage
    {
        return DB::transaction(function () use ($ligneIds, $automatique) {
            $ids = array_values(array_unique(array_map('intval', $ligneIds)));
            $lignes = LigneEcriture::with(['mouvement', 'sousCompte'])->whereIn('Id_Ligne_ecriture', $ids)->lockForUpdate()->get();

            if ($motif = self::motifNonLettrable($lignes, count($ids))) {
                throw ValidationException::withMessages(['lettrage' => [$motif]]);
            }

            $premiere = $lignes->first();
            $lettrage = Lettrage::create([
                'code' => self::prochainCode((int) $premiere->Id_Sous_compte),
                'Id_Sous_compte' => $premiere->Id_Sous_compte,
                'id_tiers' => $premiere->id_tiers,
                'montant' => round((float) $lignes->sum('Debit'), 2),
                'automatique' => $automatique,
                'user_id' => Auth::id(),
            ]);
            LigneEcriture::whereIn('Id_Ligne_ecriture', $ids)->update(['id_lettrage' => $lettrage->id]);

            Audit::enregistrer(Audit::LETTRAGE, 'lettrage', $lettrage->id,
                "Lettrage {$lettrage->code} — {$premiere->sousCompte?->Code_sous_compte}", null,
                self::etat($lettrage, $lignes), ['automatique' => $automatique]);

            return $lettrage;
        });
    }

    /** Null si les lignes peuvent être lettrées ensemble, sinon le motif. */
    public static function motifNonLettrable(Collection $lignes, ?int $attendues = null): ?string
    {
        if ($lignes->count() < 2) {
            return 'Sélectionnez au moins deux lignes.';
        }
        if ($attendues !== null && $lignes->count() !== $attendues) {
            return 'Ligne introuvable.';
        }
        if ($lignes->contains(fn ($l) => $l->statut !== EcritureGuard::STATUT_VALIDE)) {
            return 'Seules les lignes validées se lettrent.';
        }
        if ($lignes->contains(fn ($l) => $l->id_lettrage)) {
            return 'Une des lignes est déjà lettrée : délettrez-la d\'abord.';
        }
        if ($lignes->pluck('Id_Sous_compte')->unique()->count() > 1) {
            return 'Les lignes doivent porter sur le même compte.';
        }
        if ($lignes->map(fn ($l) => $l->id_tiers ?? 0)->unique()->count() > 1) {
            return 'Les lignes doivent concerner le même tiers.';
        }
        $d = round((float) $lignes->sum('Debit'), 2);
        $c = round((float) $lignes->sum('Credit'), 2);
        if (abs($d - $c) >= EcritureGuard::tolerance()) {
            return sprintf('Lettrage déséquilibré : débit %s ≠ crédit %s (écart %s).',
                self::fm($d), self::fm($c), self::fm(abs($d - $c)));
        }
        foreach ($lignes as $l) {
            if ($motif = self::motifExerciceFerme($l->mouvement?->Date_mouvement)) {
                return $motif;
            }
        }
        return null;
    }

    /** Supprime un lettrage (les lignes redeviennent non lettrées). */
    public static function delettrer(Lettrage $lettrage): void
    {
        DB::transaction(function () use ($lettrage) {
            $lignes = $lettrage->lignes()->with(['mouvement', 'sousCompte'])->get();
            foreach ($lignes as $l) {
                if ($motif = self::motifExerciceFerme($l->mouvement?->Date_mouvement)) {
                    throw ValidationException::withMessages(['lettrage' => [$motif]]);
                }
            }
            Audit::enregistrer(Audit::DELETTRAGE, 'lettrage', $lettrage->id,
                "Lettrage {$lettrage->code} — {$lettrage->compte?->Code_sous_compte}",
                self::etat($lettrage, $lignes), null);
            LigneEcriture::where('id_lettrage', $lettrage->id)->update(['id_lettrage' => null]);
            $lettrage->delete();
        });
    }

    /**
     * Lettrage automatique des lignes non lettrées (par compte et par tiers) :
     *  1. lignes de même référence de pièce dont le total s'équilibre ;
     *  2. puis paires débit / crédit de même montant, les plus anciennes d'abord.
     * Retourne le nombre de lettrages créés.
     */
    public static function automatique(?int $sousCompte = null, ?int $tiers = null): int
    {
        $q = LigneEcriture::query()
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'ligne_ecritures.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'ligne_ecritures.Id_Sous_compte')
            ->where('ligne_ecritures.statut', EcritureGuard::STATUT_VALIDE)
            ->whereNull('ligne_ecritures.id_lettrage')
            ->where('s.Code_sous_compte', 'like', '4%')
            ->select('ligne_ecritures.*', 'm.Date_mouvement as date_piece', 'm.Reference as ref_piece');
        if ($sousCompte) {
            $q->where('ligne_ecritures.Id_Sous_compte', $sousCompte);
        } else {
            // Sans compte précis : seulement les lignes rattachées à un tiers
            $q->whereNotNull('ligne_ecritures.id_tiers');
        }
        if ($tiers) {
            $q->where('ligne_ecritures.id_tiers', $tiers);
        }
        if ($debut = self::debutOuvert()) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }

        $n = 0;
        $groupes = $q->orderBy('m.Date_mouvement')->orderBy('ligne_ecritures.Id_Ligne_ecriture')->get()
            ->groupBy(fn ($l) => $l->Id_Sous_compte . '|' . ($l->id_tiers ?? 0));

        foreach ($groupes as $lignes) {
            $restantes = $lignes->keyBy('Id_Ligne_ecriture');

            // 1. Par référence de pièce (facture et règlement portant le même n°)
            foreach ($restantes->groupBy(fn ($l) => mb_strtolower(trim((string) ($l->ref_piece ?: $l->Reference)))) as $ref => $parRef) {
                if ($ref === '' || $parRef->count() < 2 || !self::equilibre($parRef)) {
                    continue;
                }
                if (!$parRef->contains(fn ($l) => $l->Debit > 0) || !$parRef->contains(fn ($l) => $l->Credit > 0)) {
                    continue;
                }
                self::lettrer($parRef->pluck('Id_Ligne_ecriture')->all(), true);
                $restantes = $restantes->except($parRef->pluck('Id_Ligne_ecriture')->all());
                $n++;
            }

            // 2. Paires de même montant
            $credits = $restantes->filter(fn ($l) => (float) $l->Credit > 0)->values();
            foreach ($restantes->filter(fn ($l) => (float) $l->Debit > 0) as $d) {
                $i = $credits->search(fn ($c) => abs((float) $c->Credit - (float) $d->Debit) < 0.005);
                if ($i === false) {
                    continue;
                }
                self::lettrer([$d->Id_Ligne_ecriture, $credits[$i]->Id_Ligne_ecriture], true);
                $credits->forget($i);
                $credits = $credits->values();
                $n++;
            }
        }
        return $n;
    }

    /** Début des lignes encore « vivantes » (exercices non représentés par des à-nouveaux). */
    public static function debutOuvert(): ?string
    {
        return TiersService::fenetre();
    }

    /** Prochain code de lettrage du compte : A … Z, AA … ZZ, AAA… */
    public static function prochainCode(int $sousCompte): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [424242, $sousCompte]);
        $dernier = Lettrage::where('Id_Sous_compte', $sousCompte)
            ->orderByRaw('LENGTH(code) DESC, code DESC')->value('code');
        if (!$dernier) {
            return 'A';
        }
        $dernier++;
        return $dernier;
    }

    /**
     * Supprime les lettrages qui contiennent une des lignes (avant suppression
     * de ces lignes : annulation de clôture, d'import…). Les autres lignes du
     * groupe redeviennent non lettrées.
     */
    public static function liberer(array $ligneIds): void
    {
        $ids = LigneEcriture::whereIn('Id_Ligne_ecriture', $ligneIds)->whereNotNull('id_lettrage')->pluck('id_lettrage')->unique();
        if ($ids->isEmpty()) {
            return;
        }
        LigneEcriture::whereIn('id_lettrage', $ids)->update(['id_lettrage' => null]);
        Lettrage::whereIn('id', $ids)->delete();
    }

    // ------------------------------------------------------------------

    private static function motifExerciceFerme($date): ?string
    {
        if (!$date) {
            return 'Pièce introuvable.';
        }
        $ex = ExerciceComptable::getExerciceByDate(substr((string) $date, 0, 10));
        if ($ex && !$ex->isOuvert()) {
            return "L'exercice {$ex->Annee_fiscale} est clôturé : ses lignes ne se lettrent plus (lettrez les à-nouveaux).";
        }
        return null;
    }

    private static function equilibre(Collection $lignes): bool
    {
        return abs(round((float) $lignes->sum('Debit') - (float) $lignes->sum('Credit'), 2)) < EcritureGuard::tolerance();
    }

    private static function etat(Lettrage $lettrage, Collection $lignes): array
    {
        return [
            'code' => $lettrage->code,
            'montant' => (float) $lettrage->montant,
            'lignes' => $lignes->map(fn ($l) => [
                'piece' => $l->mouvement?->numeroAffiche(),
                'libelle' => $l->Libelle,
                'debit' => (float) $l->Debit,
                'credit' => (float) $l->Credit,
            ])->values()->all(),
        ];
    }

    private static function fm(float $m): string
    {
        return number_format($m, 2, ',', ' ');
    }
}
