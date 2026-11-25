<?php

namespace App\Services\Assistant\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EcritureGuard;

/** « Pièces à valider », « combien de brouillons ? », « pièces en attente en mars ». */
class PiecesAValider extends Intention
{
    private const LIMITE = 8;

    protected function motsCles(): array
    {
        return [
            'brouillon' => 3, 'a valider' => 3, 'en attente' => 2, 'non valide' => 3, 'non validee' => 3,
            'valider' => 1.5, 'validation' => 1.5, 'piece' => 1,
        ];
    }

    protected function bonus(Question $q): float
    {
        return $q->piece ? -3 : 0; // une pièce précise relève de EtatPiece
    }

    public function repondre(Question $q): Reponse
    {
        $brouillons = MouvementEcriture::with(['lignes', 'journal'])
            ->where(fn ($w) => $w->whereHas('lignes', fn ($l) => $l->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut'))
                ->orWhereDoesntHave('lignes'))
            ->when($q->periode, fn ($w) => $w->whereBetween('Date_mouvement', [$q->periode['debut'], $q->periode['fin']]))
            ->when($q->journal, fn ($w) => $w->where('Id_Journal', $q->journal['id']))
            ->orderBy('Date_mouvement')
            ->get();

        $filtre = trim(($q->journal ? " du journal {$q->journal['code']}" : '') . ($q->periode ? " ({$q->periode['libelle']})" : ''));
        if ($brouillons->isEmpty()) {
            return (new Reponse("Aucune pièce en brouillon{$filtre} : tout est validé.", ['Que faire avant de clôturer ?']))
                ->lien('Toutes les pièces', '/pieces');
        }

        $motifs = $brouillons->mapWithKeys(fn ($p) => [$p->Id_Mouvement_ecriture => EcritureGuard::controlerPieceValidable($p)]);
        $validables = $motifs->filter(fn ($m) => $m === null)->count();
        $bloquees = $brouillons->count() - $validables;

        $texte = "**{$brouillons->count()} pièce(s) en brouillon**{$filtre} :\n"
            . "• {$validables} prête(s) à être validée(s)\n"
            . "• {$bloquees} à corriger avant validation\n";

        $aCorriger = $brouillons->filter(fn ($p) => $motifs[$p->Id_Mouvement_ecriture] !== null)->take(self::LIMITE);
        if ($aCorriger->isNotEmpty()) {
            $texte .= "\nÀ corriger :\n";
            foreach ($aCorriger as $p) {
                $texte .= "• {$p->numeroAffiche()} du " . Reponse::date($p->Date_mouvement) . ' : ' . $motifs[$p->Id_Mouvement_ecriture] . "\n";
            }
        }

        $reponse = (new Reponse(rtrim($texte), ['Que faire avant de clôturer ?']))->lien('Valider les pièces', '/validation');
        if ($p = $aCorriger->first()) {
            $reponse->lien("Ouvrir {$p->numeroAffiche()}", "/ecriture/{$p->Id_Mouvement_ecriture}");
            $reponse->suggestions[] = "Pourquoi {$p->numeroAffiche()} n'est pas validable ?";
        }
        return $reponse;
    }
}
