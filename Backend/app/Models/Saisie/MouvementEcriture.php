<?php

namespace App\Models\Saisie;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MouvementEcriture extends Model
{
    use HasFactory;

    protected $table = 'mouvement_ecritures';
    protected $primaryKey = 'Id_Mouvement_ecriture';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'Date_mouvement',
        'Numero_piece',
        'Id_Journal',
        'Libelle',
        'Reference',
        'justificatif_path',
        'justificatif_nom',
        'created_by',
        'type_piece',
        'id_piece_origine',
        'a_extourner',
    ];

    protected $casts = [
        'a_extourner' => 'boolean',
    ];

    public const TYPE_NORMALE = 'normale';
    public const TYPE_A_NOUVEAU = 'a_nouveau';
    public const TYPE_DOTATION = 'dotation';
    /** Solde des comptes de gestion (6/7) vers le 12, au dernier jour de l'exercice. */
    public const TYPE_CLOTURE = 'cloture';
    /** Contre-passation (correction) d'une pièce validée, saisie par l'utilisateur. */
    public const TYPE_EXTOURNE = 'extourne';
    /** Extourne automatique d'une régularisation au 1er jour de l'exercice suivant. */
    public const TYPE_EXTOURNE_AUTO = 'extourne_auto';
    /** Affectation du résultat de l'exercice précédent (décision de l'AG). */
    public const TYPE_AFFECTATION = 'affectation';

    /** Pièces générées par l'application : ni contre-passables ni extournables. */
    public const TYPES_TECHNIQUES = [self::TYPE_A_NOUVEAU, self::TYPE_CLOTURE];

    public function origine() {
        return $this->belongsTo(self::class, 'id_piece_origine');
    }

    /** Contre-passation (manuelle ou automatique) de cette pièce, s'il y en a une. */
    public function contrePassation() {
        return $this->hasOne(self::class, 'id_piece_origine');
    }

    protected $hidden = ['justificatif_path'];

    public function journal() {
        return $this->belongsTo(Journal::class, 'Id_Journal');
    }

    public function lignes() {
        return $this->hasMany(LigneEcriture::class, 'Id_Mouvement_ecriture');
    }

    public function createur() {
        return $this->belongsTo(User::class, 'created_by');
    }
}
