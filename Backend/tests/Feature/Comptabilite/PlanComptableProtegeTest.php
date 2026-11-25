<?php

namespace Tests\Feature\Comptabilite;

use App\Models\PlanCompte\Classe;
use App\Models\PlanCompte\Compte;
use App\Models\PlanCompte\Rubrique;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan comptable protégé : un élément utilisé ne peut pas être supprimé
 * (422 avec un message clair, au lieu d'une erreur 500 ou d'une cascade).
 *
 *   docker compose exec -T -e DB_DATABASE=finance_test backend php artisan test tests/Feature/Comptabilite
 */
class PlanComptableProtegeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Sanctum::actingAs(User::factory()->create());
    }

    /** Branche neuve classe → rubrique → compte → sous-compte, sans écriture. */
    private function branche(): array
    {
        $classe = Classe::create(['Code' => '9', 'Libelle' => 'Classe de test']);
        $rubrique = Rubrique::create(['Code_rubrique' => '99', 'Libelle' => 'Rubrique de test', 'Id_Classe' => $classe->Id_Classe]);
        $compte = Compte::create(['Code_compte' => '999', 'Libelle' => 'Compte de test', 'Id_Rubrique' => $rubrique->Id_Rubrique]);
        $sousCompte = SousCompte::create(['Code_sous_compte' => '999001', 'Libelle' => 'Sous-compte de test', 'Id_Compte' => $compte->Id_Compte]);

        return [$classe, $rubrique, $compte, $sousCompte];
    }

    public function test_un_sous_compte_mouvemente_ne_peut_pas_etre_supprime(): void
    {
        $id = DB::table('ligne_ecritures')->value('Id_Sous_compte');
        $code = SousCompte::find($id)->Code_sous_compte;

        $this->deleteJson("/api/sous-comptes/{$id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Le sous-compte {$code} est mouvementé : il ne peut pas être supprimé."]);
        $this->assertNotNull(SousCompte::find($id));
    }

    public function test_un_journal_contenant_des_ecritures_ne_peut_pas_etre_supprime(): void
    {
        $journal = Journal::find(DB::table('mouvement_ecritures')->value('Id_Journal'));

        $this->deleteJson("/api/journals/{$journal->Id_Journal}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Le journal {$journal->Code} contient des écritures : il ne peut pas être supprimé."]);
    }

    public function test_pas_de_suppression_en_cascade_dans_le_plan_comptable(): void
    {
        [$classe, $rubrique, $compte, $sousCompte] = $this->branche();

        $this->deleteJson("/api/classes/{$classe->Id_Classe}")->assertStatus(422);
        $this->deleteJson("/api/rubriques/{$rubrique->Id_Rubrique}")->assertStatus(422);
        $this->deleteJson("/api/comptes/{$compte->Id_Compte}")->assertStatus(422);

        $this->assertNotNull(SousCompte::find($sousCompte->Id_Sous_compte));
    }

    public function test_un_element_inutilise_se_supprime_de_bas_en_haut(): void
    {
        [$classe, $rubrique, $compte, $sousCompte] = $this->branche();

        $this->deleteJson("/api/sous-comptes/{$sousCompte->Id_Sous_compte}")->assertOk();
        $this->deleteJson("/api/comptes/{$compte->Id_Compte}")->assertOk();
        $this->deleteJson("/api/rubriques/{$rubrique->Id_Rubrique}")->assertOk();
        $this->deleteJson("/api/classes/{$classe->Id_Classe}")->assertOk();

        $this->assertNull(Classe::find($classe->Id_Classe));
    }
}
