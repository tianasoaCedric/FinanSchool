<?php

namespace Tests\Feature\Comptabilite;

use App\Models\User;
use App\Services\Parametres;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rôles (admin / comptable / lecteur), comptes désactivés, profil,
 * gestion des utilisateurs et paramètres de l'application.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class RolesEtParametresTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Parametres::oublier();
    }

    protected function tearDown(): void
    {
        Parametres::oublier();
        parent::tearDown();
    }

    private function utilisateur(string $role, bool $actif = true): User
    {
        $u = User::factory()->create(['password' => Hash::make('motdepasse-1')]);
        $u->definirRole($role)->forceFill(['actif' => $actif])->save();
        return $u->fresh();
    }

    public function test_un_lecteur_consulte_mais_ne_modifie_rien(): void
    {
        Sanctum::actingAs($this->utilisateur(User::ROLE_LECTEUR));

        $this->getJson('/api/mode-paiements')->assertOk();
        $this->postJson('/api/mode-paiements', ['Libelle' => 'Test', 'Abr' => 'TST'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Votre rôle (lecteur) ne permet que la consultation.');
        $this->postJson('/api/pieces', [])->assertStatus(403);

        // … mais il gère son propre profil
        $this->putJson('/api/profil', ['name' => 'Lecteur renommé', 'email' => 'lecteur-test@example.mg'])->assertOk();
    }

    public function test_un_comptable_modifie_mais_pas_les_parametres_ni_les_utilisateurs(): void
    {
        Sanctum::actingAs($this->utilisateur(User::ROLE_COMPTABLE));

        $this->postJson('/api/mode-paiements', ['Libelle' => 'Chèque test', 'Abr' => 'CHT'])->assertSuccessful();
        $this->putJson('/api/reglages', ['valeurs' => ['societe.nom' => 'X']])->assertStatus(403);
        $this->getJson('/api/utilisateurs')->assertStatus(403);
    }

    public function test_un_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $u = $this->utilisateur(User::ROLE_COMPTABLE, false);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'motdepasse-1'])->assertStatus(403);

        Sanctum::actingAs($u);
        $this->getJson('/api/profil')->assertStatus(401);
    }

    public function test_la_connexion_enregistre_la_date(): void
    {
        $u = $this->utilisateur(User::ROLE_COMPTABLE);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'motdepasse-1'])->assertOk();
        $this->assertNotNull($u->fresh()->derniere_connexion);
    }

    public function test_l_admin_cree_et_modifie_un_utilisateur_avec_audit(): void
    {
        Sanctum::actingAs($this->utilisateur(User::ROLE_ADMIN));

        $id = $this->postJson('/api/utilisateurs', [
            'name' => 'Nouvelle Comptable', 'email' => 'nouvelle@example.mg', 'role' => 'comptable', 'password' => 'initial-123',
        ])->assertCreated()->json('utilisateur.id');

        $this->putJson("/api/utilisateurs/{$id}", [
            'name' => 'Nouvelle Comptable', 'email' => 'nouvelle@example.mg', 'role' => 'lecteur', 'actif' => true,
        ])->assertOk()->assertJsonPath('utilisateur.role', 'lecteur');

        $this->assertDatabaseHas('audit_comptable', ['action' => 'utilisateur.droits', 'objet_id' => $id]);
        $this->assertFalse((bool) User::find($id)->is_admin);
    }

    public function test_un_admin_ne_peut_ni_se_retrograder_ni_se_desactiver(): void
    {
        $admin = $this->utilisateur(User::ROLE_ADMIN);
        Sanctum::actingAs($admin);
        $base = ['name' => $admin->name, 'email' => $admin->email];

        $this->putJson("/api/utilisateurs/{$admin->id}", $base + ['role' => 'comptable', 'actif' => true])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->putJson("/api/utilisateurs/{$admin->id}", $base + ['role' => 'admin', 'actif' => false])
            ->assertStatus(422)->assertJsonValidationErrors('actif');
    }

    public function test_changer_son_mot_de_passe_exige_l_actuel(): void
    {
        $u = $this->utilisateur(User::ROLE_COMPTABLE);
        Sanctum::actingAs($u);

        $this->putJson('/api/profil/mot-de-passe', ['actuel' => 'faux', 'nouveau' => 'nouveau-mdp-9', 'nouveau_confirmation' => 'nouveau-mdp-9'])
            ->assertStatus(422)->assertJsonValidationErrors('actuel');
        $this->putJson('/api/profil/mot-de-passe', ['actuel' => 'motdepasse-1', 'nouveau' => 'nouveau-mdp-9', 'nouveau_confirmation' => 'nouveau-mdp-9'])
            ->assertOk();
        $this->assertTrue(Hash::check('nouveau-mdp-9', $u->fresh()->password));
    }

    public function test_inscription_fermee_et_role_par_defaut(): void
    {
        Sanctum::actingAs($this->utilisateur(User::ROLE_ADMIN));
        $this->putJson('/api/reglages', ['valeurs' => ['securite.role_inscription' => 'lecteur']])->assertOk();

        $email = 'inscrit-' . uniqid() . '@example.mg';
        $this->postJson('/api/register', ['name' => 'Inscrit', 'email' => $email, 'password' => 'inscription-1', 'password_confirmation' => 'inscription-1'])
            ->assertOk();
        $this->assertSame('lecteur', User::where('email', $email)->value('role'));

        $this->putJson('/api/reglages', ['valeurs' => ['securite.inscription_ouverte' => false]])->assertOk();
        $this->postJson('/api/register', ['name' => 'Refusé', 'email' => 'refuse-' . uniqid() . '@example.mg', 'password' => 'inscription-1', 'password_confirmation' => 'inscription-1'])
            ->assertStatus(403);
    }

    public function test_parametres_valides_et_appliques(): void
    {
        Sanctum::actingAs($this->utilisateur(User::ROLE_ADMIN));

        $this->putJson('/api/reglages', ['valeurs' => ['systeme.compte_dotations' => '601000']])
            ->assertStatus(422)->assertJsonValidationErrors('systeme.compte_dotations');
        $this->putJson('/api/reglages', ['valeurs' => ['compta.tolerance_equilibre' => 0.05]])->assertOk();

        Parametres::oublier();
        $this->assertSame(0.05, \App\Services\Comptabilite\EcritureGuard::tolerance());
        $this->assertDatabaseHas('audit_comptable', ['action' => 'parametres.modification']);
    }
}
