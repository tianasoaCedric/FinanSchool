<?php

namespace App\Services\Assistant\Comptabilite;

use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EtatsFinanciers;
use Illuminate\Support\Facades\DB;

/** « Combien en trésorerie ? », « disponibilités au 31/03 », « argent en banque ». */
class Tresorerie extends Intention
{
    protected function motsCles(): array
    {
        return [
            'tresorerie' => 3, 'disponibilites' => 3, 'liquidites' => 3, 'argent' => 2,
            'banque' => 1.5, 'banques' => 1.5, 'caisse' => 1.5, 'caisses' => 1.5, 'cash' => 2,
        ];
    }

    public function repondre(Question $q): Reponse
    {
        $date = $q->periode['fin'] ?? date('Y-m-d');
        $soldes = (new EtatsFinanciers())->soldesCumules($date);

        // Trésorerie : comptes financiers de classe 5 (banques, caisses, placements, virements internes)
        $comptes = array_filter($soldes, fn ($s, $code) => str_starts_with((string) $code, '5'), ARRAY_FILTER_USE_BOTH);
        ksort($comptes, SORT_STRING);
        $libelles = DB::table('sous_comptes')->whereIn('Code_sous_compte', array_map('strval', array_keys($comptes)))
            ->pluck('Libelle', 'Code_sous_compte');

        if (!$comptes) {
            return new Reponse('Aucun solde de trésorerie au ' . Reponse::date($date) . ' (comptes de classe 5).',
                ['Derniers mouvements de la banque']);
        }

        $texte = 'Trésorerie au ' . Reponse::date($date) . " :\n\n";
        foreach ($comptes as $code => $s) {
            $texte .= "• {$code} — " . ($libelles[$code] ?? '') . ' : ' . SoldeCompte::sens($s) . "\n";
        }
        $total = round(array_sum($comptes), 2);
        $texte .= "\n**Total : " . Reponse::montant($total) . '**';
        if ($total < 0) {
            $texte .= "\n\nAttention : la trésorerie globale est négative.";
        }
        foreach ($comptes as $code => $s) {
            if (str_starts_with((string) $code, '53') && $s < -0.005) {
                $texte .= "\n\nAnomalie : la caisse {$code} est créditrice. Une caisse ne peut pas avoir un solde négatif : vérifiez les écritures.";
            }
        }

        return (new Reponse($texte . "\n\nCalcul sur les écritures validées uniquement.", ['Derniers mouvements de la caisse', 'Résultat de l\'exercice']))
            ->lien('Tableau des flux de trésorerie', '/flux-tresorerie');
    }
}
