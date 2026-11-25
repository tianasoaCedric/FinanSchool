<?php

namespace App\Services\Assistant\Comptabilite;

use App\Services\Assistant\Analyseur;
use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EtatsFinanciers;

/** « Résultat 2025 », « chiffre d'affaires de mars », « total des charges ». */
class Resultat extends Intention
{
    protected function motsCles(): array
    {
        return [
            'resultat' => 3, 'benefice' => 3, 'perte' => 3, 'chiffre d affaires' => 3, 'chiffre d affaire' => 3,
            'ca' => 2, 'compte de resultat' => 3, 'produits' => 2, 'charges' => 2, 'depenses' => 2,
            'recettes' => 2, 'ventes' => 2, 'rentable' => 2, 'gagne' => 2, 'marge' => 2,
            'valeur ajoutee' => 3, 'ebe' => 3, 'excedent brut' => 3,
        ];
    }

    public function repondre(Question $q): Reponse
    {
        $p = $q->periode ?? Analyseur::periodeParDefaut();
        $postes = (new EtatsFinanciers())->postesResultat($p['debut'], $p['fin']);

        $produits = round($postes['total_produits_ao'] + $postes['prod_extra'], 2);
        $charges = round($postes['total_charges_ao'] + $postes['charges_extra'], 2);
        $net = $postes['resultat_net'];
        $periode = $p['libelle'] . ' (du ' . Reponse::date($p['debut']) . ' au ' . Reponse::date($p['fin']) . ')';

        $titre = match (true) {
            Intention::trouve($q, 'chiffre d affaires') || Intention::trouve($q, 'ca') || Intention::trouve($q, 'ventes')
                => "Chiffre d'affaires : **" . Reponse::montant($postes['ca']) . "**\n\n",
            Intention::trouve($q, 'charges') || Intention::trouve($q, 'depenses')
                => 'Total des charges : **' . Reponse::montant($charges) . "**\n\n",
            Intention::trouve($q, 'produits') || Intention::trouve($q, 'recettes')
                => 'Total des produits : **' . Reponse::montant($produits) . "**\n\n",
            default => '',
        };

        $texte = "Compte de résultat — {$periode}\n\n{$titre}"
            . "• Chiffre d'affaires : " . Reponse::montant($postes['ca']) . "\n"
            . '• Total des produits : ' . Reponse::montant($produits) . "\n"
            . '• Total des charges : ' . Reponse::montant($charges) . "\n"
            . '  dont achats ' . Reponse::montant($postes['achats']) . ', personnel ' . Reponse::montant($postes['personnel'])
            . ', services extérieurs ' . Reponse::montant($postes['services']) . "\n"
            . '• Valeur ajoutée : ' . Reponse::montant($postes['valeur_ajoutee']) . "\n"
            . '• Excédent brut d\'exploitation : ' . Reponse::montant($postes['ebe']) . "\n\n"
            . '**Résultat net : ' . Reponse::montant(abs($net)) . ($net >= 0 ? ' (bénéfice)' : ' (perte)') . '**'
            . ($p['explicite'] ? '' : "\n\nPériode par défaut : l'exercice en cours. Précisez « en mars 2025 » ou « exercice 2024 » pour une autre période.")
            . "\n\nCalcul sur les écritures validées uniquement.";

        return (new Reponse($texte, ['Combien en trésorerie ?', 'Que faire avant de clôturer ?']))
            ->lien('Compte de résultat par nature', '/CR-Nature');
    }
}
