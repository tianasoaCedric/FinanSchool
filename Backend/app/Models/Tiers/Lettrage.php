<?php

namespace App\Models\Tiers;

use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\LigneEcriture;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Groupe de lignes lettrées ensemble (débits = crédits). */
class Lettrage extends Model
{
    protected $table = 'lettrages';

    protected $fillable = ['code', 'Id_Sous_compte', 'id_tiers', 'montant', 'automatique', 'user_id'];

    protected $casts = [
        'montant' => 'float',
        'automatique' => 'boolean',
    ];

    public function lignes()
    {
        return $this->hasMany(LigneEcriture::class, 'id_lettrage');
    }

    public function tiers()
    {
        return $this->belongsTo(Tiers::class, 'id_tiers');
    }

    public function compte()
    {
        return $this->belongsTo(SousCompte::class, 'Id_Sous_compte');
    }

    public function auteur()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
