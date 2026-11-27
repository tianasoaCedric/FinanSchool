<?php

namespace App\Models\Tiers;

use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\LigneEcriture;
use Illuminate\Database\Eloquent\Model;

/** Client, fournisseur, membre du personnel… rattaché aux lignes de classe 4. */
class Tiers extends Model
{
    protected $table = 'tiers';

    public const TYPES = [
        'client' => 'Client',
        'fournisseur' => 'Fournisseur',
        'personnel' => 'Personnel',
        'autre' => 'Autre',
    ];

    /** Préfixe de code proposé et racine de compte collectif usuelle (PCG 2005). */
    public const PREFIXES = ['client' => 'C', 'fournisseur' => 'F', 'personnel' => 'P', 'autre' => 'T'];

    protected $fillable = [
        'code', 'type', 'nom', 'nif', 'stat', 'adresse', 'telephone', 'email',
        'delai_paiement', 'Id_Sous_compte', 'actif', 'notes',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'delai_paiement' => 'integer',
    ];

    public function compte()
    {
        return $this->belongsTo(SousCompte::class, 'Id_Sous_compte');
    }

    public function lignes()
    {
        return $this->hasMany(LigneEcriture::class, 'id_tiers');
    }
}
