<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\EmailVerification;
use App\Models\Error;
use App\Helpers\ErrorHelper;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use App\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    // -------------------
    // LOGIN
    // -------------------
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => ErrorHelper::get(4),
            'password.required' => ErrorHelper::get(5),
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => ErrorHelper::get(1)
            ], 401);
        }

        if ($user->actif === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Votre compte est désactivé. Contactez un administrateur.',
            ], 403);
        }
        $user->forceFill(['derniere_connexion' => now()])->save();

        // Durée de session (minutes, 0 = illimitée) : paramètre « securite.duree_session »
        $duree = (int) \App\Services\Parametres::get('securite.duree_session');
        $token = $user->createToken('app-token', ['*'], $duree > 0 ? now()->addMinutes($duree) : null)->plainTextToken;

        return response()->json([
            'status' => 'success',
            'user' => $user,
            'token' => $token
        ]);
    }

    // -------------------
    // LOGOUT
    // -------------------
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['status' => 'success', 'message' => 'Déconnecté']);
    }

    // -------------------
    // GET CURRENT USER
    // -------------------
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    // -------------------
    // DEMANDER CODE VERIFICATION EMAIL
    // -------------------
public function requestVerification(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    // Générer un code à 6 chiffres
    $code = rand(100000, 999999);

    // Stocker dans le cache pour 10 minutes
    Cache::put('email_verification_' . $request->email, $code, now()->addMinutes(10));

    // Envoyer le code par email
    Notification::route('mail', $request->email)
        ->notify(new EmailVerificationCode($code));

    return response()->json([
        'status' => 'success',
        'message' => 'Code envoyé à votre email.'
    ]);
}

public function verifyCode(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'code' => 'required|numeric',
    ]);

    // Récupérer le code depuis le cache
    $cachedCode = Cache::get('email_verification_' . $request->email);

    // Vérifier la validité
    if (!$cachedCode) {
        return response()->json([
            'status' => 'error',
            'message' => 'Code expiré ou inexistant.'
        ], 400);
    }

    if ($request->code != $cachedCode) {
        return response()->json([
            'status' => 'error',
            'message' => 'Code invalide.'
        ], 400);
    }

    // Vérification réussie → supprimer le code
    Cache::forget('email_verification_' . $request->email);

    return response()->json([
        'status' => 'success',
        'message' => 'Email vérifié avec succès.'
    ]);
}



    // -------------------
    // REGISTER
    // -------------------
    public function register(Request $request)
        {
            // Inscription libre activable dans Paramètres › Sécurité
            if (!\App\Services\Parametres::get('securite.inscription_ouverte')) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Les inscriptions sont fermées. Demandez un compte à un administrateur.",
                ], 403);
            }
            $min = (int) \App\Services\Parametres::get('securite.mot_de_passe_min');

            // Validation uniquement des champs nécessaires
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => "required|string|min:{$min}|confirmed",
            ], [
                'name.required' => ErrorHelper::get(7) ?? 'Nom requis', // id 7 pour nom si tu veux
                'email.required' => ErrorHelper::get(4),
                'email.unique' => ErrorHelper::get(6),
                'password.required' => ErrorHelper::get(5),
                'password.min' => "Le mot de passe doit contenir au moins {$min} caractères.",
            ]);

            // Création de l'utilisateur, avec le rôle par défaut des inscriptions
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);
            $user->definirRole(\App\Services\Parametres::get('securite.role_inscription'))->save();

            return response()->json([
                'status' => 'success',
                'user' => $user
            ]);
        }

}
