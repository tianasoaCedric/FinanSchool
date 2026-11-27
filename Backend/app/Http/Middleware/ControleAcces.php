<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'accès des routes de comptabilité (après auth:sanctum) :
 *  - compte désactivé : jeton révoqué, 401 ;
 *  - rôle « lecteur » : lecture seule, toute écriture est refusée (403),
 *    sauf sur son propre compte (profil, déconnexion), l'assistant et les
 *    notifications.
 */
class ControleAcces
{
    /** Écritures permises à un lecteur (préfixes de chemin, sans « api/ »). */
    private const AUTORISE_LECTEUR = ['profil', 'logout', 'chat/', 'notifications', 'centre-notifications'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        if ($user->actif === false) {
            $user->currentAccessToken()?->delete();
            return response()->json(['message' => 'Votre compte est désactivé. Contactez un administrateur.'], 401);
        }

        if (!$user->peutModifier() && !$request->isMethodSafe()) {
            $chemin = preg_replace('#^api/#', '', $request->path());
            foreach (self::AUTORISE_LECTEUR as $p) {
                if (str_starts_with($chemin, $p)) {
                    return $next($request);
                }
            }
            return response()->json(['message' => 'Votre rôle (lecteur) ne permet que la consultation.'], 403);
        }

        return $next($request);
    }
}
