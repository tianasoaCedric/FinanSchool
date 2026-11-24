<?php

namespace App\Http\Controllers\general;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Comptabilite\EtatsFinanciers;

class CompteResultatFonctionController extends Controller
{
    /**
     * Compte de résultat par fonction (PCG 2005).
     * Les charges 60-65 et 68 sont réparties par fonction : affectation du
     * sous-compte si elle existe (catégories COUTVENTE, CHARGECOMM, CHARGEADM,
     * AUTCHOP), sinon règle par défaut selon le numéro de compte
     * (EtatsFinanciers::FONCTIONS_PAR_DEFAUT). Le résultat net est identique
     * à celui du compte de résultat par nature.
     */
    public function index(Request $request)
    {
        $request->validate(['date_debut' => 'required|date', 'date_fin' => 'required|date']);
        return response()->json(\App\Services\Comptabilite\EtatsFinanciers::nettoyer((new EtatsFinanciers())->compteResultatFonction(
            EtatsFinanciers::date($request->date_debut),
            EtatsFinanciers::date($request->date_fin)
        )));
    }
}
