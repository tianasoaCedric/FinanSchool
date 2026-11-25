<?php

namespace App\Providers;

use App\Observers\PlanComptableObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Piste d'audit du plan comptable et des journaux
        foreach (PlanComptableObserver::modeles() as $modele) {
            $modele::observe(PlanComptableObserver::class);
        }
    }
}
