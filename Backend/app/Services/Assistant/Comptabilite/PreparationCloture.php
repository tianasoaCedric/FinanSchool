<?php

namespace App\Services\Assistant\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\ClotureService;

/** « Que faire avant de clôturer ? », « peut-on clôturer 2025 ? ». */
class PreparationCloture extends Intention
{
    protected function motsCles(): array
    {
        return [
            'cloture' => 3, 'cloturer' => 3, 'fin d exercice' => 3, 'fin de l exercice' => 3,
            'inventaire' => 2, 'a nouveaux' => 2, 'reste a faire' => 2,
        ];
    }

    public function repondre(Question $q): Reponse
    {
        $exercice = $q->annee
            ? ExerciceComptable::where('Annee_fiscale', $q->annee)->first()
            : ExerciceComptable::where('Statut', 'OUVERT')->orderBy('Annee_fiscale')->first();
        if (!$exercice) {
            return new Reponse($q->annee ? "Je ne trouve pas l'exercice {$q->annee}." : 'Aucun exercice ouvert.');
        }

        $prep = app(ClotureService::class)->preparation($exercice);
        if ($exercice->isClotureDefinitive()) {
            return (new Reponse("L'exercice {$exercice->Annee_fiscale} est déjà clôturé. Résultat de clôture : "
                . Reponse::montant((float) $prep['resultat']) . '.'))->lien('Clôtures', '/cloture');
        }

        $texte = "Préparation de la clôture de l'exercice **{$exercice->Annee_fiscale}** :\n\n";
        foreach ($prep['controles'] as $c) {
            $etat = $c['ok'] ? '[OK]' : ($c['bloquant'] ? '[À faire]' : '[Recommandé]');
            $texte .= "• {$etat} {$c['libelle']}" . (!$c['ok'] && $c['detail'] ? " — {$c['detail']}" : '') . "\n";
        }
        $texte .= "\nRésultat provisoire : " . Reponse::montant(abs((float) $prep['resultat']))
            . ((float) $prep['resultat'] >= 0 ? ' (bénéfice)' : ' (perte)') . "\n\n"
            . ($prep['peut_cloturer']
                ? "**Tous les contrôles bloquants sont respectés** : l'exercice peut être clôturé."
                : '**La clôture est bloquée** tant que les points « À faire » ne sont pas réglés.');

        return (new Reponse($texte, ['Pièces à valider', "Résultat de l'exercice {$exercice->Annee_fiscale}"]))
            ->lien('Ouvrir l\'écran de clôture', '/cloture');
    }
}
