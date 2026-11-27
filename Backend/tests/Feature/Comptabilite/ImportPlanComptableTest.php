<?php

namespace Tests\Feature\Comptabilite;

use App\Models\PlanCompte\Compte;
use App\Models\PlanCompte\SousCompte;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import du plan comptable et garde-fous sur les codes.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class ImportPlanComptableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        $u = User::factory()->create();
        $u->definirRole(User::ROLE_COMPTABLE)->save();
        Sanctum::actingAs($u);
    }

    private function envoyer(array $lignes, bool $simulation = true, bool $maj = false)
    {
        return $this->postJson('/api/plan-comptable/import', [
            'lignes' => $lignes, 'simulation' => $simulation, 'mettre_a_jour_libelles' => $maj,
        ]);
    }

    public function test_l_apercu_ne_modifie_rien_et_classe_chaque_ligne(): void
    {
        $existant = SousCompte::first();
        $r = $this->envoyer([
            ['code' => '9', 'libelle' => 'Classe neuve'],
            ['code' => '9 1 0 1', 'libelle' => 'Sous-compte court'],   // → 910100, parents 91 et 910 auto
            ['code' => $existant->Code_sous_compte, 'libelle' => $existant->Libelle],
            ['code' => 'ABC', 'libelle' => 'Mauvais code'],
            ['code' => '9101', 'libelle' => 'Doublon'],
            ['code' => '1234567', 'libelle' => 'Trop long'],
            ['code' => '911001', 'libelle' => ''],
        ])->assertOk();

        $this->assertSame(['total' => 7, 'creer' => 2, 'maj' => 0, 'inchange' => 1, 'erreur' => 4, 'parents' => 2], $r->json('resume'));
        $this->assertSame('910100', $r->json('lignes.1.code'));
        $this->assertSame(['91', '910'], array_column($r->json('parents'), 'code'));
        $this->assertNull(SousCompte::where('Code_sous_compte', '910100')->first());
    }

    public function test_l_import_cree_la_hierarchie_et_trace(): void
    {
        $this->envoyer([
            ['code' => '9', 'libelle' => 'Classe de test'],
            ['code' => '91', 'libelle' => 'Rubrique de test'],
            ['code' => '910001', 'libelle' => 'Sous-compte de test'],
        ], false)->assertOk()->assertJsonPath('resume.parents', 1);

        $sc = SousCompte::where('Code_sous_compte', '910001')->with('compte.rubrique.classe')->first();
        $this->assertSame('910', $sc->compte->Code_compte);
        $this->assertSame('Compte 910', $sc->compte->Libelle);          // parent créé automatiquement
        $this->assertSame('Rubrique de test', $sc->compte->rubrique->Libelle);
        $this->assertSame('9', (string) $sc->compte->rubrique->classe->Code);
        $this->assertDatabaseHas('audit_comptable', ['action' => 'plan.import']);
        $this->assertDatabaseHas('audit_comptable', ['action' => 'plan.creation', 'objet_id' => $sc->Id_Sous_compte]);
    }

    public function test_les_libelles_ne_changent_que_sur_demande(): void
    {
        $sc = SousCompte::first();
        $ligne = [['code' => $sc->Code_sous_compte, 'libelle' => 'Libellé importé']];

        $this->envoyer($ligne, false)->assertJsonPath('resume.inchange', 1);
        $this->assertNotSame('Libellé importé', $sc->fresh()->Libelle);

        $this->envoyer($ligne, false, true)->assertJsonPath('resume.maj', 1);
        $this->assertSame('Libellé importé', $sc->fresh()->Libelle);
    }

    public function test_un_lecteur_ne_peut_pas_importer(): void
    {
        $u = User::factory()->create();
        $u->definirRole(User::ROLE_LECTEUR)->save();
        Sanctum::actingAs($u);
        $this->envoyer([['code' => '9', 'libelle' => 'X']], false)->assertStatus(403);
    }

    public function test_suffixe_invalide_refuse_sans_erreur_serveur(): void
    {
        $compte = Compte::first();
        $this->postJson('/api/sous-comptes', ['suffixe' => '12345', 'Libelle' => 'Trop long', 'Id_Compte' => $compte->Id_Compte])
            ->assertStatus(422)->assertJsonValidationErrors('suffixe');
        $this->postJson('/api/sous-comptes', ['suffixe' => 'ab', 'Libelle' => 'Lettres', 'Id_Compte' => $compte->Id_Compte])
            ->assertStatus(422)->assertJsonValidationErrors('suffixe');
    }

    public function test_le_code_d_un_sous_compte_mouvemente_ne_change_pas_mais_le_libelle_oui(): void
    {
        $sc = SousCompte::find(DB::table('ligne_ecritures')->value('Id_Sous_compte'));
        $suffixeActuel = substr($sc->Code_sous_compte, 3);
        $autre = str_pad((string) (((int) $suffixeActuel + 500) % 1000), 3, '0', STR_PAD_LEFT);

        $this->putJson("/api/sous-comptes/{$sc->Id_Sous_compte}", ['suffixe' => $autre, 'Libelle' => $sc->Libelle, 'Id_Compte' => $sc->Id_Compte])
            ->assertStatus(422);
        $this->putJson("/api/sous-comptes/{$sc->Id_Sous_compte}", ['suffixe' => $suffixeActuel, 'Libelle' => 'Nouveau libellé', 'Id_Compte' => $sc->Id_Compte])
            ->assertOk();
        $this->assertSame('Nouveau libellé', $sc->fresh()->Libelle);
    }

    public function test_le_code_d_un_compte_avec_sous_comptes_ne_change_pas(): void
    {
        $compte = Compte::whereIn('Id_Compte', SousCompte::select('Id_Compte'))->first();
        $autre = (string) ((((int) substr($compte->Code_compte, -1)) + 5) % 10);

        $this->putJson("/api/comptes/{$compte->Id_Compte}", ['suffixe' => $autre, 'Libelle' => $compte->Libelle, 'Id_Rubrique' => $compte->Id_Rubrique])
            ->assertStatus(422);
    }
}
