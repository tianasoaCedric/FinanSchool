<?php

namespace App\Services\Assistant\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use App\Services\Assistant\Intention;
use App\Services\Assistant\PlanComptable;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use App\Services\Comptabilite\EcritureGuard;
use Illuminate\Support\Facades\DB;

/** « Derniers mouvements du compte 531 », « grand livre de la banque en mars ». */
class MouvementsCompte extends Intention
{
    private const LIMITE = 10;

    protected function motsCles(): array
    {
        return [
            'mouvement' => 3, 'ecriture' => 2, 'operation' => 2, 'grand livre' => 3, 'historique' => 2,
            'releve' => 2, 'dernier' => 1, 'derniere' => 1,
        ];
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
                'De quel compte voulez-vous les mouvements ? Indiquez son numéro (ex. 512) ou son nom (ex. caisse).',
                ['Derniers mouvements du compte 512', 'Derniers mouvements de la caisse']
            );
        }

        $requete = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->when($r['par'] === 'code',
                fn ($w) => $w->where('s.Code_sous_compte', 'like', $r['codes'][0] . '%'),
                fn ($w) => $w->whereIn('s.Code_sous_compte', $r['codes']))
            ->when($q->periode, fn ($w) => $w->whereBetween('m.Date_mouvement', [$q->periode['debut'], $q->periode['fin']]));

        $nombre = (clone $requete)->count();
        $lignes = $requete->orderByDesc('m.Date_mouvement')->orderByDesc('l.Id_Ligne_ecriture')
            ->limit(self::LIMITE)
            ->get(['m.Id_Mouvement_ecriture as id', 'm.Date_mouvement as date', 'm.Numero_piece as numero',
                   'l.Libelle as libelle', 'l.Debit as debit', 'l.Credit as credit', 's.Code_sous_compte as code']);

        $plusieurs = $r['par'] === 'libelle' && count($r['codes']) > 1;
        $cible = match (true) {
            $r['par'] === 'code' => "compte {$r['codes'][0]}",
            $plusieurs => 'comptes ' . implode(', ', $r['codes']),
            default => 'compte ' . $r['trouves']->first()->code . ' — ' . $r['trouves']->first()->libelle,
        };
        $periode = $q->periode ? " ({$q->periode['libelle']})" : '';

        if ($lignes->isEmpty()) {
            return new Reponse("Aucune écriture validée sur le {$cible}{$periode}.", ["Solde du {$cible}"]);
        }

        $texte = ($nombre > self::LIMITE ? 'Les ' . self::LIMITE . " derniers mouvements sur {$nombre}" : "{$nombre} mouvement(s)")
            . " du **{$cible}**{$periode} :\n\n";
        foreach ($lignes as $l) {
            $sens = (float) $l->debit > 0 ? 'Débit ' . Reponse::montant((float) $l->debit) : 'Crédit ' . Reponse::montant((float) $l->credit);
            $compte = ($plusieurs || strlen($r['codes'][0]) < 6) ? " — {$l->code}" : '';
            $texte .= '• ' . Reponse::date($l->date) . " — {$l->numero}{$compte} — {$l->libelle} : {$sens}\n";
        }

        $compte = $r['trouves']->first()->compte;
        $reponse = (new Reponse(rtrim($texte), ["Solde du compte {$r['trouves']->first()->code}"]))
            ->lien("Grand livre du compte {$compte}", "/grand-livres/{$compte}");
        $premiere = $lignes->first();
        return $reponse->lien("Ouvrir la pièce {$premiere->numero}", "/ecriture/{$premiere->id}");
    }
}
