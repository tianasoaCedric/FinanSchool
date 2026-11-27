<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Parametres;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * « Mon profil » : chaque utilisateur gère son propre compte
 * (identité, mot de passe, sessions ouvertes).
 */
class ProfilController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => $this->presenter($user),
            'roles' => User::ROLES,
            'sessions' => $user->tokens()->count(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => "L'e-mail est obligatoire.",
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Cette adresse est déjà utilisée par un autre compte.',
        ]);
        $user->fill($data)->save();
        return response()->json(['message' => 'Profil mis à jour.', 'user' => $this->presenter($user)]);
    }

    public function motDePasse(Request $request)
    {
        $min = (int) Parametres::get('securite.mot_de_passe_min');
        $request->validate([
            'actuel' => 'required|string',
            'nouveau' => "required|string|min:{$min}|confirmed|different:actuel",
        ], [
            'actuel.required' => 'Saisissez votre mot de passe actuel.',
            'nouveau.required' => 'Saisissez le nouveau mot de passe.',
            'nouveau.min' => "Le mot de passe doit contenir au moins {$min} caractères.",
            'nouveau.confirmed' => 'La confirmation ne correspond pas.',
            'nouveau.different' => "Le nouveau mot de passe doit être différent de l'actuel.",
        ]);

        $user = $request->user();
        if (!Hash::check($request->input('actuel'), $user->password)) {
            throw ValidationException::withMessages(['actuel' => ['Mot de passe actuel incorrect.']]);
        }
        $user->forceFill(['password' => Hash::make($request->input('nouveau'))])->save();

        // Les autres sessions sont fermées ; la session courante reste ouverte.
        $courant = $user->currentAccessToken()?->id;
        $user->tokens()->when($courant, fn ($q) => $q->where('id', '!=', $courant))->delete();

        return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été fermées.']);
    }

    /** Ferme toutes les sessions sauf celle en cours. */
    public function fermerSessions(Request $request)
    {
        $user = $request->user();
        $courant = $user->currentAccessToken()?->id;
        $n = $user->tokens()->when($courant, fn ($q) => $q->where('id', '!=', $courant))->delete();
        return response()->json(['message' => $n ? "{$n} autre(s) session(s) fermée(s)." : 'Aucune autre session ouverte.']);
    }

    public static function presenter(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role ?? User::ROLE_COMPTABLE,
            'is_admin' => $u->estAdmin(),
            'actif' => $u->actif !== false,
            'derniere_connexion' => $u->derniere_connexion?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
