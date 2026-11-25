<?php

namespace App\Observers;

use App\Models\PlanCompte\Classe;
use App\Models\PlanCompte\Compte;
use App\Models\PlanCompte\Rubrique;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Services\Comptabilite\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Trace dans la piste d'audit toute création, modification ou suppression
 * d'un élément du plan comptable ou d'un journal (enregistré via Eloquent).
 */
class PlanComptableObserver
{
    /** Type d'objet et champ « code » de chaque modèle suivi. */
    private const SUIVIS = [
        Classe::class => ['classe', 'Code', 'Classe'],
        Rubrique::class => ['rubrique', 'Code_rubrique', 'Rubrique'],
        Compte::class => ['compte', 'Code_compte', 'Compte'],
        SousCompte::class => ['sous_compte', 'Code_sous_compte', 'Sous-compte'],
        Journal::class => ['journal', 'Code', 'Journal'],
    ];

    public static function modeles(): array
    {
        return array_keys(self::SUIVIS);
    }

    public function created(Model $m): void
    {
        $this->tracer(Audit::PLAN_CREATION, $m, null, $this->etat($m->getAttributes()));
    }

    public function updated(Model $m): void
    {
        $changes = array_keys($m->getChanges());
        if (!$changes) {
            return;
        }
        $avant = array_intersect_key($m->getOriginal(), array_flip($changes));
        $apres = array_intersect_key($m->getAttributes(), array_flip($changes));
        $this->tracer(Audit::PLAN_MODIFICATION, $m, $this->etat($avant), $this->etat($apres));
    }

    public function deleted(Model $m): void
    {
        $this->tracer(Audit::PLAN_SUPPRESSION, $m, $this->etat($m->getOriginal()), null);
    }

    private function tracer(string $action, Model $m, ?array $avant, ?array $apres): void
    {
        [$type, $champCode, $nom] = self::SUIVIS[get_class($m)];
        $code = $m->getAttribute($champCode) ?? $m->getOriginal($champCode);
        $libelle = $m->getAttribute('Libelle') ?? $m->getOriginal('Libelle');

        Audit::enregistrer($action, $type, $m->getKey(), trim("{$nom} {$code} — {$libelle}", ' —'), $avant, $apres);
    }

    /** Attributs utiles (sans horodatages techniques). */
    private function etat(array $attributs): array
    {
        return array_diff_key($attributs, array_flip(['created_at', 'updated_at']));
    }
}
