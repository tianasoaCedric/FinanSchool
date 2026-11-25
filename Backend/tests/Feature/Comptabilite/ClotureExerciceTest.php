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
 * Lot 4 — clôture d'exercice (6/7 → 12), à-nouveaux N+1, verrouillage,
 * dotations aux amortissements.
 * Exercices fictifs 2090 / 2091 pour ne pas dépendre des données réelles
 * (la base finance_test en contient : on raisonne en variations).
 */
class ClotureExerciceTest extends TestCase
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
        Sanctum::actingAs(User::factory()->create());

        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        $this->n = ExerciceComptable::create(['Annee_fiscale' => 2090, 'Date_debut' => '2090-01-01', 'Date_fin' => '2090-12-31', 'Statut' => 'OUVERT']);
        $this->n1 = ExerciceComptable::create(['Annee_fiscale' => 2091, 'Date_debut' => '2091-01-01', 'Date_fin' => '2091-12-31', 'Statut' => 'CLOTURE']);
        // Les immobilisations réelles déclencheraient l'avertissement « dotations »
        DB::table('immobilisations')->delete();

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['101999' => 'Capital test', '218999' => 'Matériel test', '281899' => 'Amort. matériel test',
                  '681999' => 'Dotation test', '411999' => 'Client test', '706999' => 'Écolages test',
                  '512999' => 'Banque test', '641999' => 'Salaires test'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TCL', 'Libelle' => 'Journal test clôture',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
    }

    private function piece(string $debit, string $credit, float $montant, string $date = '2090-06-30', string $action = 'valider')
    {
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => $date,
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => "Test {$debit}/{$credit}",
            'Reference' => 'FAC-TEST',
            'action' => $action,
            'lignes' => [
                ['Id_Sous_compte' => $this->sc[$debit], 'Debit' => $montant, 'Credit' => 0],
                ['Id_Sous_compte' => $this->sc[$credit], 'Debit' => 0, 'Credit' => $montant],
            ],
        ]);
    }

    /** Capital 1 000 000, écolages 300 000 (à crédit), dotation 100 000 : bénéfice 200 000. */
    private function scenarioBenefice(): void
    {
        $this->piece('512999', '101999', 1_000_000)->assertCreated();
        $this->piece('411999', '706999', 300_000)->assertCreated();
        $this->piece('681999', '281899', 100_000)->assertCreated();
    }

    private function cloturer(array $options = [])
    {
        return $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloturer", $options);
    }

    /** Net (débit − crédit) d'un compte dans une pièce. */
    private function netDansPiece(int $idPiece, string $code): float
    {
        return round((float) DB::table('ligne_ecritures as l')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.Id_Mouvement_ecriture', $idPiece)->where('s.Code_sous_compte', $code)
            ->sum(DB::raw('l."Debit" - l."Credit"')), 2);
    }

    private function soldeCumule(string $code, string $date): float
    {
        return (new EtatsFinanciers())->soldesCumules($date)[$code] ?? 0.0;
    }

    // ------------------------------------------------------------------

    public function test_cloture_solde_les_comptes_de_gestion_vers_le_12(): void
    {
        $this->scenarioBenefice();
        $prep = $this->getJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloture")->assertOk()->json();
        $this->assertTrue($prep['peut_cloturer']);
        $this->assertEquals(200_000, $prep['resultat']);
        $this->assertStringStartsWith('120000', $prep['apercu']['compte_resultat']);

        $r = $this->cloturer()->assertOk()->json();
        $this->assertEquals(200_000, $r['resultat']);

        $piece = MouvementEcriture::with('lignes')->findOrFail($r['piece_cloture']['id']);
        $this->assertSame(MouvementEcriture::TYPE_CLOTURE, $piece->type_piece);
        $this->assertSame('2090-12-31', substr($piece->Date_mouvement, 0, 10));
        $this->assertTrue($piece->lignes->every(fn ($l) => $l->statut === 'valide'));
        $this->assertEquals(round($piece->lignes->sum('Debit'), 2), round($piece->lignes->sum('Credit'), 2));
        $this->assertEquals(300_000, $this->netDansPiece($piece->Id_Mouvement_ecriture, '706999'));   // produit soldé au débit
        $this->assertEquals(-100_000, $this->netDansPiece($piece->Id_Mouvement_ecriture, '681999'));  // charge soldée au crédit
        $this->assertEquals(-200_000, $this->netDansPiece($piece->Id_Mouvement_ecriture, '120000'));  // bénéfice au crédit du 120
    }

    public function test_les_a_nouveaux_reprennent_les_comptes_de_bilan_dont_le_12(): void
    {
        $avant12 = $this->soldeCumule('120000', '2090-12-31');
        $this->scenarioBenefice();
        $r = $this->cloturer()->assertOk()->json();

        $an = MouvementEcriture::with('lignes')->findOrFail($r['piece_an']['id']);
        $this->assertSame(MouvementEcriture::TYPE_A_NOUVEAU, $an->type_piece);
        $this->assertSame('2091-01-01', substr($an->Date_mouvement, 0, 10));
        $this->assertStringStartsWith('AN2091-', $an->Numero_piece);
        $this->assertEquals(round($an->lignes->sum('Debit'), 2), round($an->lignes->sum('Credit'), 2));

        $id = $an->Id_Mouvement_ecriture;
        $this->assertEquals($this->soldeCumule('512999', '2090-12-31'), $this->netDansPiece($id, '512999'));
        $this->assertEquals($this->soldeCumule('101999', '2090-12-31'), $this->netDansPiece($id, '101999'));
        $this->assertEquals($this->soldeCumule('281899', '2090-12-31'), $this->netDansPiece($id, '281899'));
        $this->assertEquals(round($avant12 - 200_000, 2), $this->netDansPiece($id, '120000'));
        // Aucun compte de gestion dans les à-nouveaux
        $this->assertEquals(0, $this->netDansPiece($id, '706999'));
        $this->assertEquals(0, $this->netDansPiece($id, '681999'));
    }

    public function test_verrouillage_de_n_et_ouverture_de_n_plus_1(): void
    {
        $this->scenarioBenefice();
        $this->cloturer()->assertOk();

        $n = $this->n->fresh();
        $this->assertSame('CLOTURE', $n->Statut);
        $this->assertTrue($n->isClotureDefinitive());
        $this->assertEquals(200_000, $n->resultat_cloture);
        $n1 = $this->n1->fresh();
        $this->assertSame('OUVERT', $n1->Statut);
        $this->assertTrue($n1->ouvert_par_a_nouveaux);

        // Plus aucune saisie dans N ; la saisie dans N+1 fonctionne
        $this->piece('411999', '706999', 1_000)->assertStatus(422);
        $this->piece('411999', '706999', 1_000, '2091-02-01')->assertCreated();
        // Ni réouverture directe, ni seconde clôture
        $this->postJson("/api/exercices/{$n->Id_Exercice_comptable}/ouvrir")->assertStatus(422);
        $this->cloturer()->assertStatus(422);
    }

    public function test_les_etats_de_n_sont_inchanges_et_n_plus_1_part_des_a_nouveaux(): void
    {
        $this->scenarioBenefice();
        $avant = new EtatsFinanciers();
        $crAvant = $avant->postesResultat('2090-01-01', '2090-12-31');
        $bilanAvant = $avant->bilan('2090-01-01', '2090-12-31');

        $this->cloturer()->assertOk();

        $e = new EtatsFinanciers();
        $this->assertEquals($crAvant, $e->postesResultat('2090-01-01', '2090-12-31'), 'Compte de résultat de N inchangé');
        $this->assertEquals($bilanAvant, $e->bilan('2090-01-01', '2090-12-31'), 'Bilan de N inchangé');

        // N+1, avant toute écriture : mêmes actifs / passifs, résultat N en « non affecté »
        $b1 = $e->bilan('2091-01-01', '2091-12-31');
        $this->assertEquals($bilanAvant['actif'], $b1['actif']);
        $this->assertEquals(0.0, $b1['passif']['resultat']);
        $this->assertEquals(round($bilanAvant['passif']['report'] + $bilanAvant['passif']['resultat'], 2), $b1['passif']['report']);

        $this->piece('411999', '706999', 50_000, '2091-03-01')->assertCreated();
        $c = (new EtatsFinanciers())->controles('2091-01-01', '2091-12-31');
        $this->assertTrue($c['equilibre_bilan']['ok'], 'Actif = Passif en N+1');
        $this->assertTrue($c['tresorerie']['ok'], 'Flux de N+1 rapprochés');
        $this->assertTrue($c['capitaux_propres']['ok'], 'Variation des capitaux propres cohérente');
        $this->assertEquals(50_000, (new EtatsFinanciers())->resultatNet('2091-01-01', '2091-12-31'));
    }

    public function test_une_perte_est_portee_au_129(): void
    {
        $this->piece('512999', '101999', 500_000)->assertCreated();
        $this->piece('641999', '512999', 80_000)->assertCreated();
        $r = $this->cloturer()->assertOk()->json();

        $this->assertEquals(-80_000, $r['resultat']);
        $this->assertEquals(80_000, $this->netDansPiece($r['piece_cloture']['id'], '129000'));
        $this->assertEquals(-80_000, $this->netDansPiece($r['piece_cloture']['id'], '641999'));
    }

    public function test_les_vues_de_reporting_ignorent_les_pieces_de_cloture_et_d_a_nouveaux(): void
    {
        $this->scenarioBenefice();
        $vue = fn (string $code, string $d, string $f) => round((float) DB::table('vue_balance_generale')
            ->where('code_sous_compte', $code)->whereBetween('date_mouvement', [$d, $f])->sum('solde_final'), 2);
        $avant706 = $vue('706999', '2090-01-01', '2090-12-31');
        $avant512 = $vue('512999', '1900-01-01', '2091-12-31');

        $this->cloturer()->assertOk();

        $this->assertEquals($avant706, $vue('706999', '2090-01-01', '2090-12-31'), 'CA de N non annulé par la clôture');
        $this->assertEquals($avant512, $vue('512999', '1900-01-01', '2091-12-31'), 'Trésorerie non doublée par les à-nouveaux');
    }

    public function test_controles_bloquants(): void
    {
        $this->scenarioBenefice();
        $this->piece('411999', '706999', 10_000, '2090-07-01', 'brouillon')->assertCreated();

        $prep = $this->getJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloture")->json();
        $this->assertFalse($prep['peut_cloturer']);
        $this->assertFalse(collect($prep['controles'])->firstWhere('cle', 'brouillons')['ok']);

        $this->cloturer()->assertStatus(422);
        $this->assertSame('OUVERT', $this->n->fresh()->Statut);
        $this->assertSame(0, MouvementEcriture::where('type_piece', '!=', 'normale')->whereBetween('Date_mouvement', ['2090-01-01', '2091-12-31'])->count());
    }

    public function test_annuler_la_cloture(): void
    {
        $this->scenarioBenefice();
        $r = $this->cloturer()->assertOk()->json();

        // Les écritures déjà saisies dans N+1 sont conservées
        $p = $this->piece('411999', '706999', 1_000, '2091-02-01')->assertCreated()->json();

        $this->connecterAdmin();
        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/annuler-cloture", ['motif' => self::MOTIF])->assertOk();
        $this->assertNull(MouvementEcriture::find($r['piece_cloture']['id']));
        $this->assertNull(MouvementEcriture::find($r['piece_an']['id']));
        $this->assertNotNull(MouvementEcriture::find($p['piece']['id']));
        $this->assertSame('OUVERT', $this->n->fresh()->Statut);
        $this->assertFalse($this->n->fresh()->isClotureDefinitive());
        $this->assertSame('OUVERT', $this->n1->fresh()->Statut, 'N et N+1 restent ouverts ensemble');
        $this->assertFalse($this->n1->fresh()->ouvert_par_a_nouveaux);

        // On peut clôturer de nouveau ; une fois un mois de N+1 clôturé, l'annulation est refusée
        $this->cloturer()->assertOk();
        $this->postJson("/api/exercices/{$this->n1->Id_Exercice_comptable}/periodes/cloturer", ['mois' => '2091-01'])->assertOk();
        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/annuler-cloture", ['motif' => self::MOTIF])->assertStatus(422);
    }

    private const MOTIF = 'Écriture de régularisation oubliée avant la clôture';

    private function connecterAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
        return $admin;
    }

    public function test_seul_un_administrateur_peut_annuler_une_cloture(): void
    {
        $this->scenarioBenefice();
        $this->cloturer()->assertOk();
        $url = "/api/exercices/{$this->n->Id_Exercice_comptable}/annuler-cloture";

        // Utilisateur ordinaire : refusé, la clôture reste en place
        $this->postJson($url, ['motif' => self::MOTIF])
            ->assertForbidden()
            ->assertJsonFragment(['message' => "Seul un administrateur peut annuler la clôture d'un exercice."]);
        $this->assertTrue($this->n->fresh()->isClotureDefinitive());

        // Administrateur sans motif valable : refusé
        $this->connecterAdmin();
        $this->postJson($url)->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->postJson($url, ['motif' => 'erreur'])->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->assertTrue($this->n->fresh()->isClotureDefinitive());
    }

    public function test_l_annulation_est_tracee_avec_son_motif_et_son_auteur(): void
    {
        $this->scenarioBenefice();
        $r = $this->cloturer()->assertOk()->json();
        $admin = $this->connecterAdmin();

        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/annuler-cloture", ['motif' => self::MOTIF])->assertOk();

        $trace = \Illuminate\Support\Facades\DB::table('audit_comptable')
            ->where('action', 'exercice.annulation_cloture')
            ->where('objet_id', $this->n->Id_Exercice_comptable)
            ->latest('id')->first();
        $this->assertNotNull($trace);
        $this->assertEquals($admin->id, $trace->user_id);
        $details = json_decode($trace->details, true);
        $this->assertSame(self::MOTIF, $details['motif']);
        $this->assertContains($r['piece_cloture']['numero'], $details['pieces_supprimees']);
    }

    public function test_dotations_aux_amortissements(): void
    {
        $taux = DB::table('taux_amortissement')->insertGetId([
            'intitule' => 'Matériel test', 'taux' => 20, 'duree' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('immobilisations')->insert([
            'libelle' => 'Vidéoprojecteur', 'Id_Sous_compte' => $this->sc['218999'], 'taux_amortissement_id' => $taux,
            'valeur_brute' => 1_000_000, 'date_acquisition' => '2090-07-01', 'date_debut_utilisation' => '2090-07-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = "/api/exercices/{$this->n->Id_Exercice_comptable}";

        // Prorata au jour : 200 000 × 184 / 365
        $plan = $this->getJson("{$url}/dotations")->assertOk()->json();
        $this->assertEquals(100_821.92, $plan['total']);
        $this->assertNull($plan['piece']);

        // Sans dotations passées : avertissement, clôture refusée sauf si l'on passe outre
        $prep = $this->getJson("{$url}/cloture")->json();
        $this->assertFalse(collect($prep['controles'])->firstWhere('cle', 'dotations')['ok']);
        $this->cloturer()->assertStatus(422);

        // Brouillon puis régénération (pas de doublon), puis validation
        $this->postJson("{$url}/dotations")->assertCreated();
        $g = $this->postJson("{$url}/dotations", ['valider' => true])->assertCreated()->json();
        $this->assertSame(1, MouvementEcriture::where('type_piece', 'dotation')->whereBetween('Date_mouvement', ['2090-01-01', '2090-12-31'])->count());
        $this->assertTrue($g['plan']['piece']['validee']);
        $this->assertEquals(100_821.92, $this->netDansPiece($g['piece']['id'], '681000'));
        $this->assertEquals(-100_821.92, $this->netDansPiece($g['piece']['id'], '281899'));
        $this->postJson("{$url}/dotations")->assertStatus(422); // déjà validées

        $this->cloturer()->assertOk();

        // Exercice suivant : annuité pleine, cumul antérieur repris
        $plan2 = $this->getJson("/api/exercices/{$this->n1->Id_Exercice_comptable}/dotations")->json();
        $this->assertEquals(200_000, $plan2['total']);
        $this->assertEquals(100_821.92, $plan2['immobilisations'][0]['cumul_anterieur']);
    }
}
