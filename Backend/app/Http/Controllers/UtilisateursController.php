<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Comptabilite\Audit;
use App\Services\Parametres;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des utilisateurs et de leurs rôles (administrateurs uniquement).
 * Garde-fous : on ne peut ni se rétrograder ni se désactiver soi-même, et il
 * reste toujours au moins un administrateur actif.
 * Chaque changement de droits est tracé dans la piste d'audit.
 */
class UtilisateursController extends Controller
{
    public function index(Request $request)
    {
        $this->exigerAdmin($request);
        return response()->json([
            'utilisateurs' => User::orderBy('name')->get()->map(fn ($u) => ProfilController::presenter($u)),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $this->exigerAdmin($request);
        $data = $this->valider($request, null);

        $user = DB::transaction(function () use ($data) {
            $user = new User(['name' => $data['name'], 'email' => $data['email']]);
            $user->forceFill(['password' => Hash::make($data['password']), 'actif' => true]);
            $user->definirRole($data['role'])->save();
            Audit::enregistrer(Audit::UTILISATEUR_DROITS, 'utilisateur', $user->id, $user->name,
                null, ['role' => $user->role, 'actif' => true], ['origine' => 'création']);
            return $user;
        });

        return response()->json(['message' => "Utilisateur {$user->name} créé.", 'utilisateur' => ProfilController::presenter($user)], 201);
    }

    public function update(Request $request, int $id)
    {
        $admin = $this->exigerAdmin($request);
        $user = User::findOrFail($id);
        $data = $this->valider($request, $user);

        $soi = $user->id === $admin->id;
        if ($soi && $data['role'] !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages(['role' => ['Vous ne pouvez pas retirer votre propre rôle administrateur.']]);
        }
        if ($soi && !$data['actif']) {
            throw ValidationException::withMessages(['actif' => ['Vous ne pouvez pas désactiver votre propre compte.']]);
        }
        $perdAdmin = $user->estAdmin() && ($data['role'] !== User::ROLE_ADMIN || !$data['actif']);
        if ($perdAdmin && $this->adminsActifs($user->id) === 0) {
            throw ValidationException::withMessages(['role' => ['Il doit rester au moins un administrateur actif.']]);
        }

        DB::transaction(function () use ($user, $data) {
            $avant = ['role' => $user->role, 'actif' => $user->actif !== false];
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $user->definirRole($data['role'])->forceFill(['actif' => $data['actif']]);
            if (!empty($data['password'])) {
                $user->forceFill(['password' => Hash::make($data['password'])]);
            }
            $user->save();

            // Un compte désactivé ou dont le mot de passe change est déconnecté partout
            if (!$data['actif'] || !empty($data['password'])) {
                $user->tokens()->delete();
            }
            $apres = ['role' => $user->role, 'actif' => $user->actif];
            if ($avant !== $apres || !empty($data['password'])) {
                Audit::enregistrer(Audit::UTILISATEUR_DROITS, 'utilisateur', $user->id, $user->name, $avant, $apres,
                    !empty($data['password']) ? ['mot_de_passe' => 'réinitialisé'] : []);
            }
        });

        return response()->json(['message' => 'Utilisateur mis à jour.', 'utilisateur' => ProfilController::presenter($user->fresh())]);
    }

    // ------------------------------------------------------------------

    private function exigerAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->estAdmin(), 403, 'Réservé aux administrateurs.');
        return $user;
    }

    private function adminsActifs(int $saufId): int
    {
        return User::where('id', '!=', $saufId)->where('actif', true)
            ->where(fn ($q) => $q->where('role', User::ROLE_ADMIN)->orWhere('is_admin', true))
            ->count();
    }

    private function valider(Request $request, ?User $user): array
    {
        $min = (int) Parametres::get('securite.mot_de_passe_min');
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'actif' => $user ? 'required|boolean' : 'nullable|boolean',
            'password' => ($user ? 'nullable' : 'required') . "|string|min:{$min}",
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => "L'e-mail est obligatoire.",
            'email.email' => 'Adresse e-mail invalide.',
            'email.unique' => 'Cette adresse est déjà utilisée.',
            'role.in' => 'Rôle inconnu.',
            'password.required' => 'Un mot de passe initial est obligatoire.',
            'password.min' => "Le mot de passe doit contenir au moins {$min} caractères.",
        ]);
    }
}
