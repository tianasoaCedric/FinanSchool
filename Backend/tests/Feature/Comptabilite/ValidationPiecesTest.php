<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Écran de validation : file des brouillons avec motif, validation par lot.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class ValidationPiecesTest extends TestCase
{
    use DatabaseTransactions;

    private Journal $journal;
    private int $compteA;
    private int $compteB;

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
            'Code' => 'TVA9', 'Libelle' => 'Journal test validation',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function brouillon(float $credit = 1000, ?string $reference = 'FAC-V'): int
    {
        return $this->postJson('/api/pieces', [
            'Date_mouvement' => '2025-07-10', 'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => 'Pièce à valider', 'Reference' => $reference, 'action' => 'brouillon',
            'lignes' => [
                ['Id_Sous_compte' => $this->compteA, 'Debit' => 1000, 'Credit' => 0],
                ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => $credit],
            ],
        ])->assertCreated()->json('piece.id');
    }

    private function file(): array
    {
        return $this->getJson("/api/validation/pieces?journal={$this->journal->Id_Journal}")->assertOk()->json();
    }

    public function test_la_file_donne_le_motif_de_chaque_brouillon(): void
    {
        $prete = $this->brouillon();
        $desequilibree = $this->brouillon(900);
        $sansReference = $this->brouillon(1000, null);

        $r = $this->file();
        $pieces = collect($r['pieces'])->keyBy('id');

        $this->assertNull($pieces[$prete]['motif']);
        $this->assertStringContainsString('déséquilibrée', $pieces[$desequilibree]['motif']);
        $this->assertStringContainsString('Référence', $pieces[$sansReference]['motif']);
        $this->assertCount(2, $pieces[$prete]['lignes']);
        $this->assertSame(['total' => 3, 'pretes' => 1, 'a_corriger' => 2],
            array_intersect_key($r['synthese'], array_flip(['total', 'pretes', 'a_corriger'])));
    }

    public function test_validation_par_lot_valide_les_pretes_et_explique_les_autres(): void
    {
        $p1 = $this->brouillon();
        $p2 = $this->brouillon();
        $ko = $this->brouillon(900);

        $r = $this->postJson('/api/validation/valider', ['ids' => [$p1, $ko, $p2]])->assertOk()->json();

        $this->assertSame(['TVA92025-0001', 'TVA92025-0002'], array_column($r['validees'], 'numero'));
        $this->assertCount(1, $r['ignorees']);
        $this->assertSame("BR-{$ko}", $r['ignorees'][0]['numero']);
        $this->assertStringContainsString('déséquilibrée', $r['ignorees'][0]['motif']);
        $this->assertSame(0, LigneEcriture::where('Id_Mouvement_ecriture', $ko)->where('statut', 'valide')->count());

        // Les pièces validées sortent de la file
        $this->assertSame([$ko], array_column($this->file()['pieces'], 'id'));
        // Et la validation est tracée
        $this->assertSame(2, DB::table('audit_comptable')->where('action', 'piece.validation')->whereIn('objet_id', [$p1, $p2])->count());
    }

    public function test_la_validation_exige_une_connexion(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/validation/pieces')->assertUnauthorized();
        $this->postJson('/api/validation/valider', ['ids' => [1]])->assertUnauthorized();
    }
}
