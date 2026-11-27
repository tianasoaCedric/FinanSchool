<?php

namespace App\Http\Controllers;

use App\Services\Comptabilite\Audit;
use App\Services\Parametres;
use Illuminate\Http\Request;

/**
 * Écran « Paramètres » : lecture pour tout utilisateur connecté,
 * modification réservée aux administrateurs (tracée dans la piste d'audit).
 */
class ParametresController extends Controller
{
    public function index()
    {
        return response()->json([
            'valeurs' => Parametres::tout(),
            'defauts' => array_map(fn ($d) => $d['defaut'], Parametres::DEFINITIONS),
        ]);
    }

    public function update(Request $request)
    {
        if (!$request->user()?->estAdmin()) {
            return response()->json(['message' => 'Seul un administrateur peut modifier les paramètres.'], 403);
        }
        $valeurs = $request->input('valeurs');
        if (!is_array($valeurs) || !$valeurs) {
            return response()->json(['message' => 'Aucun paramètre à enregistrer.'], 422);
        }

        [$avant, $apres] = Parametres::enregistrer($valeurs);

        if ($apres) {
            // Le logo (data URL volumineuse) n'est pas recopié dans l'audit
            $masquer = fn (array $v) => array_key_exists('societe.logo', $v)
                ? ['societe.logo' => $v['societe.logo'] ? '(image)' : ''] + $v
                : $v;
            Audit::enregistrer(
                Audit::PARAMETRES_MODIFICATION,
                'parametres',
                null,
                implode(', ', array_keys($apres)),
                $masquer($avant),
                $masquer($apres)
            );
        }

        return response()->json([
            'message' => $apres ? 'Paramètres enregistrés.' : 'Aucune modification.',
            'valeurs' => Parametres::tout(),
        ]);
    }
}
