<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use App\Services\Parametres;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tiers (clients / fournisseurs), règle « tiers obligatoire », lettrage,
 * balance âgée et à-nouveaux détaillés par tiers.
 * Base « finance_test » uniquement ; exercices fictifs 2090 / 2091.
 */
class TiersLettrageTest extends TestCase
{
    use DatabaseTransactions;

    private ExerciceComptable $n;
    private Journal $journal;
    private array $sc = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        DB::table('parametres')->whereIn('cle', ['tiers.obligatoire', 'tiers.comptes'])->delete();
        Parametres::oublier();
        Sanctum::actingAs(User::factory()->create());

        ExerciceComptable::query()->update(['Statut' => 'CLOTURE']);
        $this->n = ExerciceComptable::create(['Annee_fiscale' => 2090, 'Date_debut' => '2090-01-01', 'Date_fin' => '2090-12-31', 'Statut' => 'OUVERT']);
        ExerciceComptable::create(['Annee_fiscale' => 2091, 'Date_debut' => '2091-01-01', 'Date_fin' => '2091-12-31', 'Statut' => 'CLOTURE']);
        DB::table('immobilisations')->delete();

        $rubrique = DB::table('rubriques')->value('Id_Rubrique');
        foreach (['411998' => 'Clients test', '401998' => 'Fournisseurs test', '706998' => 'Ventes test',
                  '601998' => 'Achats test', '512998' => 'Banque test', '101998' => 'Capital test'] as $code => $libelle) {
            $idCompte = DB::table('comptes')->where('Code_compte', substr($code, 0, 3))->value('Id_Compte')
                ?? DB::table('comptes')->insertGetId(['Code_compte' => substr($code, 0, 3), 'Libelle' => $libelle, 'Id_Rubrique' => $rubrique], 'Id_Compte');
            $this->sc[$code] = SousCompte::firstOrCreate(['Code_sous_compte' => $code], ['Libelle' => $libelle, 'Id_Compte' => $idCompte])->Id_Sous_compte;
        }
        $this->journal = Journal::create([
            'Code' => 'TTI', 'Libelle' => 'Journal test tiers',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
    }

    private function tiers(string $type = 'client', string $nom = 'Client A', int $delai = 30): int
    {
        return $this->postJson('/api/tiers', ['type' => $type, 'nom' => $nom, 'delai_paiement' => $delai])
            ->assertCreated()->json('tiers.id');
    }

    /** Pièce à deux lignes ; le tiers est porté par la ligne du compte $compteTiers. */
    private function piece(string $debit, string $credit, float $montant, ?int $tiers, string $date = '2090-03-01', string $ref = 'REF', string $action = 'valider')
    {
        $ligne = fn ($code, $d, $c) => ['Id_Sous_compte' => $this->sc[$code], 'Debit' => $d, 'Credit' => $c,
            'id_tiers' => str_starts_with($code, '4') ? $tiers : null];
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => $date, 'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => "Test {$debit}/{$credit}", 'Reference' => $ref, 'action' => $action,
            'lignes' => [$ligne($debit, $montant, 0), $ligne($credit, 0, $montant)],
        ]);
    }

    private function ligne(int $pieceId, string $code): int
    {
        return LigneEcriture::where('Id_Mouvement_ecriture', $pieceId)->where('Id_Sous_compte', $this->sc[$code])->value('Id_Ligne_ecriture');
    }

    // ------------------------------------------------------------------

    public function test_fiche_tiers_code_automatique_et_suppression(): void
    {
        $r = $this->postJson('/api/tiers', ['type' => 'fournisseur', 'nom' => 'Jirama', 'nif' => '123', 'Id_Sous_compte' => $this->sc['401998']])
            ->assertCreated()->json('tiers');
        $this->assertMatchesRegularExpression('/^F\d{4}$/', $r['code']);
        $this->assertSame('401998', $r['compte']['code']);

        // Compte collectif hors classe 4 refusé ; code en double refusé
        $this->postJson('/api/tiers', ['type' => 'client', 'nom' => 'X', 'Id_Sous_compte' => $this->sc['706998']])->assertStatus(422);
        $this->postJson('/api/tiers', ['type' => 'client', 'nom' => 'Y', 'code' => $r['code']])->assertStatus(422);

        // Un tiers utilisé ne se supprime pas (on le désactive)
        $this->piece('601998', '401998', 1000, $r['id'])->assertCreated();
        $this->deleteJson("/api/tiers/{$r['id']}")->assertStatus(422);
        $this->putJson("/api/tiers/{$r['id']}", ['type' => 'fournisseur', 'nom' => 'Jirama', 'actif' => false])->assertOk();
        $this->getJson('/api/tiers?per_page=0')->assertJsonMissing(['id' => $r['id']]);
        $this->assertDatabaseHas('audit_comptable', ['action' => 'tiers.creation', 'objet_id' => $r['id']]);
    }

    public function test_tiers_obligatoire_sur_les_comptes_40_et_41(): void
    {
        $client = $this->tiers();
        // Brouillon sans tiers accepté, validation refusée
        $this->piece('411998', '706998', 500, null, action: 'brouillon')->assertCreated();
        $this->piece('411998', '706998', 500, null)->assertStatus(422)
            ->assertJsonFragment(['message' => 'Validation impossible (BR-' . (MouvementEcriture::max('Id_Mouvement_ecriture') + 1) . ') : Ligne 1 : indiquez le client ou le fournisseur du compte 411998.']);
        $this->piece('411998', '706998', 500, $client)->assertCreated();

        // Tiers sur un compte hors classe 4 : refusé
        $r = $this->postJson('/api/pieces', [
            'Date_mouvement' => '2090-03-01', 'Id_Journal' => $this->journal->Id_Journal, 'Reference' => 'R', 'action' => 'valider',
            'lignes' => [
                ['Id_Sous_compte' => $this->sc['512998'], 'Debit' => 10, 'id_tiers' => $client],
                ['Id_Sous_compte' => $this->sc['101998'], 'Credit' => 10],
            ],
        ])->assertStatus(422);
        $this->assertStringContainsString('classe 4', $r->json('message'));

        // Règle désactivable
        Parametres::enregistrer(['tiers.obligatoire' => false]);
        $this->piece('411998', '706998', 500, null)->assertCreated();
    }

    public function test_lettrage_manuel_controles_et_delettrage(): void
    {
        $a = $this->tiers('client', 'A');
        $b = $this->tiers('client', 'B');
        $fac = $this->piece('411998', '706998', 1000, $a, '2090-02-01', 'FA-1')->json('piece.id');
        $reg = $this->piece('512998', '411998', 1000, $a, '2090-03-01', 'RG-1')->json('piece.id');
        $autre = $this->piece('512998', '411998', 1000, $b, '2090-03-02', 'RG-2')->json('piece.id');
        $partiel = $this->piece('512998', '411998', 400, $a, '2090-03-03', 'RG-3')->json('piece.id');

        $lf = $this->ligne($fac, '411998');
        // Tiers différents, montants différents : refusés
        $this->postJson('/api/lettrage', ['lignes' => [$lf, $this->ligne($autre, '411998')]])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Les lignes doivent concerner le même tiers.']);
        $this->postJson('/api/lettrage', ['lignes' => [$lf, $this->ligne($partiel, '411998')]])->assertStatus(422);

        $code = $this->postJson('/api/lettrage', ['lignes' => [$lf, $this->ligne($reg, '411998')]])->assertCreated()->json('lettrage.code');
        $this->assertSame('A', $code);
        // Déjà lettrée
        $this->postJson('/api/lettrage', ['lignes' => [$lf, $this->ligne($partiel, '411998')]])->assertStatus(422);

        // Une pièce lettrée ne se contre-passe pas
        $this->postJson("/api/pieces/{$fac}/contrepasser", ['Date_mouvement' => '2090-04-01'])->assertStatus(422);

        $lignes = $this->getJson("/api/lettrage/lignes?Id_Sous_compte={$this->sc['411998']}&id_tiers={$a}")->assertOk()->json('lignes');
        $this->assertCount(1, $lignes); // seule la ligne de 400 reste à lettrer

        $id = LigneEcriture::find($lf)->id_lettrage;
        $this->deleteJson("/api/lettrage/{$id}")->assertOk();
        $this->assertNull(LigneEcriture::find($lf)->id_lettrage);
        $this->assertDatabaseHas('audit_comptable', ['action' => 'lettrage.suppression', 'objet_id' => $id]);
    }

    public function test_lettrage_automatique_par_reference_puis_par_montant(): void
    {
        $a = $this->tiers();
        // Facture 1500 réglée en deux fois sous la même référence
        $this->piece('411998', '706998', 1500, $a, '2090-01-10', 'FA-10');
        $this->piece('512998', '411998', 1000, $a, '2090-02-10', 'FA-10');
        $this->piece('512998', '411998', 500, $a, '2090-02-20', 'FA-10');
        // Facture 700 réglée sous une autre référence (même montant)
        $this->piece('411998', '706998', 700, $a, '2090-01-15', 'FA-11');
        $this->piece('512998', '411998', 700, $a, '2090-03-01', 'VIR-99');
        // Facture non réglée
        $this->piece('411998', '706998', 300, $a, '2090-01-20', 'FA-12');

        $this->postJson('/api/lettrage/auto', ['Id_Sous_compte' => $this->sc['411998']])->assertOk()->assertJsonPath('nombre', 2);
        $restantes = $this->getJson("/api/lettrage/lignes?Id_Sous_compte={$this->sc['411998']}&id_tiers={$a}")->json('lignes');
        $this->assertCount(1, $restantes);
        $this->assertEquals(300, $restantes[0]['debit']);
    }

    public function test_releve_et_balance_agee(): void
    {
        $a = $this->tiers('client', 'A', 30);
        $this->piece('411998', '706998', 1000, $a, '2090-01-01', 'FA-1');   // échue au 31/01 : 60 j de retard au 01/04
        $this->piece('411998', '706998', 2000, $a, '2090-03-20', 'FA-2');   // échéance 19/04 : non échue
        $this->piece('512998', '411998', 400, $a, '2090-03-25', 'RG-1');    // s'impute sur FA-1

        $releve = $this->getJson("/api/tiers/{$a}/releve")->assertOk()->json();
        $this->assertCount(3, $releve['lignes']);
        $this->assertEquals(2600, $releve['totaux']['solde']);

        $bal = collect($this->getJson('/api/tiers-balance-agee?type=client&date=2090-04-01')->assertOk()->json('lignes'))->firstWhere('id', $a);
        $this->assertEquals(600, $bal['j60']);
        $this->assertEquals(2000, $bal['non_echu']);
        $this->assertEquals(2600, $bal['total']);

        $this->assertEquals(2600, collect($this->getJson('/api/tiers?per_page=0')->json())->firstWhere('id', $a)['solde']);
    }

    public function test_a_nouveaux_detailles_par_tiers_puis_lettrables(): void
    {
        $a = $this->tiers('client', 'A');
        $b = $this->tiers('client', 'B');
        $this->piece('512998', '101998', 10000, null, '2090-01-01', 'CAP');
        $this->piece('411998', '706998', 1000, $a, '2090-05-01', 'FA-1');
        $this->piece('411998', '706998', 2500, $b, '2090-05-02', 'FA-2');

        $r = $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/cloturer")->assertOk()->json();
        $an = LigneEcriture::where('Id_Mouvement_ecriture', $r['piece_an']['id'])->where('Id_Sous_compte', $this->sc['411998'])->get();
        $this->assertEquals([$a => 1000.0, $b => 2500.0], $an->mapWithKeys(fn ($l) => [$l->id_tiers => (float) $l->Debit])->sortKeys()->all());

        // En 2091 : règlement de A lettré avec son à-nouveau
        $reg = $this->piece('512998', '411998', 1000, $a, '2091-02-01', 'RG-1')->assertCreated()->json('piece.id');
        $ligneAn = $an->firstWhere('id_tiers', $a)->Id_Ligne_ecriture;
        $this->postJson('/api/lettrage', ['lignes' => [$ligneAn, $this->ligne($reg, '411998')]])->assertCreated();

        // Les lignes de 2090 (exercice clôturé) ne sont plus proposées
        $lignes = collect($this->getJson("/api/lettrage/lignes?Id_Sous_compte={$this->sc['411998']}")->json('lignes'));
        $this->assertTrue($lignes->every(fn ($l) => $l['date'] >= '2091-01-01'));

        // Annuler la clôture défait le lettrage de l'à-nouveau
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
        $this->postJson("/api/exercices/{$this->n->Id_Exercice_comptable}/annuler-cloture", ['motif' => 'Écriture de régularisation oubliée avant la clôture'])->assertOk();
        $this->assertNull(LigneEcriture::find($this->ligne($reg, '411998'))->id_lettrage);
    }

    public function test_un_lecteur_ne_peut_ni_creer_ni_lettrer(): void
    {
        $u = User::factory()->create();
        $u->definirRole(User::ROLE_LECTEUR)->save();
        Sanctum::actingAs($u);
        $this->postJson('/api/tiers', ['type' => 'client', 'nom' => 'X'])->assertForbidden();
        $this->postJson('/api/lettrage', ['lignes' => [1, 2]])->assertForbidden();
        $this->getJson('/api/tiers')->assertOk();
    }

    public function test_import_avec_code_tiers(): void
    {
        $client = $this->tiers();
        $code = DB::table('tiers')->where('id', $client)->value('code');
        $l = fn ($compte, $d, $c, $tiers = '') => ['date' => '10/03/2090', 'compte' => $compte, 'libelle' => 'Vente', 'debit' => $d,
            'credit' => $c, 'reference' => 'IMP-T', 'journal' => 'TTI', 'tiers' => $tiers];

        // Analyse : tiers inconnu, tiers sur un compte hors classe 4, tiers manquant (avertissement)
        $r = $this->postJson('/api/ecritures/import/analyse', ['lignes' => [
            $l('411998', '100', '', 'ZZ999'), $l('706998', '', '100', $code), $l('411998', '50', ''), $l('706998', '', '50'),
        ]])->assertOk();
        $this->assertStringContainsString('introuvable', $r->json('lignes.0.erreurs.0'));
        $this->assertStringContainsString('classe 4', $r->json('lignes.1.erreurs.0'));
        $this->assertSame([], $r->json('lignes.2.erreurs'));
        $this->assertStringContainsString('Tiers non renseigné', $r->json('lignes.2.avertissements.0'));

        // Import : le tiers est porté par la ligne et la pièce est validable
        $r = $this->postJson('/api/ecritures/import/lignes', ['lignes' => [$l('411998', '100', '', strtolower($code)), $l('706998', '', '100')], 'fichier' => 't.csv'])
            ->assertOk()->assertJsonPath('historique.statut', 'réussi');
        $piece = MouvementEcriture::where('id_import', $r->json('historique.id'))->firstOrFail();
        $this->assertSame($client, (int) LigneEcriture::where('Id_Mouvement_ecriture', $piece->Id_Mouvement_ecriture)->where('Id_Sous_compte', $this->sc['411998'])->value('id_tiers'));
    }
}