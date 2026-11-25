<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Comptabilite\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Donne (ou retire) le droit administrateur à un utilisateur.
 *   php artisan utilisateur:admin email@exemple.mg
 *   php artisan utilisateur:admin email@exemple.mg --retirer
 * Le changement est inscrit dans la piste d'audit.
 */
class DefinirAdministrateur extends Command
{
    protected $signature = 'utilisateur:admin {email : E-mail de l\'utilisateur} {--retirer : Retire le droit au lieu de le donner}';

    protected $description = "Donne ou retire le droit administrateur (annulation de clôture d'exercice)";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (!$user) {
            $this->error("Aucun utilisateur avec l'e-mail {$this->argument('email')}.");
            return self::FAILURE;
        }

        $admin = !$this->option('retirer');
        if ((bool) $user->is_admin === $admin) {
            $this->info($admin ? "{$user->name} est déjà administrateur." : "{$user->name} n'est pas administrateur.");
            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $admin) {
            $user->forceFill(['is_admin' => $admin])->save();
            Audit::enregistrer(Audit::UTILISATEUR_DROITS, 'utilisateur', $user->id, $user->name,
                ['administrateur' => !$admin], ['administrateur' => $admin], ['origine' => 'commande utilisateur:admin']);
        });

        $this->info($admin ? "{$user->name} est maintenant administrateur." : "{$user->name} n'est plus administrateur.");
        return self::SUCCESS;
    }
}
