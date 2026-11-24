<?php

namespace App\Models\Saisie;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle d'écriture réutilisable (ex. « Paie du mois », « Écolage »).
 * `lignes` : [{ Id_Sous_compte, Libelle, sens: 'debit'|'credit', montant: number|null }]
 */
class ModeleEcriture extends Model
{
    protected $table = 'modeles_ecriture';

    protected $fillable = ['nom', 'Id_Journal', 'libelle', 'lignes', 'created_by'];

    protected $casts = ['lignes' => 'array'];

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'Id_Journal');
    }
}
