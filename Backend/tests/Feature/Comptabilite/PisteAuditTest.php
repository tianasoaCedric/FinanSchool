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
 * Lot C — piste d'audit : chaque action comptable est tracée (auteur, date,
 * avant / après) et la trace ne peut être ni modifiée ni supprimée.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class PisteAuditTest extends TestCase
{
    use DatabaseTransactions;

    private Journal $journal;
    private int $compteA;
    private int $compteB;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDatabaseName() !== 'finance_test') {
            $this->markTestSkipped('Tests à lancer sur la base finance_test (DB_DATABASE=finance_test).');
        }
        ExerciceComptable::where('Annee_fiscale', '!=', 2025)->update(['Statut' => 'CLOTURE']);
        ExerciceComptable::where('Annee_fiscale', 2025)->update(['Statut' => 'OUVERT', 'periode_cloturee_au' => null]);

        [$this->compteA, $this->compteB] = SousCompte::orderBy('Id_Sous_compte')->limit(2)->pluck('Id_Sous_compte')->all();
        $this->journal = Journal::create([
            'Code' => 'TAU',
            'Libelle' => "Journal de test audit",
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
            'Id_Sous_compte' => $this->compteB,
        ]);
        $this->user = User::factory()->create(['name' => 'Comptable Test']);
        Sanctum::actingAs($this->user);
    }

    private function piece(string $action = 'brouillon', float $credit = 1000): array
    {
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => '2025-06-20',
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => 'Achat fournitures',
            'Reference' => 'FAC-AUDIT',
            'action' => $action,
            'lignes' => [
                ['Id_Sous_compte' => $this->compteA, 'Debit' => 1000, 'Credit' => 0],
                ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => $credit],
            ],
        ])->json('piece') ?? [];
    }

    private function traces(int $pieceId): array
    {
        return DB::table('audit_comptable')
            ->where('objet_type', 'piece')->where('objet_id', $pieceId)
            ->orderBy('id')->get()->all();
    }

    public function test_creation_et_validation_sont_tracees_avec_l_auteur(): void
    {
        $piece = $this->piece('valider');
        $traces = $this->traces($piece['id']);

        $this->assertSame(['piece.creation', 'piece.validation'], array_column($traces, 'action'));
        $this->assertSame('Comptable Test', $traces[1]->user_nom);
        $this->assertEquals($this->user->id, $traces[1]->user_id);
        $apres = json_decode($traces[1]->apres, true);
        $this->assertSame('TAU2025-0001', $apres['numero']);
        $this->assertSame('valide', $apres['statut']);
        $this->assertCount(2, $apres['lignes']);
    }

    public function test_une_modification_garde_l_etat_avant_et_apres(): void
    {
        $id = $this->piece()['id'];

        $this->putJson("/api/pieces/{$id}", [
            'Date_mouvement' => '2025-06-21',
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => 'Achat corrigé',
            'Reference' => 'FAC-AUDIT',
            'lignes' => [
                ['Id_Sous_compte' => $this->compteA, 'Debit' => 1500, 'Credit' => 0],
                ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => 1500],
            ],
        ])->assertOk();

        $modif = collect($this->traces($id))->firstWhere('action', 'piece.modification');
        $avant = json_decode($modif->avant, true);
        $apres = json_decode($modif->apres, true);
        $this->assertSame('Achat fournitures', $avant['libelle']);
        $this->assertEquals(1000, $avant['total_debit']);
        $this->assertSame('Achat corrigé', $apres['libelle']);
        $this->assertEquals(1500, $apres['total_debit']);
    }

    public function test_la_suppression_d_un_brouillon_est_tracee_avec_son_contenu(): void
    {
        $id = $this->piece()['id'];
        $this->deleteJson("/api/pieces/{$id}")->assertOk();

        $suppression = collect($this->traces($id))->firstWhere('action', 'piece.suppression');
        $this->assertNotNull($suppression);
        $this->assertSame('Achat fournitures', json_decode($suppression->avant, true)['libelle']);
        $this->assertNull($suppression->apres);
    }

    public function test_une_action_refusee_ne_laisse_pas_de_trace(): void
    {
        $avant = DB::table('audit_comptable')->count();

        // Pièce déséquilibrée : enregistrement + validation refusés en bloc
        $this->postJson('/api/pieces', [
            'Date_mouvement' => '2025-06-20', 'Id_Journal' => $this->journal->Id_Journal,
            'Reference' => 'FAC-AUDIT', 'action' => 'valider',
            'lignes' => [
                ['Id_Sous_compte' => $this->compteA, 'Debit' => 1000, 'Credit' => 0],
                ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => 900],
            ],
        ])->assertStatus(422);

        $this->assertSame($avant, DB::table('audit_comptable')->count());
    }

    public function test_la_contre_passation_est_tracee(): void
    {
        $id = $this->piece('valider')['id'];
        $extourne = $this->postJson("/api/pieces/{$id}/contrepasser", ['Date_mouvement' => '2025-06-25'])
            ->assertCreated()->json('piece.id');

        $actions = array_column($this->traces($extourne), 'action');
        $this->assertSame(['piece.contre_passation', 'piece.validation'], $actions);
    }

    public function test_le_plan_comptable_est_trace(): void
    {
        $compte = DB::table('comptes')->value('Id_Compte');
        $sc = $this->postJson('/api/sous-comptes', [
            'suffixe' => '977', 'Libelle' => 'Compte audit', 'Id_Compte' => $compte,
        ])->assertSuccessful()->json();
        $id = $sc['Id_Sous_compte'] ?? $sc['data']['Id_Sous_compte'];

        $this->deleteJson("/api/sous-comptes/{$id}")->assertOk();

        $actions = DB::table('audit_comptable')->where('objet_type', 'sous_compte')->where('objet_id', $id)
            ->orderBy('id')->pluck('action')->all();
        $this->assertSame(['plan.creation', 'plan.suppression'], $actions);
    }

    public function test_la_piste_d_audit_est_en_lecture_seule(): void
    {
        $this->piece();
        $id = DB::table('audit_comptable')->max('id');

        foreach ([
            fn () => DB::table('audit_comptable')->where('id', $id)->update(['user_nom' => 'Pirate']),
            fn () => DB::table('audit_comptable')->where('id', $id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation); // savepoint : la transaction du test reste utilisable
                $this->fail("La piste d'audit a accepté une modification.");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('lecture seule', $e->getMessage());
            }
        }
        $this->assertSame('Comptable Test', DB::table('audit_comptable')->where('id', $id)->value('user_nom'));
    }

    public function test_consultation_de_la_piste_avec_filtres(): void
    {
        $id = $this->piece('valider')['id'];

        $r = $this->getJson("/api/audit?objet_type=piece&objet_id={$id}")->assertOk();
        $this->assertSame(2, $r->json('total'));
        // Plus récent d'abord
        $this->assertSame('Validation de pièce', $r->json('data.0.action_libelle'));
        $this->assertSame('Comptable Test', $r->json('data.0.utilisateur'));

        $this->assertSame(1, $this->getJson("/api/audit?objet_id={$id}&action=piece.creation")->json('total'));
        $this->getJson('/api/audit/options')->assertOk()->assertJsonFragment(['code' => 'exercice.cloture']);
    }

    public function test_la_commande_admin_donne_et_retire_le_droit_et_le_trace(): void
    {
        $cible = User::factory()->create(['email' => 'futur.admin@test.mg']);
        $this->assertFalse((bool) $cible->fresh()->is_admin, "Personne n'est administrateur par défaut");

        $this->artisan('utilisateur:admin', ['email' => 'futur.admin@test.mg'])->assertSuccessful();
        $this->assertTrue($cible->fresh()->is_admin);

        $this->artisan('utilisateur:admin', ['email' => 'futur.admin@test.mg', '--retirer' => true])->assertSuccessful();
        $this->assertFalse($cible->fresh()->is_admin);

        $this->assertSame(2, DB::table('audit_comptable')
            ->where('action', 'utilisateur.droits')->where('objet_id', $cible->id)->count());
        $this->artisan('utilisateur:admin', ['email' => 'inconnu@test.mg'])->assertFailed();
    }

    public function test_le_droit_admin_ne_peut_pas_etre_donne_par_un_formulaire(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@test.mg', 'password' => 'secret123', 'is_admin' => true]);
        $this->assertFalse((bool) $user->fresh()->is_admin);
    }

    public function test_la_consultation_exige_une_connexion(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/audit')->assertUnauthorized();
    }
}
