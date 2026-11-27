<?php

namespace App\Models\import;

use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    protected $table = 'import_histories';

    protected $fillable = [
        'nom_fichier',
        'imported_at',
        'imported_by',
        'nombre_mouvements',
        'nombre_lignes',
        'statut',
        'erreur',
        'total_debit',
        'date_min',
        'date_max',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
        'imported_at' => 'datetime',
        'annule_le' => 'datetime',
        'date_min' => 'date:Y-m-d',
        'date_max' => 'date:Y-m-d',
        'total_debit' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'imported_by');
    }

    public function annulePar()
    {
        return $this->belongsTo(\App\Models\User::class, 'annule_par');
    }
}
