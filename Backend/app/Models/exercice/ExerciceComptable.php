<?php

namespace App\Models\exercice;

use Illuminate\Database\Eloquent\Model;

class ExerciceComptable extends Model
{
    protected $table = 'exercice_comptable';
    protected $primaryKey = 'Id_Exercice_comptable';

    protected $fillable = [
        'Date_debut',
        'Date_fin',
        'Statut',
        'Annee_fiscale',
        'cloture_le',
        'cloture_par',
        'id_piece_an',
        'id_piece_cloture',
        'resultat_cloture',
        'ouvert_par_a_nouveaux',
        'periode_cloturee_au',
        'id_piece_affectation',
    ];

    protected $casts = [
        'Date_debut' => 'date',
        'Date_fin' => 'date',
        'cloture_le' => 'datetime',
        'resultat_cloture' => 'float',
        'ouvert_par_a_nouveaux' => 'boolean',
        'periode_cloturee_au' => 'date',
    ];

    /**
     * Exercice ouvert de référence pour la saisie courante. Deux exercices
     * consécutifs peuvent être ouverts (N en travaux d'inventaire, N+1 en
     * saisie courante) : c'est le plus récent qui est renvoyé.
     */
    public static function getExerciceOuvert()
    {
        return self::where('Statut', 'OUVERT')->orderByDesc('Annee_fiscale')->first();
    }

    /**
     * Motif de refus d'ouverture, ou null. Règles :
     *  - aucun exercice postérieur (ou celui-ci) clôturé définitivement : ses
     *    écritures ont servi aux à-nouveaux ;
     *  - au plus deux exercices ouverts, et consécutifs (N et N+1).
     */
    public function motifRefusOuverture(): ?string
    {
        $bloquant = self::where('Annee_fiscale', '>=', $this->Annee_fiscale)
            ->whereNotNull('cloture_le')->orderBy('Annee_fiscale')->first();
        if ($bloquant) {
            return "L'exercice {$bloquant->Annee_fiscale} est clôturé définitivement : annulez d'abord sa clôture.";
        }
        $annees = self::where('Statut', 'OUVERT')->pluck('Annee_fiscale')
            ->push($this->Annee_fiscale)->map(fn ($a) => (int) $a)->unique();
        if ($annees->max() - $annees->min() > 1) {
            return 'Deux exercices consécutifs au plus peuvent être ouverts (N en travaux de clôture et N+1). '
                . 'Clôturez d\'abord l\'exercice ' . $annees->min() . '.';
        }
        return null;
    }

    /**
     * Récupère l'exercice contenant une date donnée
     */
    public static function getExerciceByDate($date)
    {
        return self::whereDate('Date_debut', '<=', $date)
            ->whereDate('Date_fin', '>=', $date)
            ->first();
    }

    /**
     * Récupère tous les exercices (pour dropdown/sélection)
     */
    public static function getAllExercices()
    {
        return self::orderBy('Annee_fiscale', 'desc')->get();
    }

    public function isOuvert()
    {
        return $this->Statut === 'OUVERT';
    }

    public function isCloture()
    {
        return $this->Statut === 'CLOTURE';
    }

    /** Exercice clôturé par la procédure complète (écriture de clôture et à-nouveaux). */
    public function isClotureDefinitive(): bool
    {
        return $this->isCloture() && $this->cloture_le !== null;
    }

    /**
     * Fermer l'exercice (changement de statut uniquement).
     * La clôture comptable complète passe par App\Services\Comptabilite\ClotureService.
     */
    public function cloturer()
    {
        $this->Statut = 'CLOTURE';
        $this->save();
    }

    /**
     * Ouvrir l'exercice (changement de statut). Les règles d'ouverture sont
     * contrôlées par motifRefusOuverture() côté contrôleur ; la clôture ouvre
     * N+1 sans fermer d'autre exercice.
     */
    public function ouvrir()
    {
        $this->Statut = 'OUVERT';
        $this->save();
    }
}
