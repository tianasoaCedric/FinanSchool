<?php

namespace App\Services\Assistant\Comptabilite;

use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use Illuminate\Support\Facades\Auth;

/** Salutation et présentation de ce que l'assistant sait faire. */
class Aide extends Intention
{
    public const EXEMPLES = [
        'Solde du compte 512',
        'Derniers mouvements de la caisse',
        'Résultat de l\'exercice',
        'Combien en trésorerie ?',
        'Pièces à valider',
        'Que faire avant de clôturer ?',
    ];

    protected function motsCles(): array
    {
        return [
            'aide' => 3, 'help' => 3, 'que sais tu faire' => 3, 'que peux tu faire' => 3, 'comment ca marche' => 3,
            // Salutation seule : atteint le seuil ; avec une vraie question, l'autre intention l'emporte
            'bonjour' => 2, 'salut' => 2, 'bonsoir' => 2, 'hello' => 2, 'manao ahoana' => 2,
        ];
    }

    public function repondre(Question $q): Reponse
    {
        $nom = Auth::user()?->name;
        $texte = ($nom ? "Bonjour {$nom}. " : 'Bonjour. ')
            . "Je réponds à vos questions sur votre comptabilité, à partir des écritures validées :\n\n"
            . "• **Comptes** : solde d'un compte à une date, derniers mouvements, recherche d'un compte par son nom\n"
            . "• **Résultat** : chiffre d'affaires, charges, produits et résultat d'un exercice ou d'un mois\n"
            . "• **Trésorerie** : soldes des banques et des caisses\n"
            . "• **Saisie** : pièces en brouillon, motif pour lequel une pièce ne peut pas être validée\n"
            . "• **Clôture** : ce qu'il reste à faire avant de clôturer l'exercice\n"
            . "• **Journaux** : liste et détail d'un journal\n\n"
            . 'Vous pouvez préciser une période : « au 31/03/2025 », « en mars 2025 », « exercice 2024 ».';

        return new Reponse($texte, self::EXEMPLES);
    }
}
