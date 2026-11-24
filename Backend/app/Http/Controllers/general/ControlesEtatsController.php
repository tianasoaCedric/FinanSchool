<?php

namespace App\Http\Controllers\general;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Comptabilite\EtatsFinanciers;

class ControlesEtatsController extends Controller
{
    /**
     * Contrôles de cohérence des états financiers d'un exercice :
     * Actif = Passif, résultat identique partout, rapprochement de trésorerie,
     * capitaux propres, pièces validées déséquilibrées, brouillons exclus.
     * GET /api/etats-financiers/controles?date_debut=…&date_fin=…
     */
    public function index(Request $request)
    {
        $request->validate(['date_debut' => 'required|date', 'date_fin' => 'required|date']);
        return response()->json(\App\Services\Comptabilite\EtatsFinanciers::nettoyer((new EtatsFinanciers())->controles(
            EtatsFinanciers::date($request->date_debut),
            EtatsFinanciers::date($request->date_fin)
        )));
    }
}
