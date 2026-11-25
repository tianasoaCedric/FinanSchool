<?php

namespace Tests\Feature\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lot « Saisie » — pièce complète en un envoi, modèles d'écriture, justificatif.
 * Base « finance_test » uniquement (voir IntegriteEcrituresTest).
 */
class SaisiePieceTest extends TestCase
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
        ExerciceComptable::where('Annee_fiscale', 2025)->update(['Statut' => 'OUVERT']);

        [$this->compteA, $this->compteB] = SousCompte::orderBy('Id_Sous_compte')->limit(2)->pluck('Id_Sous_compte')->all();
        $this->journal = Journal::create([
            'Code' => 'TSP',
            'Libelle' => 'Journal de test saisie',
            'Id_Type_Journal' => DB::table('type_journals')->value('Id_Type_Journal'),
            'Id_Sous_compte' => $this->compteB,
        ]);
        Sanctum::actingAs(User::factory()->create());
    }

    private function piece(array $lignes, string $action = 'brouillon', array $entete = []): array
    {
        return array_merge([
            'Date_mouvement' => '2025-06-20',
            'Id_Journal' => $this->journal->Id_Journal,
            'Libelle' => 'Paiement fournisseur',
            'Reference' => 'FAC-001',
            'action' => $action,
            'lignes' => $lignes,
        ], $entete);
    }

    private function equilibree(float $m = 250000): array
    {
        return [
            ['Id_Sous_compte' => $this->compteA, 'Debit' => $m, 'Credit' => 0],
            ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => $m],
        ];
    }

    public function test_creer_une_piece_complete_en_brouillon(): void
    {
        $r = $this->postJson('/api/pieces', $this->piece($this->equilibree()))->assertCreated();

        // Pas de numéro définitif avant la validation : référence provisoire BR-{id}
        $this->assertSame('BR-' . $r->json('piece.id'), $r->json('piece.numero'));
        $this->assertSame('brouillon', $r->json('piece.statut'));
        $this->assertCount(2, $r->json('piece.lignes'));
        // Libellé de la pièce repris sur les lignes sans libellé
        $this->assertSame('Paiement fournisseur', $r->json('piece.lignes.0.Libelle'));
    }

    public function test_enregistrer_et_valider_une_piece_equilibree(): void
    {
        $r = $this->postJson('/api/pieces', $this->piece($this->equilibree(), 'valider'))->assertCreated();
        $this->assertSame('valide', $r->json('piece.statut'));
        $this->assertSame('TSP2025-0001', $r->json('piece.numero'));
        $this->assertStringContainsString('TSP2025-0001', $r->json('message'));
        $this->assertSame(['valide'], LigneEcriture::where('Id_Mouvement_ecriture', $r->json('piece.id'))->pluck('statut')->unique()->values()->all());
    }

    public function test_valider_une_piece_desequilibree_n_enregistre_rien(): void
    {
        $avant = MouvementEcriture::count();
        $lignes = [
            ['Id_Sous_compte' => $this->compteA, 'Debit' => 1000, 'Credit' => 0],
            ['Id_Sous_compte' => $this->compteB, 'Debit' => 0, 'Credit' => 900],
        ];
        $this->postJson('/api/pieces', $this->piece($lignes, 'valider'))->assertStatus(422);
        $this->assertSame($avant, MouvementEcriture::count());
    }

    public function test_une_ligne_debit_et_credit_est_refusee(): void
    {
        $lignes = [['Id_Sous_compte' => $this->compteA, 'Debit' => 10, 'Credit' => 10]];
        $this->postJson('/api/pieces', $this->piece($lignes))->assertStatus(422);
    }

    public function test_modifier_un_brouillon_remplace_les_lignes_sans_le_numeroter(): void
    {
        $id = $this->postJson('/api/pieces', $this->piece($this->equilibree()))->json('piece.id');

        $r = $this->putJson("/api/pieces/{$id}", $this->piece($this->equilibree(400), 'brouillon', ['Libelle' => 'Corrigé']))->assertOk();

        $this->assertSame("BR-{$id}", $r->json('piece.numero'));
        $this->assertSame('Corrigé', $r->json('piece.Libelle'));
        $this->assertEquals(400, $r->json('piece.total_debit'));
        $this->assertSame(2, LigneEcriture::where('Id_Mouvement_ecriture', $id)->count());
    }

    public function test_une_piece_validee_ne_peut_plus_etre_modifiee_ni_supprimee(): void
    {
        $id = $this->postJson('/api/pieces', $this->piece($this->equilibree(), 'valider'))->json('piece.id');

        $this->putJson("/api/pieces/{$id}", $this->piece($this->equilibree(1)))->assertStatus(422);
        $this->deleteJson("/api/pieces/{$id}")->assertStatus(422);
        $this->getJson("/api/pieces/{$id}")->assertOk()->assertJsonPath('modifiable', false);
    }

    public function test_liste_des_pieces_filtree_par_statut(): void
    {
        $brouillon = $this->postJson('/api/pieces', $this->piece($this->equilibree()))->json('piece.numero');
        $validee = $this->postJson('/api/pieces', $this->piece($this->equilibree(), 'valider'))->json('piece.numero');

        $numerosBrouillon = array_column($this->getJson("/api/pieces?statut=brouillon&journal={$this->journal->Id_Journal}")->json('data'), 'numero');
        $numerosValides = array_column($this->getJson("/api/pieces?statut=valide&journal={$this->journal->Id_Journal}")->json('data'), 'numero');

        $this->assertSame([$brouillon], $numerosBrouillon);
        $this->assertSame([$validee], $numerosValides);
    }

    public function test_justificatif_joint_puis_telecharge(): void
    {
        Storage::fake('local');
        $donnees = $this->piece($this->equilibree());
        $donnees['lignes'] = json_encode($donnees['lignes']);
        $donnees['justificatif'] = UploadedFile::fake()->create('facture.pdf', 120, 'application/pdf');

        $id = $this->post('/api/pieces', $donnees, ['Accept' => 'application/json'])->assertCreated()->json('piece.id');

        $this->get("/api/pieces/{$id}/justificatif")->assertOk()->assertDownload('facture.pdf');
    }

    public function test_modeles_d_ecriture(): void
    {
        $id = $this->postJson('/api/modeles-ecriture', [
            'nom' => 'Paie test',
            'Id_Journal' => $this->journal->Id_Journal,
            'libelle' => 'Salaires du mois',
            'lignes' => [
                ['Id_Sous_compte' => $this->compteA, 'sens' => 'debit', 'montant' => 100],
                ['Id_Sous_compte' => $this->compteB, 'sens' => 'credit', 'montant' => null],
            ],
        ])->assertCreated()->json('modele.id');

        $this->assertContains('Paie test', array_column($this->getJson('/api/modeles-ecriture')->json(), 'nom'));
        $this->postJson('/api/modeles-ecriture', ['nom' => 'Paie test', 'lignes' => [
            ['Id_Sous_compte' => $this->compteA, 'sens' => 'debit'], ['Id_Sous_compte' => $this->compteB, 'sens' => 'credit'],
        ]])->assertStatus(422);
        $this->deleteJson("/api/modeles-ecriture/{$id}")->assertOk();
    }
}
