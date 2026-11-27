<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use App\Services\Comptabilite\ComptesSysteme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Immobilisations et amortissements : comptes non amortissables, années
 * bissextiles, dotations déjà comptabilisées, verrouillage, sorties d'actif,
 * rapprochement registre / comptabilité, taux paramétrables.
 */
class AmortissementsTest extends TestCase
{
    use DatabaseTransactions;

    private ExerciceComptable $n;     // 2092 (bissextile)
    private ExerciceComptable $n1;    // 2093
    private Journal $journal;
    private array $sc = [];
    private int $taux20;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Sanctum::actingAs(User::factory()->create());

        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        $this->n = ExerciceComptable::create(['Annee_fiscale' => 2092, 'Date_debut' => '2092-01-01', 'Date_fin' => '2092-12-31', 'Statut' => 'OUVERT']);
        $this->n1 = ExerciceComptable::create(['Annee_fiscale' => 2093, 'Date_debut' => '2093-01-01', 'Date_fin' => '2093-12-31', 'Statut' => 'OUVERT']);
        DB::table('immobilisations')->delete();

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['211999' => 'Terrain test', '218999' => 'Matériel test', '281899' => 'Amort. matériel test',
                  '512999' => 'Banque test', '101999' => 'Capital test'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TAM', 'Libelle' => 'Journal test amortissements',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
        $this->taux20 = $this->postJson('/api/taux-amortissement', ['intitule' => 'Matériel test 20 %', 'taux' => 20])
            ->assertCreated()->json('id');
    }

    private function piece(array $lignes, string $date, string $action = 'valider')
    {
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => $date, 'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => 'Test amortissements', 'Reference' => 'TEST-AM', 'action' => $action,
            'lignes' => collect($lignes)->map(fn ($l) => ['Id_Sous_compte' => $this->sc[$l[0]], 'Debit' => $l[1], 'Credit' => $l[2]])->all(),
        ]);
    }

    private function materiel(float $valeur = 1_000_000, string $date = '2092-01-01')
    {
        return $this->postJson('/api/amortissement', [
            'libelle' => 'Serveur', 'Id_Sous_compte' => $this->sc['218999'], 'taux_amortissement_id' => $this->taux20,
            'valeur_brute' => $valeur, 'date_acquisition' => $date,
        ])->assertCreated()->json('id');
    }

    private function plan(ExerciceComptable $e): array
    {
        return $this->getJson("/api/exercices/{$e->Id_Exercice_comptable}/dotations")->assertOk()->json();
    }

    public function test_terrain_non_amortissable_et_saisies_invalides(): void
    {
        $this->postJson('/api/amortissement', [
            'libelle' => 'Terrain', 'Id_Sous_compte' => $this->sc['211999'], 'taux_amortissement_id' => $this->taux20,
            'valeur_brute' => 5_000_000, 'date_acquisition' => '2092-01-01',
        ])->assertCreated();
        $this->assertEquals(0, $this->plan($this->n)['total']);

        $base = ['libelle' => 'X', 'Id_Sous_compte' => $this->sc['218999'], 'taux_amortissement_id' => $this->taux20, 'date_acquisition' => '2092-03-01'];
        $this->postJson('/api/amortissement', $base + ['valeur_brute' => -10])->assertStatus(422);
        $this->postJson('/api/amortissement', $base + ['valeur_brute' => 10, 'date_debut_utilisation' => '2092-01-01'])->assertStatus(422);
        $this->postJson('/api/amortissement', ['taux_amortissement_id' => null, 'valeur_brute' => 10] + $base)->assertStatus(422);
        $this->postJson('/api/amortissement', ['Id_Sous_compte' => $this->sc['512999'], 'valeur_brute' => 10] + $base)->assertStatus(422);
    }

    public function test_annee_bissextile_sans_ecart_de_cumul(): void
    {
        $this->materiel(1_000_000, '2092-07-01');
        // 2092 bissextile : 200 000 × 184 / 366
        $this->assertEquals(100_546.45, $this->plan($this->n)['total']);
        $p2 = $this->plan($this->n1);
        $this->assertEquals(100_546.45, $p2['immobilisations'][0]['cumul_anterieur']);
        $this->assertEquals(200_000, $p2['total']);
    }

    public function test_dotations_importees_reconnues_puis_complement(): void
    {
        $this->materiel();
        $this->assertEquals(200_000, $this->plan($this->n)['reste_a_passer']);

        // Dotation saisie à la main (comme un import) : 150 000 sur 200 000
        $this->sc['681000'] = ComptesSysteme::dotationsAmortissements()->Id_Sous_compte;
        $this->piece([['681000', 150_000, 0], ['281899', 0, 150_000]], '2092-12-31')->assertCreated();

        $plan = $this->plan($this->n);
        $this->assertEquals(150_000, $plan['deja_comptabilise']);
        $this->assertEquals(50_000, $plan['reste_a_passer']);

        // Le module ne passe que le complément
        $g = $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/dotations", ['valider' => true])->assertCreated()->json();
        $this->assertEquals(0, $g['plan']['reste_a_passer']);
        $this->assertEquals(50_000, DB::table('ligne_ecritures')->where('Id_Mouvement_ecriture', $g['piece']['id'])->sum('Debit'));
        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/dotations")->assertStatus(422);

        $prep = $this->getJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloture")->json();
        $this->assertTrue(collect($prep['controles'])->firstWhere('cle', 'dotations')['ok']);
    }

    public function test_fiche_verrouillee_apres_dotations_validees(): void
    {
        $id = $this->materiel();
        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/dotations", ['valider' => true])->assertCreated();

        $fiche = ['libelle' => 'Serveur principal', 'Id_Sous_compte' => $this->sc['218999'], 'taux_amortissement_id' => $this->taux20,
                  'valeur_brute' => 1_000_000, 'date_acquisition' => '2092-01-01', 'date_debut_utilisation' => '2092-01-01'];
        $this->putJson("/api/amortissement/{$id}", $fiche)->assertOk();                                  // libellé seul : permis
        $this->putJson("/api/amortissement/{$id}", ['valeur_brute' => 2_000_000] + $fiche)->assertStatus(422);
        $this->deleteJson("/api/amortissement/{$id}")->assertStatus(422);
        $this->deleteJson("/api/taux-amortissement/{$this->taux20}")->assertStatus(422);              // taux utilisé
    }

    public function test_cession_avec_plus_value_et_mise_au_rebut(): void
    {
        $id = $this->materiel();   // 1 000 000, 20 %, mise en service 01/01/2092
        // Cession au 30/06/2093 pour 700 000 : cumul 200 000 + 200 000 × 181/365 = 299 178,08 ; VNC 700 821,92 → moins-value 821,92
        $r = $this->postJson("/api/amortissement/{$id}/sortie", [
            'date_sortie' => '2093-06-30', 'prix_cession' => 700_000, 'Id_Sous_compte_contrepartie' => $this->sc['512999'],
        ])->assertCreated()->json();

        $lignes = DB::table('ligne_ecritures as l')->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.Id_Mouvement_ecriture', $r['piece']['id'])->selectRaw('s."Code_sous_compte" as code, l."Debit" - l."Credit" as net')
            ->pluck('net', 'code')->map(fn ($v) => (float) $v);
        $this->assertEquals(299_178.08, $lignes['281899']);
        $this->assertEquals(-1_000_000, $lignes['218999']);
        $this->assertEquals(700_000, $lignes['512999']);
        $this->assertEquals(821.92, $lignes['652000']);
        $this->assertSame('cession', MouvementEcriture::find($r['piece']['id'])->type_piece);

        // La dotation 2093 s'arrête à la sortie
        $this->assertEquals(99_178.08, $this->plan($this->n1)['total']);

        // Annulation possible tant que la pièce est en brouillon
        $this->deleteJson("/api/amortissement/{$id}/sortie")->assertOk();
        $this->assertNull(MouvementEcriture::find($r['piece']['id']));
        $this->assertEquals(200_000, $this->plan($this->n1)['total']);

        // Mise au rebut (sans prix) : toute la VNC en 652
        $r2 = $this->postJson("/api/amortissement/{$id}/sortie", ['date_sortie' => '2093-12-31'])->assertCreated()->json();
        $vnc = (float) DB::table('ligne_ecritures as l')->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.Id_Mouvement_ecriture', $r2['piece']['id'])->where('s.Code_sous_compte', '652000')->value('l.Debit');
        $this->assertEquals(600_000, $vnc);
    }

    public function test_rapprochement_registre_comptabilite(): void
    {
        $this->materiel(1_000_000);
        $ecarts = $this->plan($this->n)['rapprochement'];
        $this->assertSame('218999', collect($ecarts)->firstWhere('compte', '218999')['compte']);   // fiche sans écriture

        $this->piece([['218999', 1_000_000, 0], ['512999', 0, 1_000_000]], '2092-01-01')->assertCreated();
        $this->assertNull(collect($this->plan($this->n)['rapprochement'])->firstWhere('compte', '218999'));

        $prep = $this->getJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloture")->json();
        $this->assertTrue(collect($prep['controles'])->firstWhere('cle', 'registre')['ok']);
    }
}
