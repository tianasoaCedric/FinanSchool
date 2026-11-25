<?php

namespace App\Services\Assistant\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Services\Assistant\Intention;
use App\Services\Assistant\PlanComptable;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EtatsFinanciers;
use Carbon\Carbon;

/** « Solde du 512001 au 31/03/2025 », « solde de la caisse ». */
class SoldeCompte extends Intention
{
    protected function motsCles(): array
    {
        return ['solde' => 3, 'combien sur' => 2, 'combien dans' => 2, 'reste sur' => 2, 'montant du compte' => 2];
    }

    protected function bonus(Question $q): float
    {
        return $q->codesComptes ? 2 : 0;
    }

    public function repondre(Question $q): Reponse
    {
        $r = PlanComptable::resoudre($q);
        if (!$r['codes']) {
            return new Reponse(
                "De quel compte voulez-vous le solde ? Indiquez son numéro (ex. 512) ou son nom (ex. caisse, banque).",
                ['Solde du compte 512', 'Solde de la caisse', 'Quel est le compte de la banque ?']
            );
        }

        $date = $q->periode['fin'] ?? date('Y-m-d');
        $etats = new EtatsFinanciers();
        $cumules = $etats->soldesCumules($date);
        // Comptes de gestion (6/7) : depuis le début de l'exercice seulement
        $ex = ExerciceComptable::getExerciceByDate($date);
        $gestion = $etats->soldes($ex ? Carbon::parse($ex->Date_debut)->toDateString() : null, $date);

        $lignes = [];
        $total = 0.0;
        foreach ($r['trouves'] as $c) {
            $s = in_array($c->code[0], ['6', '7'], true) ? ($gestion[$c->code] ?? 0.0) : ($cumules[$c->code] ?? 0.0);
            $total += $s;
            $lignes[] = ['code' => $c->code, 'libelle' => $c->libelle, 'solde' => $s];
        }

        $libelleDate = Reponse::date($date);
        $titre = $r['par'] === 'code'
            ? "compte {$r['codes'][0]}"
            : 'comptes correspondant à « ' . implode(' ', $q->termesLibres) . ' »';
        if (count($lignes) === 1) {
            $l = $lignes[0];
            $texte = "Solde du compte **{$l['code']} — {$l['libelle']}** au {$libelleDate} :\n\n**" . self::sens($l['solde']) . '**';
        } else {
            $texte = "Soldes au {$libelleDate} ({$titre}) :\n\n";
            foreach (array_slice($lignes, 0, 15) as $l) {
                $texte .= "• {$l['code']} — {$l['libelle']} : " . self::sens($l['solde']) . "\n";
            }
            if (count($lignes) > 15) {
                $texte .= '… et ' . (count($lignes) - 15) . " autre(s) sous-compte(s)\n";
            }
            $texte .= "\n**Total : " . self::sens(round($total, 2)) . '**';
        }
        $texte .= "\n\nCalcul sur les écritures validées uniquement.";

        $compte = $r['trouves']->first()->compte;
        return (new Reponse($texte, ["Derniers mouvements du compte {$lignes[0]['code']}", 'Combien en trésorerie ?']))
            ->lien("Grand livre du compte {$compte}", "/grand-livres/{$compte}");
    }

    public static function sens(float $solde): string
    {
        if (abs($solde) < 0.005) {
            return 'soldé (0,00 Ar)';
        }
        return Reponse::montant(abs($solde)) . ($solde > 0 ? ' (débiteur)' : ' (créditeur)');
    }
}
