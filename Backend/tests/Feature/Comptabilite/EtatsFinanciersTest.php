<?php

namespace Tests\Feature\Comptabilite;

use App\Http\Controllers\general\BilanPassifController;
use App\Http\Controllers\general\CompteResultatNatureController;
use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\User;
use App\Services\Comptabilite\EtatsFinanciers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lots 2-3 — états financiers PCG 2005.
 * Scénario d'écritures aux effets connus, comparé avant / après sur la base
 * « finance_test » (qui contient déjà des données : on raisonne en variations).
 */
class EtatsFinanciersTest extends TestCase
{
    use DatabaseTransactions;

    private const DEBUT = '2025-01-01';
    private const FIN = '2025-12-31';

    private Journal $journal;
    private array $sc = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        ExerciceComptable::where('Annee_fiscale', '!=', 2025)->update(['Statut' => 'CLOTURE']);
        ExerciceComptable::where('Annee_fiscale', 2025)->update(['Statut' => 'OUVERT']);
        Sanctum::actingAs(User::factory()->create());

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['101999' => 'Capital test', '218999' => 'Matériel test', '281899' => 'Amort. matériel test',
                  '681999' => 'Dotation test', '411999' => 'Client test', '706999' => 'Écolages test',
                  '671999' => 'Charge extraordinaire test', '512999' => 'Banque test'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::create(['Code_sous_compte' => $code, 'Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TEF', 'Libelle' => 'Journal test états',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
    }

    private function piece(string $debit, string $credit, float $montant): void
    {
        $this->postJson('/api/pieces', [
            'Date_mouvement' => '2025-06-30',
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => "Test {$debit}/{$credit}",
            'Reference' => 'FAC-TEST',
            'action' => 'valider',
            'lignes' => [
                ['Id_Sous_compte' => $this->sc[$debit], 'Debit' => $montant, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc[$credit], 'Debit' => 0, 'Credit' => $montant],
            ],
        ])->assertCreated();
    }

    private function photo(): array
    {
        $e = new EtatsFinanciers();
        $bilan = $e->bilan(self::DEBUT, self::FIN);
        $flux = collect($e->fluxTresorerie(self::DEBUT, self::FIN))->pluck('montant', 'label');
        return ['cr' => $e->postesResultat(self::DEBUT, self::FIN), 'bilan' => $bilan, 'flux' => $flux, 'controles' => $e->controles(self::DEBUT, self::FIN)];
    }

    private function scenario(): array
    {
        $avant = $this->photo();
        $this->piece('512999', '101999', 1_000_000);   // apport en capital
        $this->piece('218999', '512999', 500_000);     // achat de matériel
        $this->piece('681999', '281899', 100_000);     // dotation aux amortissements
        $this->piece('411999', '706999', 300_000);     // écolages facturés (à crédit)
        $this->piece('671999', '512999', 20_000);      // charge extraordinaire
        return [$avant, $this->photo()];
    }

    public function test_compte_de_resultat_signe_et_sans_double_comptage(): void
    {
        [$avant, $apres] = $this->scenario();
        $d = fn ($k) => round($apres['cr'][$k] - $avant['cr'][$k], 2);

        $this->assertEquals(300_000, $d('ca'));
        $this->assertEquals(100_000, $d('dotations'));
        $this->assertEquals(20_000, $d('charges_extra'));
        $this->assertEquals(200_000, $d('resultat_ao'));      // hors extraordinaire
        $this->assertEquals(-20_000, $d('resultat_extra'));
        $this->assertEquals(180_000, $d('resultat_net'));     // extraordinaire compté une seule fois
        $this->assertEquals(round($apres['cr']['resultat_ao'] + $apres['cr']['resultat_extra'], 2), $apres['cr']['resultat_net']);
    }

    public function test_bilan_postes_et_equilibre(): void
    {
        [$avant, $apres] = $this->scenario();
        $da = fn ($poste, $col) => round($apres['bilan']['actif'][$poste][$col] - $avant['bilan']['actif'][$poste][$col], 2);
        $dp = fn ($poste) => round($apres['bilan']['passif'][$poste] - $avant['bilan']['passif'][$poste], 2);

        $this->assertEquals(500_000, $da('corporelles', 'brut'));
        $this->assertEquals(100_000, $da('corporelles', 'amort'));
        $this->assertEquals(300_000, $da('clients', 'brut'));
        $this->assertEquals(480_000, $da('tresorerie', 'brut'));   // +1 000 000 − 500 000 − 20 000
        $this->assertEquals(1_000_000, $dp('capital'));
        $this->assertEquals(180_000, $dp('resultat'));
        $this->assertTrue($apres['controles']['equilibre_bilan']['ok'], 'Actif = Passif');
    }

    public function test_flux_de_tresorerie_rapproches(): void
    {
        [$avant, $apres] = $this->scenario();
        $d = fn ($label) => round($apres['flux'][$label] - $avant['flux'][$label], 2);

        $this->assertEquals(100_000, $d('Amortissements, dépréciations et provisions (nets)'));
        $this->assertEquals(-300_000, $d('Variation des clients'));
        $this->assertEquals(-500_000, $d('Flux de trésorerie liés aux opérations d\'investissement (B)'));
        $this->assertEquals(1_000_000, $d('Augmentations de capital, réserves et dividendes'));
        $this->assertEquals(480_000, $d('Variation de trésorerie de la période (A + B + C)'));
        $this->assertTrue($apres['controles']['tresorerie']['ok'], 'Flux = variation de la classe 5');
    }

    public function test_tous_les_controles_sont_coherents(): void
    {
        [, $apres] = $this->scenario();
        $c = $apres['controles'];
        $this->assertTrue($c['equilibre_bilan']['ok']);
        $this->assertTrue($c['resultat']['ok']);
        $this->assertTrue($c['tresorerie']['ok']);
        $this->assertTrue($c['capitaux_propres']['ok']);
        $this->assertSame(0, $c['pieces_validees_desequilibrees']);
    }

    public function test_les_brouillons_ne_comptent_pas(): void
    {
        $avant = (new EtatsFinanciers())->postesResultat(self::DEBUT, self::FIN)['ca'];
        $this->postJson('/api/pieces', [
            'Date_mouvement' => '2025-06-30', 'Id_Journal' => $this->journal->Id_Journal, 'action' => 'brouillon',
            'lignes' => [
                ['Id_Sous_compte' => $this->sc['411999'], 'Debit' => 999_999, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc['706999'], 'Debit' => 0, 'Credit' => 999_999],
            ],
        ])->assertCreated();
        $this->assertEquals($avant, (new EtatsFinanciers())->postesResultat(self::DEBUT, self::FIN)['ca']);
    }

    public function test_les_endpoints_des_etats_repondent(): void
    {
        $q = '?date_debut=' . self::DEBUT . '&date_fin=' . self::FIN;
        foreach (['/api/bilan/actif', '/api/bilan/passif', '/api/compte-resultat/nature', '/api/compte-resultat/fonction',
                  '/api/flux-tresorerie', '/api/variations-capitaux', '/api/etats-financiers/controles'] as $url) {
            $this->getJson($url . $q)->assertOk();
        }
    }

    public function test_les_anciens_calculs_du_module_analyse_restent_disponibles(): void
    {
        // Le module Analyse appelle encore ces méthodes : elles ne doivent pas avoir disparu.
        $this->assertIsArray(CompteResultatNatureController::calculerCompteResultat(self::DEBUT, self::FIN));
        $this->assertNotNull(BilanPassifController::getBilanPassif(self::DEBUT, self::FIN));
    }
}
