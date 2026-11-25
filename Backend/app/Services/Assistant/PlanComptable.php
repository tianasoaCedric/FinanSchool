<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recherche de comptes pour l'assistant : par code (préfixe) ou par libellé.
 */
class PlanComptable
{
    /** Sous-comptes dont le code commence par $code. */
    public static function parCode(string $code, int $limite = 50): Collection
    {
        return DB::table('sous_comptes as s')
            ->join('comptes as c', 'c.Id_Compte', '=', 's.Id_Compte')
            ->where('s.Code_sous_compte', 'like', $code . '%')
            ->orderBy('s.Code_sous_compte')
            ->limit($limite)
            ->get(['s.Code_sous_compte as code', 's.Libelle as libelle', 'c.Code_compte as compte', 'c.Libelle as libelle_compte']);
    }

    /**
     * Sous-comptes dont le libellé (ou celui de leur compte) contient les
     * termes cherchés, les plus pertinents d'abord.
     */
    public static function parLibelle(array $termes, int $limite = 10): Collection
    {
        if (!$termes) {
            return collect();
        }
        $lignes = DB::table('sous_comptes as s')
            ->join('comptes as c', 'c.Id_Compte', '=', 's.Id_Compte')
            ->where(function ($q) use ($termes) {
                foreach ($termes as $t) {
                    $q->orWhereRaw(self::sansAccents('s."Libelle"') . ' LIKE ?', ["%{$t}%"])
                      ->orWhereRaw(self::sansAccents('c."Libelle"') . ' LIKE ?', ["%{$t}%"]);
                }
            })
            ->get(['s.Code_sous_compte as code', 's.Libelle as libelle', 'c.Code_compte as compte', 'c.Libelle as libelle_compte']);

        // Le terme doit être un mot (ou un début de mot) du libellé : « caisse »
        // trouve « Caisse école » mais pas « scolarité à encaisser ».
        $motDe = fn (string $texte, string $t) => (bool) preg_match('/\b' . preg_quote($t, '/') . '/', Analyseur::normaliser($texte));

        return $lignes->map(function ($l) use ($termes, $motDe) {
            $l->pertinence = collect($termes)->filter(fn ($t) => $motDe($l->libelle . ' ' . $l->libelle_compte, $t))->count()
                // Un terme présent dans le libellé du sous-compte compte davantage
                + collect($termes)->filter(fn ($t) => $motDe($l->libelle, $t))->count() * 0.5;
            return $l;
        })->filter(fn ($l) => $l->pertinence > 0)
            ->sortBy([['pertinence', 'desc'], ['code', 'asc']])->take($limite)->values();
    }

    /** Expression SQL : colonne en minuscules et sans accents (comparable au texte normalisé). */
    private static function sansAccents(string $colonne): string
    {
        return "translate(lower({$colonne}), 'àâäáãåèéêëìíîïòóôöõùúûüçñ', 'aaaaaaeeeeiiiiooooouuuucn')";
    }

    /**
     * Comptes visés par la question : codes cités, sinon recherche par libellé.
     * @return array{codes: string[], trouves: Collection, par: 'code'|'libelle'|null}
     */
    public static function resoudre(Question $q): array
    {
        if ($q->codesComptes) {
            return ['codes' => $q->codesComptes, 'trouves' => self::parCode($q->codesComptes[0]), 'par' => 'code'];
        }
        $trouves = self::parLibelle($q->termesLibres);
        if ($trouves->isEmpty()) {
            return ['codes' => [], 'trouves' => $trouves, 'par' => null];
        }
        // Les résultats presque aussi pertinents que le premier (« banque » : le compte
        // « Banques comptes courants » et le sous-compte « Virement banque vers caisse »)
        $seuil = $trouves->first()->pertinence - 0.5;
        $meilleurs = $trouves->filter(fn ($c) => $c->pertinence >= $seuil)->values();
        return ['codes' => $meilleurs->pluck('code')->all(), 'trouves' => $meilleurs, 'par' => 'libelle'];
    }
}
