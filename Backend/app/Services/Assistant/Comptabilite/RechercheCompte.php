<?php

namespace App\Services\Assistant\Comptabilite;

use App\Services\Assistant\Intention;
use App\Services\Assistant\PlanComptable;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;

/** « Quel est le compte de la caisse ? », « sous-comptes du 401 », « compte 512001 ». */
class RechercheCompte extends Intention
{
    protected function motsCles(): array
    {
        return [
            'quel compte' => 3, 'quel est le compte' => 3, 'numero de compte' => 3, 'sous compte' => 2,
            'plan comptable' => 2, 'compte' => 1, 'cherche' => 1, 'trouve' => 1, 'libelle' => 1,
        ];
    }

    protected function bonus(Question $q): float
    {
        return ($q->codesComptes || $q->termesLibres) ? 1 : 0;
    }

    public function repondre(Question $q): Reponse
    {
        if ($q->codesComptes) {
            $trouves = PlanComptable::parCode($q->codesComptes[0], 30);
            $intro = "Sous-comptes commençant par **{$q->codesComptes[0]}**";
        } else {
            $trouves = PlanComptable::parLibelle($q->termesLibres, 10);
            $intro = 'Comptes correspondant à « ' . implode(' ', $q->termesLibres) . ' »';
        }

        if ($trouves->isEmpty()) {
            return new Reponse(
                $q->termesLibres || $q->codesComptes
                    ? "Je n'ai trouvé aucun compte correspondant. Essayez avec un autre mot (ex. banque, caisse, fournisseur) ou un numéro de compte."
                    : 'Quel compte cherchez-vous ? Donnez un numéro (ex. 401) ou un mot du libellé (ex. fournisseur).',
                ['Quel est le compte de la caisse ?', 'Sous-comptes du 401']
            );
        }

        $texte = "{$intro} :\n\n";
        foreach ($trouves as $c) {
            $texte .= "• **{$c->code}** — {$c->libelle}" . ($c->libelle_compte ? " (compte {$c->compte} — {$c->libelle_compte})" : '') . "\n";
        }
        $premier = $trouves->first();
        return (new Reponse(rtrim($texte), ["Solde du compte {$premier->code}", "Derniers mouvements du compte {$premier->code}"]))
            ->lien('Plan comptable : sous-comptes', '/souscompte');
    }
}
