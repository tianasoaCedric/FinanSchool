<?php

namespace App\Services\Comptabilite;

use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Services\Parametres;
use Illuminate\Support\Facades\DB;

/**
 * Comptes et journaux techniques utilisés par la clôture et les dotations.
 * Ils sont créés à la demande s'ils n'existent pas encore.
 */
class ComptesSysteme
{
    /**
     * Sous-compte $code (6 caractères) rattaché au compte de 3 chiffres
     * correspondant ; crée le compte et/ou le sous-compte au besoin.
     */
    public static function sousCompte(string $code, string $libelle, ?int $rubriqueParDefaut = null): SousCompte
    {
        $existant = SousCompte::where('Code_sous_compte', $code)->first();
        if ($existant) {
            return $existant;
        }

        $codeCompte = substr($code, 0, 3);
        $idCompte = DB::table('comptes')->where('Code_compte', $codeCompte)->value('Id_Compte');
        if (!$idCompte) {
            // Rubrique d'un compte voisin de la même classe (ex. 28x → rubrique de 281)
            $rubrique = DB::table('comptes')->where('Code_compte', 'like', substr($code, 0, 2) . '%')->value('Id_Rubrique')
                ?? DB::table('comptes')->where('Code_compte', 'like', $code[0] . '%')->value('Id_Rubrique')
                ?? $rubriqueParDefaut;
            $idCompte = DB::table('comptes')->insertGetId([
                'Code_compte' => $codeCompte,
                'Libelle' => $libelle,
                'Id_Rubrique' => $rubrique,
            ], 'Id_Compte');
        }

        return SousCompte::create([
            'Code_sous_compte' => $code,
            'Libelle' => $libelle,
            'Id_Compte' => $idCompte,
        ]);
    }

    public static function reportBeneficiaire(): SousCompte
    {
        return self::sousCompte(Parametres::get('systeme.compte_report_crediteur'), 'Report à nouveau (solde créditeur)');
    }

    public static function reportDeficitaire(): SousCompte
    {
        return self::sousCompte(Parametres::get('systeme.compte_report_debiteur'), 'Report à nouveau (solde débiteur)');
    }

    public static function resultatBenefice(): SousCompte
    {
        return self::sousCompte(Parametres::get('systeme.compte_resultat_benefice'), "Résultat net de l'exercice (bénéfice)");
    }

    public static function resultatPerte(): SousCompte
    {
        return self::sousCompte(Parametres::get('systeme.compte_resultat_perte'), "Résultat net de l'exercice (perte)");
    }

    public static function dotationsAmortissements(): SousCompte
    {
        return self::sousCompte(Parametres::get('systeme.compte_dotations'), 'Dotations aux amortissements des immobilisations');
    }

    /**
     * Compte d'amortissement d'une immobilisation : 2Xyzzz → 28Xyzz
     * (ex. 218001 Mobilier scolaire → 281801 Amortissement mobilier scolaire).
     */
    public static function amortissementDe(SousCompte $immobilisation): SousCompte
    {
        $c = str_pad($immobilisation->Code_sous_compte, 6, '0');
        $code = '28' . $c[1] . $c[2] . substr($c, -2);
        return self::sousCompte($code, 'Amortissement ' . mb_strtolower($immobilisation->Libelle));
    }

    public static function journal(string $code, string $libelle): Journal
    {
        $journal = Journal::where('Code', $code)->first();
        if ($journal) {
            return $journal;
        }
        $type = DB::table('type_journals')->where('Type', 'Opérations diverses')->value('Id_Type_Journal')
            ?? DB::table('type_journals')->insertGetId(['Type' => 'Opérations diverses'], 'Id_Type_Journal');
        return Journal::create(['Code' => $code, 'Libelle' => $libelle, 'Id_Type_Journal' => $type]);
    }

    public static function journalANouveaux(): Journal
    {
        return self::journal(Parametres::get('systeme.journal_a_nouveaux'), 'Journal des à-nouveaux');
    }

    public static function journalCloture(): Journal
    {
        return self::journal(Parametres::get('systeme.journal_cloture'), 'Écritures de clôture');
    }

    public static function journalOperationsDiverses(): Journal
    {
        return self::journal(Parametres::get('systeme.journal_od'), 'Opérations diverses');
    }
}
