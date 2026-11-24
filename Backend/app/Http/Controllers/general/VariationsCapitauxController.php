<?php

namespace App\Http\Controllers\general;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Comptabilite\EtatsFinanciers;

class VariationsCapitauxController extends Controller
{
    /**
     * Tableau de variation des capitaux propres (PCG 2005) : soldes réels aux
     * 31/12/N-2, N-1 et N, et mouvements de chaque exercice. Le solde final est
     * égal aux capitaux propres du bilan. Fonctionne quel que soit le nombre
     * d'exercices déjà saisis.
     */
    public function index(Request $request)
    {
        $request->validate(['date_debut' => 'required|date', 'date_fin' => 'required|date']);
        return response()->json(\App\Services\Comptabilite\EtatsFinanciers::nettoyer((new EtatsFinanciers())->variationsCapitaux(
            EtatsFinanciers::date($request->date_debut),
            EtatsFinanciers::date($request->date_fin)
        )));
    }
}
