<?php

namespace Tests\Feature\Comptabilite;

use App\Models\notifications\Evenement;
use App\Models\notifications\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Centre de notifications de l'interface comptable.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class CentreNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        Notification::query()->delete();
        Sanctum::actingAs(User::factory()->create());
    }

    private function notif(array $donnees, int $niveau = 1, string $statut = 'non_lu'): Notification
    {
        $type = DB::table('types_evenement')->value('id');
        $e = Evenement::create(['type_evenement_id' => $type, 'donnees_evenement' => $donnees]);
        $n = Notification::create([
            'evenement_id' => $e->id, 'niveau_urgence_id' => $niveau,
            'titre' => 'Titre', 'message' => 'Message',
        ]);
        if ($statut === 'lu') {
            $this->patchJson("/api/centre-notifications/{$n->id}/lue")->assertOk();
        }
        return $n;
    }

    private function compteurs(): array
    {
        return $this->getJson('/api/centre-notifications')->assertOk()->json('compteurs');
    }

    public function test_liste_categories_liens_et_compteurs(): void
    {
        $this->notif(['nom_fichier' => 'a.csv']);
        $this->notif(['date_cloture' => '2025-12-31', 'lien_redirection' => 'http://localhost:5173/ecritures-a-valider'], 3);
        $this->notif(['lien_redirection' => 'http://localhost:5173/non-affected'], 2, 'lu');

        $r = $this->getJson('/api/centre-notifications')->assertOk();
        $this->assertSame(['tous' => 3, 'non_lu' => 2, 'urgent_non_lu' => 1], $r->json('compteurs'));
        $liens = collect($r->json('data'))->pluck('lien', 'categorie')->all();
        $this->assertSame(['analyse' => '/non-affected', 'cloture' => '/validation', 'import' => '/import'], collect($liens)->sortKeys()->all());

        $this->getJson('/api/centre-notifications?categorie=import')->assertJsonPath('total', 1);
        $this->getJson('/api/centre-notifications?niveau=urgent')->assertJsonPath('total', 1);
        $this->getJson('/api/centre-notifications?statut=lu')->assertJsonPath('total', 1);
    }

    public function test_actions_lue_non_lue_tout_lire_et_suppression(): void
    {
        $a = $this->notif(['nom_fichier' => 'a.csv']);
        $b = $this->notif(['nom_fichier' => 'b.csv']);

        $this->patchJson("/api/centre-notifications/{$a->id}/lue")->assertOk();
        $this->assertSame(1, $this->compteurs()['non_lu']);
        $this->patchJson("/api/centre-notifications/{$a->id}/non-lue")->assertOk();
        $this->assertSame(2, $this->compteurs()['non_lu']);

        $this->postJson('/api/centre-notifications/tout-lire')->assertOk();
        $this->assertSame(0, $this->compteurs()['non_lu']);

        $this->deleteJson("/api/centre-notifications/{$b->id}")->assertOk();
        $this->deleteJson('/api/centre-notifications/lues')->assertOk();
        $this->assertSame(0, $this->compteurs()['tous']);
        // Masquées pour cet utilisateur seulement : les notifications existent toujours
        $this->assertSame(2, Notification::count());
    }

    public function test_etat_propre_a_chaque_utilisateur(): void
    {
        $a = $this->notif(['nom_fichier' => 'a.csv']);
        $b = $this->notif(['nom_fichier' => 'b.csv']);
        $this->patchJson("/api/centre-notifications/{$a->id}/lue")->assertOk();
        $this->deleteJson("/api/centre-notifications/{$b->id}")->assertOk();
        $this->assertSame(['tous' => 1, 'non_lu' => 0, 'urgent_non_lu' => 0], $this->compteurs());

        Sanctum::actingAs(User::factory()->create());
        $this->assertSame(['tous' => 2, 'non_lu' => 2, 'urgent_non_lu' => 0], $this->compteurs());
    }

    public function test_un_lecteur_peut_gerer_ses_notifications(): void
    {
        $u = User::factory()->create();
        $u->definirRole(User::ROLE_LECTEUR)->save();
        Sanctum::actingAs($u);
        $n = $this->notif(['nom_fichier' => 'a.csv']);
        $this->patchJson("/api/centre-notifications/{$n->id}/lue")->assertOk();
    }
}
