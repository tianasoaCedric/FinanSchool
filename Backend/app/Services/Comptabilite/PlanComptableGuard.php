<?php

namespace App\Services\Comptabilite;

use App\Models\PlanCompte\Classe;
use App\Models\PlanCompte\Compte;
use App\Models\PlanCompte\Rubrique;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Protection du plan comptable et des journaux (PCG 2005 : un compte
 * mouvementé ne disparaît pas de la comptabilité).
 *
 * Avant chaque suppression, on vérifie que l'élément n'est pas utilisé ;
 * sinon une ValidationException (HTTP 422) explique pourquoi. Cela évite
 * aussi les suppressions en cascade (classe → rubriques → comptes →
 * sous-comptes) définies en base.
 */
class PlanComptableGuard
{
    public static function assertClasseSupprimable(Classe $classe): void
    {
        $n = DB::table('rubriques')->where('Id_Classe', $classe->Id_Classe)->count();
        if ($n > 0) {
            self::refuser("La classe {$classe->Code} contient {$n} rubrique(s) : supprimez-les d'abord.");
        }
    }

    public static function assertRubriqueSupprimable(Rubrique $rubrique): void
    {
        $n = DB::table('comptes')->where('Id_Rubrique', $rubrique->Id_Rubrique)->count();
        if ($n > 0) {
            self::refuser("La rubrique {$rubrique->Code_rubrique} contient {$n} compte(s) : supprimez-les d'abord.");
        }
    }

    public static function assertCompteSupprimable(Compte $compte): void
    {
        $n = DB::table('sous_comptes')->where('Id_Compte', $compte->Id_Compte)->count();
        if ($n > 0) {
            self::refuser("Le compte {$compte->Code_compte} contient {$n} sous-compte(s) : supprimez-les d'abord.");
        }
    }

    public static function assertSousCompteSupprimable(SousCompte $sousCompte): void
    {
        $id = $sousCompte->Id_Sous_compte;
        $code = $sousCompte->Code_sous_compte;

        if (DB::table('ligne_ecritures')->where('Id_Sous_compte', $id)->exists()) {
            self::refuser("Le sous-compte {$code} est mouvementé : il ne peut pas être supprimé.");
        }
        $journal = DB::table('journals')->where('Id_Sous_compte', $id)->value('Code');
        if ($journal) {
            self::refuser("Le sous-compte {$code} est la contrepartie du journal {$journal} : changez d'abord le compte du journal.");
        }
        if (DB::table('immobilisations')->where('Id_Sous_compte', $id)->exists()) {
            self::refuser("Le sous-compte {$code} est utilisé par des immobilisations : il ne peut pas être supprimé.");
        }
        if (DB::table('compte_categories')->where('id_sous_compte', $id)->exists()) {
            self::refuser("Le sous-compte {$code} est utilisé dans le paramétrage des états financiers : il ne peut pas être supprimé.");
        }
    }

    // ---- Changement de code (le libellé reste toujours modifiable) ----------
    // Les codes portent la hiérarchie (601001 → compte 601 → rubrique 60 →
    // classe 6) et servent aux états financiers, aux catégories et aux comptes
    // d'amortissement : on ne les change que sur un élément encore « vierge ».

    public static function assertCodeClasseModifiable(Classe $classe, string $nouveau): void
    {
        if ($nouveau !== (string) $classe->Code && DB::table('rubriques')->where('Id_Classe', $classe->Id_Classe)->exists()) {
            self::refuser("Le code de la classe {$classe->Code} ne peut plus changer : elle contient des rubriques. Seul le libellé est modifiable.");
        }
    }

    public static function assertCodeRubriqueModifiable(Rubrique $rubrique, string $nouveau): void
    {
        if ($nouveau !== (string) $rubrique->Code_rubrique && DB::table('comptes')->where('Id_Rubrique', $rubrique->Id_Rubrique)->exists()) {
            self::refuser("Le code de la rubrique {$rubrique->Code_rubrique} ne peut plus changer : elle contient des comptes. Seul le libellé est modifiable.");
        }
    }

    public static function assertCodeCompteModifiable(Compte $compte, string $nouveau): void
    {
        if ($nouveau !== (string) $compte->Code_compte && DB::table('sous_comptes')->where('Id_Compte', $compte->Id_Compte)->exists()) {
            self::refuser("Le code du compte {$compte->Code_compte} ne peut plus changer : il contient des sous-comptes. Seul le libellé est modifiable.");
        }
    }

    public static function assertCodeSousCompteModifiable(SousCompte $sousCompte, string $nouveau): void
    {
        if ($nouveau === (string) $sousCompte->Code_sous_compte) {
            return;
        }
        try {
            // Mêmes usages que ceux qui interdisent la suppression
            self::assertSousCompteSupprimable($sousCompte);
        } catch (ValidationException) {
            self::refuser("Le code du sous-compte {$sousCompte->Code_sous_compte} ne peut plus changer : il est déjà utilisé (écritures, journal, immobilisation ou états). Seul le libellé est modifiable.");
        }
    }

    public static function assertJournalSupprimable(Journal $journal): void
    {
        $utilise = DB::table('mouvement_ecritures')->where('Id_Journal', $journal->Id_Journal)->exists()
            || DB::table('ligne_ecritures')->where('Id_Journal', $journal->Id_Journal)->exists();
        if ($utilise) {
            self::refuser("Le journal {$journal->Code} contient des écritures : il ne peut pas être supprimé.");
        }
    }

    private static function refuser(string $message): never
    {
        throw ValidationException::withMessages(['plan_comptable' => [$message]]);
    }
}
