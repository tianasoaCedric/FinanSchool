<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use App\Services\Comptabilite\EtatsFinanciers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lot 5 — organisation comptable PCG 2005 : exercices N / N+1, pièce
 * justificative, clôture mensuelle, contre-passation, extournes,
 * affectation du résultat, balance et grand livre.
 * Exercices fictifs 2090 / 2091 (la base finance_test contient des données réelles).
 */
class OrganisationComptableTest extends TestCase
{
    use DatabaseTransactions;

    private ExerciceComptable $n;
    private ExerciceComptable $n1;
    private Journal $journal;
    private array $sc = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        // Les tiers sont testés dans TiersLettrageTest
        DB::table('parametres')->updateOrInsert(['cle' => 'tiers.obligatoire'], ['valeur' => 'false']);
        \App\Services\Parametres::oublier();
        Sanctum::actingAs(User::factory()->create());

        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        $this->n = ExerciceComptable::create(['Annee_fiscale' => 2090, 'Date_debut' => '2090-01-01', 'Date_fin' => '2090-12-31', 'Statut' => 'OUVERT']);
        $this->n1 = ExerciceComptable::create(['Annee_fiscale' => 2091, 'Date_debut' => '2091-01-01', 'Date_fin' => '2091-12-31', 'Statut' => 'CLOTURE']);
        DB::table('immobilisations')->delete();

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['101999' => 'Capital test', '411999' => 'Client test', '706999' => 'Écolages test',
                  '512999' => 'Banque test', '641999' => 'Salaires test', '428999' => 'Personnel charges à payer test'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TOC', 'Libelle' => 'Journal test organisation',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
    }

    private function piece(string $debit, string $credit, float $montant, string $date = '2090-06-30', string $action = 'valider', array $entete = [])
    {
        return $this->postJson('/api/pieces', array_merge([
            'Date_mouvement' => $date,
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => "Test {$debit}/{$credit}",
            'Reference' => 'FAC-TEST',
            'action' => $action,
            'lignes' => [
                ['Id_Sous_compte' => $this->sc[$debit], 'Debit' => $montant, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc[$credit], 'Debit' => 0, 'Credit' => $montant],
            ],
        ], $entete));
    }

    private function url(ExerciceComptable $e, string $suffixe = ''): string
    {
        return "/api/exercices/{$e->Id_Exercice_comptable}{$suffixe}";
    }

    private function netDansPiece(int $idPiece, string $code): float
    {
        return round((float) DB::table('ligne_ecritures as l')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.Id_Mouvement_ecriture', $idPiece)->where('s.Code_sous_compte', $code)
            ->sum(DB::raw('l."Debit" - l."Credit"')), 2);
    }

    /** Bénéfice 2090 de 200 000 puis clôture. */
    private function exerciceClotureBeneficiaire(): array
    {
        $this->piece('512999', '101999', 1_000_000)->assertCreated();
        $this->piece('411999', '706999', 300_000)->assertCreated();
        $this->piece('641999', '512999', 100_000)->assertCreated();
        return $this->postJson($this->url($this->n, '/cloturer'))->assertOk()->json();
    }

    // ------------------------------------------------------------------
    // Exercices N et N+1
    // ------------------------------------------------------------------

    public function test_n_et_n_plus_1_ouverts_ensemble_mais_pas_trois(): void
    {
        $this->postJson('/api/exercices/ouvrir-suivant')->assertCreated()->assertJsonPath('exercice.annee', 2091);
        $this->assertSame('OUVERT', $this->n->fresh()->Statut, 'N reste ouvert pour les travaux d\'inventaire');
        $this->assertSame('OUVERT', $this->n1->fresh()->Statut);
        $this->assertSame(2091, (int) ExerciceComptable::getExerciceOuvert()->Annee_fiscale);

        $this->piece('411999', '706999', 1_000, '2090-12-15')->assertCreated();
        $this->piece('411999', '706999', 1_000, '2091-01-15')->assertCreated();

        // Un troisième exercice ouvert est refusé (et n'est pas créé)
        $this->postJson('/api/exercices/ouvrir-suivant')->assertStatus(422);
        $this->assertNull(ExerciceComptable::where('Annee_fiscale', 2092)->first());
    }

    // ------------------------------------------------------------------
    // Pièce justificative
    // ------------------------------------------------------------------

    public function test_la_reference_du_justificatif_est_exigee_a_la_validation(): void
    {
        // Pièce refusée : elle n'a pas reçu de numéro définitif (BR-{id} provisoire)
        $message = $this->piece('411999', '706999', 1_000, entete: ['Reference' => null])
            ->assertStatus(422)
            ->json('message');
        $this->assertMatchesRegularExpression(
            '/^Validation impossible \(BR-\d+\) : Référence de la pièce justificative manquante \(n° de facture, reçu, relevé…\)\.$/u',
            $message
        );

        // En brouillon, la référence peut attendre
        $p = $this->piece('411999', '706999', 1_000, action: 'brouillon', entete: ['Reference' => ''])->assertCreated()->json('piece');
        $this->assertStringContainsString('Référence', $p['motif_non_validable']);
    }

    // ------------------------------------------------------------------
    // Clôture mensuelle
    // ------------------------------------------------------------------

    public function test_cloture_mensuelle_dans_l_ordre_et_definitive(): void
    {
        $this->piece('411999', '706999', 1_000, '2090-01-10')->assertCreated();
        $brouillon = $this->piece('411999', '706999', 500, '2090-01-20', 'brouillon')->assertCreated()->json('piece');
        $url = $this->url($this->n, '/periodes/cloturer');

        $this->postJson($url, ['mois' => '2090-02'])->assertStatus(422);                    // dans l'ordre
        $this->postJson($url, ['mois' => '2090-01'])->assertStatus(422);                    // brouillon en janvier
        $this->deleteJson("/api/pieces/{$brouillon['id']}")->assertOk();
        $this->postJson($url, ['mois' => '2090-01'])->assertOk();
        $this->postJson($url, ['mois' => '2090-12'])->assertStatus(422);                    // dernier mois : inventaire

        $this->assertSame('2090-01-31', $this->n->fresh()->periode_cloturee_au->toDateString());
        $this->piece('411999', '706999', 1_000, '2090-01-31')->assertStatus(422);           // mois figé
        $this->piece('411999', '706999', 1_000, '2090-02-01')->assertCreated();

        $mois = collect($this->getJson($this->url($this->n, '/periodes'))->assertOk()->json('mois'))->keyBy('mois');
        $this->assertSame('cloture', $mois['2090-01']['statut']);
        $this->assertTrue($mois['2090-02']['prochain']);
        $this->assertSame('inventaire', $mois['2090-12']['statut']);

        // Les mois de N+1 attendent la clôture de N (à-nouveaux)
        $this->postJson('/api/exercices/ouvrir-suivant')->assertCreated();
        $this->postJson($this->url($this->n1, '/periodes/cloturer'), ['mois' => '2091-01'])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Contre-passation
    // ------------------------------------------------------------------

    public function test_contre_passation_d_une_piece_validee(): void
    {
        $origine = $this->piece('411999', '706999', 75_000, '2090-03-10')->assertCreated()->json('piece');
        $brouillon = $this->piece('411999', '706999', 10, '2090-03-11', 'brouillon')->assertCreated()->json('piece');
        $url = "/api/pieces/{$origine['id']}/contrepasser";

        $this->postJson("/api/pieces/{$brouillon['id']}/contrepasser", ['Date_mouvement' => '2090-03-12'])->assertStatus(422);
        $this->postJson($url, ['Date_mouvement' => '2090-03-01'])->assertStatus(422);       // antérieure à l'origine

        $ext = $this->postJson($url, ['Date_mouvement' => '2090-04-01'])->assertCreated()->json('piece');
        $this->assertSame('extourne', $ext['type_piece']);
        $this->assertSame('valide', $ext['statut']);
        $this->assertSame($origine['id'], $ext['origine']['id']);
        $this->assertSame('FAC-TEST', $ext['Reference']);
        $this->assertEquals(-75_000, $this->netDansPiece($ext['id'], '411999'));
        $this->assertEquals(75_000, $this->netDansPiece($ext['id'], '706999'));

        $this->postJson($url, ['Date_mouvement' => '2090-04-02'])->assertStatus(422);       // une seule fois
        $this->postJson("/api/pieces/{$ext['id']}/contrepasser", ['Date_mouvement' => '2090-04-02'])->assertStatus(422);
        $this->getJson("/api/pieces/{$origine['id']}")->assertJsonPath('contre_passee_par.id', $ext['id']);

        // Effet nul sur le chiffre d'affaires
        $this->assertEquals(0.0, (new EtatsFinanciers())->postesResultat('2090-01-01', '2090-12-31')['ca']
            - (new EtatsFinanciers())->postesResultat('2090-01-01', '2090-02-28')['ca']);
    }

    // ------------------------------------------------------------------
    // Extourne automatique des régularisations
    // ------------------------------------------------------------------

    public function test_les_regularisations_sont_extournees_au_premier_jour_de_n_plus_1(): void
    {
        $this->piece('512999', '101999', 500_000)->assertCreated();
        $cap = $this->piece('641999', '428999', 40_000, '2090-12-31', entete: ['a_extourner' => true])->assertCreated()->json('piece');
        $this->assertTrue($cap['a_extourner']);

        $prep = $this->getJson($this->url($this->n, '/cloture'))->json();
        $this->assertSame(1, $prep['apercu']['regularisations_a_extourner']);

        $r = $this->postJson($this->url($this->n, '/cloturer'))->assertOk()->json();
        $this->assertSame(1, $r['extournes']);
        $ext = MouvementEcriture::where('id_piece_origine', $cap['id'])->firstOrFail();
        $this->assertSame(MouvementEcriture::TYPE_EXTOURNE_AUTO, $ext->type_piece);
        $this->assertSame('2091-01-01', substr($ext->Date_mouvement, 0, 10));
        $this->assertEquals(-40_000, $this->netDansPiece($ext->Id_Mouvement_ecriture, '641999'));
        $this->assertEquals(40_000, $this->netDansPiece($ext->Id_Mouvement_ecriture, '428999'));
        // La charge de N reste en N ; l'extourne la neutralise dans N+1
        $this->assertEquals(40_000, (new EtatsFinanciers())->resultatNet('2091-01-01', '2091-12-31'));

        $this->annulerCloture()->assertOk();
        $this->assertNull(MouvementEcriture::find($ext->Id_Mouvement_ecriture));
    }

    /** Annulation de clôture : réservée à un administrateur, avec un motif. */
    private function annulerCloture()
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
        return $this->postJson($this->url($this->n, '/annuler-cloture'), ['motif' => 'Correction avant dépôt des états financiers']);
    }

    // ------------------------------------------------------------------
    // Affectation du résultat
    // ------------------------------------------------------------------

    public function test_affectation_du_resultat_dans_n_plus_1(): void
    {
        $avant120 = (new EtatsFinanciers())->soldesCumules('2090-12-31')['120000'] ?? 0.0;
        $this->exerciceClotureBeneficiaire();
        $url = $this->url($this->n, '/affectation');

        $prep = $this->getJson($url)->assertOk()->json();
        $this->assertEquals(200_000, $prep['resultat']);
        $this->assertNull($prep['motif_blocage']);
        $this->assertContains('106100', array_column($prep['destinations'], 'code'));

        $repartition = [['code' => '106100', 'montant' => 10_000], ['code' => '457000', 'montant' => 50_000], ['code' => '110001', 'montant' => 140_000]];
        $this->postJson($url, ['date' => '2091-06-30', 'reference' => 'PV AG', 'repartition' => [['code' => '110001', 'montant' => 1]]])->assertStatus(422); // total
        $this->postJson($url, ['date' => '2090-12-31', 'reference' => 'PV AG', 'repartition' => $repartition])->assertStatus(422); // dans N
        $this->postJson($url, ['date' => '2091-06-30', 'reference' => 'PV AG', 'repartition' => [['code' => '601000', 'montant' => 200_000]]])->assertStatus(422); // compte

        $r = $this->postJson($url, ['date' => '2091-06-30', 'reference' => 'PV AG du 30/06/2091', 'repartition' => $repartition, 'valider' => true])
            ->assertCreated()->json('affectation');
        $this->assertTrue($r['piece']['validee']);
        $id = $r['piece']['id'];
        $this->assertEquals(200_000, $this->netDansPiece($id, '120000'));
        $this->assertEquals(-10_000, $this->netDansPiece($id, '106100'));
        $this->assertEquals(-50_000, $this->netDansPiece($id, '457000'));
        $this->assertEquals(-140_000, $this->netDansPiece($id, '110001'));

        // Le 12 est soldé dans N+1
        $this->assertEquals(round($avant120, 2), (new EtatsFinanciers())->soldesCumules('2091-12-31')['120000'] ?? 0.0);
        $this->postJson($url, ['date' => '2091-06-30', 'reference' => 'PV AG', 'repartition' => $repartition])->assertStatus(422); // déjà affecté
        $this->annulerCloture()->assertStatus(422);
        $this->assertTrue((new EtatsFinanciers())->controles('2091-01-01', '2091-12-31')['equilibre_bilan']['ok']);
    }

    // ------------------------------------------------------------------
    // Balance et grand livre
    // ------------------------------------------------------------------

    public function test_balance_en_trois_blocs_avec_a_nouveaux(): void
    {
        $this->exerciceClotureBeneficiaire();
        $this->piece('512999', '411999', 120_000, '2091-02-10')->assertCreated();
        $this->piece('411999', '706999', 7_000, '2091-02-11', 'brouillon')->assertCreated(); // brouillard : hors balance

        $n = collect($this->getJson('/api/balance-generale?date_debut=2090-01-01&date_fin=2090-12-31')->assertOk()->json())->keyBy('code_sous_compte');
        $this->assertEquals(300_000, $n['706999']['total_credit'], 'Écriture de clôture exclue des livres');
        $this->assertEquals(-300_000, $n['706999']['solde_final']);

        $rows = $this->getJson('/api/balance-generale?date_debut=2091-01-01&date_fin=2091-12-31')->assertOk()->json();
        $b = collect($rows)->keyBy('code_sous_compte');
        $this->assertFalse($b->has('706999'), 'Pas de compte de gestion de N dans les à-nouveaux de N+1');
        $this->assertEquals(300_000, $b['411999']['ouverture']);
        $this->assertEquals(120_000, $b['411999']['total_credit']);
        $this->assertEquals(180_000, $b['411999']['solde_final']);
        $this->assertLessThan(-199_999, $b['120000']['ouverture']); // résultat 2090 en instance d'affectation
        // Équilibre de chaque bloc
        $this->assertEqualsWithDelta(0, array_sum(array_column($rows, 'ouverture')), 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($rows, 'total_debit')), array_sum(array_column($rows, 'total_credit')), 0.01);
        $this->assertEqualsWithDelta(0, array_sum(array_column($rows, 'solde_final')), 0.01);
    }

    public function test_grand_livre_avec_report_a_nouveau(): void
    {
        $this->exerciceClotureBeneficiaire();
        $this->piece('512999', '411999', 120_000, '2091-02-10')->assertCreated();

        $gl = collect($this->getJson('/api/grand-livre?date_debut=2091-01-01&date_fin=2091-12-31&code_compte=411')->assertOk()->json())
            ->where('code_sous_compte', '411999')->values();
        $this->assertSame('REPORT', $gl[0]['numero_piece']);
        $this->assertEquals(300_000, $gl[0]['Debit']);
        $this->assertSame('2091-02-10', $gl[1]['date_mouvement']);
        $this->assertEquals(180_000, $gl->last()['solde_progressif']);
        $this->assertCount(2, $gl, 'Ni les à-nouveaux ni la clôture comme lignes de mouvement');
    }
}
