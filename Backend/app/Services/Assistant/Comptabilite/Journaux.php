<?php

namespace App\Services\Assistant\Comptabilite;

use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;
use Illuminate\Support\Facades\DB;

/** « Liste des journaux », « journal BNI ». */
class Journaux extends Intention
{
    protected function motsCles(): array
    {
        return ['journal' => 3, 'journaux' => 3];
    }

    protected function bonus(Question $q): float
    {
        return $q->journal ? 1 : 0;
    }

    public function repondre(Question $q): Reponse
    {
        return $q->journal ? $this->detail($q->journal['id']) : $this->liste();
    }

    private function liste(): Reponse
    {
        $journaux = DB::table('journals as j')
            ->leftJoin('type_journals as t', 't.Id_Type_Journal', '=', 'j.Id_Type_Journal')
            ->orderBy('j.Code')
            ->get(['j.Code', 'j.Libelle', 't.Type as type']);
        if ($journaux->isEmpty()) {
            return new Reponse('Aucun journal n\'est encore créé.');
        }
        $texte = "{$journaux->count()} journal(aux) :\n\n";
        foreach ($journaux as $j) {
            $texte .= "• **{$j->Code}** — {$j->Libelle}" . ($j->type ? " ({$j->type})" : '') . "\n";
        }
        return (new Reponse(rtrim($texte), ["Journal {$journaux->first()->Code}"]))->lien('Tous les journaux', '/journal');
    }

    private function detail(int $id): Reponse
    {
        $j = DB::table('journals as j')
            ->leftJoin('type_journals as t', 't.Id_Type_Journal', '=', 'j.Id_Type_Journal')
            ->leftJoin('sous_comptes as s', 's.Id_Sous_compte', '=', 'j.Id_Sous_compte')
            ->where('j.Id_Journal', $id)
            ->first(['j.Code', 'j.Libelle', 't.Type as type', 's.Code_sous_compte as compte', 's.Libelle as libelle_compte']);

        $pieces = DB::table('mouvement_ecritures')->where('Id_Journal', $id);
        $nb = (clone $pieces)->count();
        $derniere = (clone $pieces)->max('Date_mouvement');

        $texte = "Journal **{$j->Code} — {$j->Libelle}**\n\n"
            . '• Type : ' . ($j->type ?? '—') . "\n"
            . '• Compte de contrepartie : ' . ($j->compte ? "{$j->compte} — {$j->libelle_compte}" : 'aucun') . "\n"
            . "• Pièces : {$nb}" . ($derniere ? ' (dernière le ' . Reponse::date($derniere) . ')' : '');

        return (new Reponse($texte, ["Pièces à valider du journal {$j->Code}"]))
            ->lien("Écritures du journal {$j->Code}", "/ecritures-journal/{$id}");
    }
}
