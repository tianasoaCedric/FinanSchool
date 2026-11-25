<?php

namespace App\Services\Assistant\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EcritureGuard;

/** « Pourquoi BR-128 n'est pas validable ? », « pièce BNI2025-0012 ». */
class EtatPiece extends Intention
{
    protected function motsCles(): array
    {
        return [
            'pourquoi' => 2, 'validable' => 2, 'bloque' => 2, 'piece' => 1, 'etat' => 1, 'statut' => 1,
            'valider' => 1, 'refuse' => 2, 'erreur' => 1,
        ];
    }

    public function score(Question $q): float
    {
        // Une pièce citée suffit : « BR-128 ? » doit donner son état
        return $q->piece ? 4 + parent::score($q) : 0.0;
    }

    public function repondre(Question $q): Reponse
    {
        $piece = isset($q->piece['brouillon'])
            ? MouvementEcriture::with(['lignes', 'journal'])->find($q->piece['brouillon'])
            : MouvementEcriture::with(['lignes', 'journal'])->where('Numero_piece', $q->piece['numero'])->first();

        $ref = $q->piece['numero'] ?? 'BR-' . $q->piece['brouillon'];
        if (!$piece) {
            return new Reponse("Je ne trouve pas la pièce {$ref}.", ['Pièces à valider']);
        }

        $lignes = $piece->lignes;
        $debit = round((float) $lignes->sum('Debit'), 2);
        $credit = round((float) $lignes->sum('Credit'), 2);
        $entete = "Pièce **{$piece->numeroAffiche()}** du " . Reponse::date($piece->Date_mouvement)
            . ($piece->journal ? " (journal {$piece->journal->Code})" : '')
            . ($piece->Libelle ? " — {$piece->Libelle}" : '')
            . "\n{$lignes->count()} ligne(s), débit " . Reponse::montant($debit) . ', crédit ' . Reponse::montant($credit) . "\n\n";

        $validee = $lignes->isNotEmpty() && $lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE);
        if ($validee) {
            $l = $lignes->first();
            $par = $l->valide_par ? User::find($l->valide_par)?->name : null;
            $texte = $entete . '**Validée**' . ($l->date_validation ? ' le ' . Reponse::date($l->date_validation) : '')
                . ($par ? " par {$par}" : '') . '. Elle ne peut plus être modifiée ; pour la corriger, il faut la contre-passer.';
        } else {
            $motif = EcritureGuard::controlerPieceValidable($piece);
            $texte = $entete . ($motif === null
                ? '**Brouillon prêt à être validé** : tous les contrôles sont respectés.'
                : "**Brouillon non validable** : {$motif}.");
        }

        return (new Reponse($texte, ['Pièces à valider']))
            ->lien("Ouvrir la pièce {$piece->numeroAffiche()}", "/ecriture/{$piece->Id_Mouvement_ecriture}")
            ->lien('Historique de la pièce', "/audit?objet_type=piece&objet_id={$piece->Id_Mouvement_ecriture}");
    }
}
