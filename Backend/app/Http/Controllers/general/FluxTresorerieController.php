<?php

namespace App\Http\Controllers\general;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Comptabilite\EtatsFinanciers;

class FluxTresorerieController extends Controller
{
    /**
     * Tableau des flux de trésorerie, méthode indirecte (PCG 2005).
     * Construit à partir des variations de soldes entre l'ouverture et la
     * clôture : la variation calculée (A + B + C) se rapproche exactement de la
     * variation réelle de la trésorerie (classe 5).
     */
    public function index(Request $request)
    {
        $request->validate(['date_debut' => 'required|date', 'date_fin' => 'required|date']);
        return response()->json(\App\Services\Comptabilite\EtatsFinanciers::nettoyer((new EtatsFinanciers())->fluxTresorerie(
            EtatsFinanciers::date($request->date_debut),
            EtatsFinanciers::date($request->date_fin)
        )));
    }
}
