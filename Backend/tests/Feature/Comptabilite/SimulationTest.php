<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Simulations budgétaires : réalisé de l'exercice de référence (validé
 * uniquement), hypothèses, enregistrement et modification d'un scénario.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class SimulationTest extends TestCase
{
    use DatabaseTransactions;

    private array $sc = [];
    private Journal $journal;
    private ExerciceComptable $ex2088;
    private ExerciceComptable $ex2089;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Sanctum::actingAs(User::factory()->create());
        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        $this->ex2088 = ExerciceComptable::create(['Annee_fiscale' => 2088, 'Date_debut' => '2088-01-01', 'Date_fin' => '2088-12-31', 'Statut' => 'OUVERT']);
        $this->ex2089 = ExerciceComptable::create(['Annee_fiscale' => 2089, 'Date_debut' => '2089-01-01', 'Date_fin' => '2089-12-31', 'Statut' => 'OUVERT']);

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['706996' => 'Écolages simulation', '641996' => 'Salaires simulation', '601996' => 'Achats simulation', '512996' => 'Banque simulation'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create(['Code' => 'TSI', 'Libelle' => 'Journal test simulation', 'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal')]);
    }

    private function piece(string $debit, string $credit, float $montant, string $date, string $action = 'valider'): void
    {
        $this->postJson('/api/pieces', [
            'Date_mouvement' => $date, 'Id_Journal' => $this->journal->Id_Journal, 'Libelle' => 'Simulation',
            'Reference' => 'SIM', 'action' => $action,
            'lignes' => [
                ['Id_Sous_compte' => $this->sc[$debit], 'Debit' => $montant, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc[$credit], 'Debit' => 0, 'Credit' => $montant],
            ],
        ])->assertCreated();
    }

    private function historique(ExerciceComptable $ex): array
    {
        return $this->getJson("/api/simulations/historical?id_exercice={$ex->Id_Exercice_comptable}")->assertOk()->json();
    }

    public function test_le_realise_ne_porte_que_sur_l_exercice_choisi_et_les_ecritures_validees(): void
    {
        $this->piece('512996', '706996', 1_200_000, '2088-03-01');
        $this->piece('641996', '512996', 500_000, '2088-04-01');
        $this->piece('601996', '512996', 100_000, '2088-05-01');
        $this->piece('512996', '706996', 9_999_999, '2089-02-01');            // autre exercice
        $this->piece('641996', '512996', 777_777, '2088-06-01', 'brouillon'); // brouillon

        $r = $this->historique($this->ex2088);
        $lignes = collect($r['lignes'])->keyBy('code');

        $this->assertSame(2088, $r['exercice']['annee']);
        $this->assertFalse($r['exercice']['en_cours']);
        $this->assertEquals(1_200_000, $lignes['706996']['reference']);
        $this->assertSame('produit', $lignes['706996']['type']);
        $this->assertEquals(500_000, $lignes['641996']['reference']);
        $this->assertSame('fixe', $lignes['641996']['nature_charge']);
        $this->assertSame('variable', $lignes['601996']['nature_charge']);
        $this->assertArrayNotHasKey('512996', $lignes->all(), 'seulement les comptes 6 et 7');
    }

    public function test_un_scenario_est_cree_puis_modifie_avec_ses_totaux(): void
    {
        $corps = [
            'nom_simulation' => 'Budget 2089 prudent',
            'description' => 'Hausse des écolages de 5 %',
            'id_exercice_comptable' => $this->ex2088->Id_Exercice_comptable,
            'annee_cible' => 2089,
            'hypotheses' => ['produits' => 5, 'charges_fixes' => 2.5, 'charges_variables' => 0],
            'lignes' => [
                ['libelle' => 'Écolages', 'type' => 'produit', 'nature_charge' => 'fixe', 'reference' => 1_200_000, 'coefficient' => 1.05, 'montant_simule' => 1_260_000, 'id_sous_compte' => $this->sc['706996']],
                ['libelle' => 'Salaires', 'type' => 'charge', 'nature_charge' => 'fixe', 'reference' => 500_000, 'coefficient' => 1.025, 'montant_simule' => 512_500, 'id_sous_compte' => $this->sc['641996']],
                ['libelle' => 'Achats', 'type' => 'charge', 'nature_charge' => 'variable', 'reference' => 100_000, 'coefficient' => 1, 'montant_simule' => 100_000, 'id_sous_compte' => $this->sc['601996']],
            ],
        ];

        $s = $this->postJson('/api/simulations', $corps)->assertCreated()->json();
        $this->assertSame(2089, $s['annee_cible']);
        $this->assertEquals(2.5, $s['hypotheses']['charges_fixes']);
        $this->assertEquals(1.025, collect($s['lignes'])->firstWhere('code', '641996')['coefficient'], 'coefficient précis à 4 décimales');
        $this->assertEquals(['produits' => 1_260_000, 'charges' => 612_500, 'resultat' => 647_500],
            array_intersect_key($s['totaux'], array_flip(['produits', 'charges', 'resultat'])));

        // Modification : les lignes sont remplacées
        $corps['nom_simulation'] = 'Budget 2089 révisé';
        $corps['lignes'] = [$corps['lignes'][0]];
        $m = $this->putJson("/api/simulations/{$s['id']}", $corps)->assertOk()->json();
        $this->assertSame('Budget 2089 révisé', $m['nom']);
        $this->assertCount(1, $m['lignes']);
        $this->assertEquals(1_260_000, $m['totaux']['resultat']);

        $liste = collect($this->getJson('/api/simulations')->assertOk()->json())->firstWhere('id', $s['id']);
        $this->assertSame(2088, $liste['exercice_reference']);
        $this->assertEquals(0, $liste['totaux']['charges_fixes']);
    }

    public function test_un_scenario_sans_nom_ou_sans_compte_est_refuse(): void
    {
        $this->postJson('/api/simulations', [
            'nom_simulation' => '', 'id_exercice_comptable' => $this->ex2088->Id_Exercice_comptable, 'lignes' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['nom_simulation', 'lignes']);
    }
}
